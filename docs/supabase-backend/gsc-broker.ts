// PublishRank — Google Search Console sign-in broker (Supabase Edge Function).
//
// Lets WordPress sites connect Search Console with one click ("Sign in with Google") without each
// site owner creating a Google OAuth client. The Google client id/secret and every refresh token
// stay here; a site only ever receives a revocable connection id + secret, and short-lived access
// tokens. Deployed with verify_jwt = false because browsers are redirected here by Google.
//
// Secrets required:  GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET   (TOKEN_ENC_KEY optional)
// Endpoints (all under /functions/v1/gsc-broker):
//   GET  /health                       -> { ok, configured }
//   GET  /start?state&challenge&return -> 302 to Google
//   GET  /callback?code&state          -> 302 back to the WordPress site with ?ticket=
//   POST /exchange {ticket, verifier}  -> { connection_id, connection_secret, email, sites[] }
//   POST /token {connection_id, connection_secret} -> { access_token, expires_in, email }
//   POST /disconnect {connection_id, connection_secret} -> { ok }
import { createClient } from "npm:@supabase/supabase-js@2";

const SUPABASE_URL = Deno.env.get("SUPABASE_URL")!;
const SERVICE_KEY = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!;
const GOOGLE_ID = Deno.env.get("GOOGLE_CLIENT_ID") ?? "";
const GOOGLE_SECRET = Deno.env.get("GOOGLE_CLIENT_SECRET") ?? "";
const ENC_SECRET = Deno.env.get("TOKEN_ENC_KEY") || GOOGLE_SECRET;
const SCOPE_GSC = "https://www.googleapis.com/auth/webmasters.readonly";
const BASE = `${SUPABASE_URL}/functions/v1/gsc-broker`;
const REDIRECT_URI = `${BASE}/callback`;

const db = createClient(SUPABASE_URL, SERVICE_KEY, { auth: { persistSession: false } });
const enc = new TextEncoder();

const b64u = (buf: ArrayBuffer | Uint8Array) =>
  btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
const fromB64u = (s: string) => Uint8Array.from(atob(s.replace(/-/g, "+").replace(/_/g, "/")), (c) => c.charCodeAt(0));
const rand = (n = 32) => b64u(crypto.getRandomValues(new Uint8Array(n)));
const sha256 = async (s: string) => b64u(await crypto.subtle.digest("SHA-256", enc.encode(s)));

function json(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json", "Cache-Control": "no-store", "Access-Control-Allow-Origin": "*" },
  });
}
const redirect = (url: string) => new Response(null, { status: 302, headers: { Location: url, "Cache-Control": "no-store" } });

function timingSafeEqual(a: string, b: string) {
  if (a.length !== b.length) return false;
  let r = 0;
  for (let i = 0; i < a.length; i++) r |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return r === 0;
}

// ---- encryption of refresh tokens at rest (AES-256-GCM) ------------------------------------
async function aesKey() {
  const base = await crypto.subtle.importKey("raw", enc.encode(ENC_SECRET), { name: "HMAC", hash: "SHA-256" }, false, ["sign"]);
  const raw = await crypto.subtle.sign("HMAC", base, enc.encode("publishrank-gsc-refresh-token-v1"));
  return crypto.subtle.importKey("raw", raw, "AES-GCM", false, ["encrypt", "decrypt"]);
}
async function encrypt(plain: string) {
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const ct = new Uint8Array(await crypto.subtle.encrypt({ name: "AES-GCM", iv }, await aesKey(), enc.encode(plain)));
  const out = new Uint8Array(iv.length + ct.length);
  out.set(iv); out.set(ct, iv.length);
  return b64u(out);
}
async function decrypt(token: string) {
  const raw = fromB64u(token);
  const pt = await crypto.subtle.decrypt({ name: "AES-GCM", iv: raw.slice(0, 12) }, await aesKey(), raw.slice(12));
  return new TextDecoder().decode(pt);
}

// ---- return-URL validation -----------------------------------------------------------------
function parseReturn(raw: string): URL | null {
  try {
    const u = new URL(raw);
    if (!["https:", "http:"].includes(u.protocol) || u.username || u.password) return null;
    if (!u.pathname.endsWith("/wp-admin/admin-post.php")) return null;
    if (u.searchParams.get("action") !== "publishrank_gsc_callback") return null;
    const h = u.hostname.toLowerCase();
    if (h === "localhost" || h.endsWith(".local") || /^\d+\.\d+\.\d+\.\d+$/.test(h) || h.includes(":")) return null;
    return u;
  } catch (_) {
    return null;
  }
}
function withParams(base: string, params: Record<string, string>) {
  const u = new URL(base);
  for (const [k, v] of Object.entries(params)) u.searchParams.set(k, v);
  return u.toString();
}

// Does a Search Console property belong to the host that is asking to connect?
function propertyMatchesHost(siteUrl: string, host: string) {
  host = host.toLowerCase();
  let ph = "";
  if (siteUrl.startsWith("sc-domain:")) ph = siteUrl.slice(10).toLowerCase();
  else { try { ph = new URL(siteUrl).hostname.toLowerCase(); } catch (_) { return false; } }
  return host === ph || host.endsWith("." + ph) || ph.endsWith("." + host);
}

// ---- Google helpers ------------------------------------------------------------------------
async function googleToken(params: Record<string, string>) {
  const res = await fetch("https://oauth2.googleapis.com/token", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ client_id: GOOGLE_ID, client_secret: GOOGLE_SECRET, ...params }),
  });
  const data = await res.json().catch(() => ({}));
  return { ok: res.ok, status: res.status, data };
}
async function listSites(accessToken: string) {
  const res = await fetch("https://www.googleapis.com/webmasters/v3/sites", { headers: { Authorization: `Bearer ${accessToken}` } });
  if (!res.ok) return [] as { siteUrl: string; permissionLevel: string }[];
  const data = await res.json().catch(() => ({}));
  return ((data.siteEntry ?? []) as { siteUrl: string; permissionLevel: string }[]).filter((s) =>
    ["siteOwner", "siteFullUser", "siteRestrictedUser"].includes(s.permissionLevel)
  );
}
function decodeIdToken(idToken?: string): { sub?: string; email?: string } {
  try {
    const payload = idToken!.split(".")[1];
    return JSON.parse(new TextDecoder().decode(fromB64u(payload)));
  } catch (_) {
    return {};
  }
}

async function cleanup() {
  const now = new Date().toISOString();
  await db.from("pr_gsc_oauth_states").delete().lt("expires_at", now);
  await db.from("pr_gsc_tickets").delete().lt("expires_at", now);
  // connections whose ticket was never exchanged (browser closed, failed handshake) are orphans
  await db.from("pr_gsc_connections").delete().is("last_used_at", null).lt("created_at", new Date(Date.now() - 3600_000).toISOString());
}

// ---- handlers ------------------------------------------------------------------------------
async function start(url: URL) {
  if (!GOOGLE_ID || !GOOGLE_SECRET) return json({ error: "not_configured" }, 503);
  const state = url.searchParams.get("state") ?? "";
  const challenge = url.searchParams.get("challenge") ?? "";
  const ret = parseReturn(url.searchParams.get("return") ?? "");
  if (!ret || !/^[a-f0-9]{32,64}$/.test(state) || !/^[A-Za-z0-9_-]{43}$/.test(challenge)) return json({ error: "bad_request" }, 400);
  cleanup().catch(() => {});
  const verifier = rand(48);
  const { error } = await db.from("pr_gsc_oauth_states").insert({
    state, site_url: `${ret.protocol}//${ret.host}`, return_url: ret.toString(), challenge, google_verifier: verifier,
  });
  if (error) return json({ error: "state_failed" }, 500);
  const q = new URLSearchParams({
    client_id: GOOGLE_ID,
    redirect_uri: REDIRECT_URI,
    response_type: "code",
    scope: `openid email ${SCOPE_GSC}`,
    access_type: "offline",
    prompt: "consent select_account",
    include_granted_scopes: "false",
    state,
    code_challenge: await sha256(verifier),
    code_challenge_method: "S256",
  });
  return redirect(`https://accounts.google.com/o/oauth2/v2/auth?${q}`);
}

async function callback(url: URL) {
  const state = url.searchParams.get("state") ?? "";
  const { data: row } = await db.from("pr_gsc_oauth_states").select("*").eq("state", state).maybeSingle();
  if (!row) return json({ error: "unknown_state" }, 400);
  await db.from("pr_gsc_oauth_states").delete().eq("state", state);
  const back = (params: Record<string, string>) => redirect(withParams(row.return_url, { state, ...params }));
  if (new Date(row.expires_at).getTime() < Date.now()) return back({ pr_error: "expired" });
  if (url.searchParams.get("error")) return back({ pr_error: "denied" });
  const code = url.searchParams.get("code");
  if (!code) return back({ pr_error: "no_code" });

  const t = await googleToken({ code, redirect_uri: REDIRECT_URI, grant_type: "authorization_code", code_verifier: row.google_verifier });
  if (!t.ok || !t.data.access_token) return back({ pr_error: "exchange_failed" });
  if (!t.data.refresh_token) return back({ pr_error: "no_refresh_token" });
  if (!String(t.data.scope ?? "").includes(SCOPE_GSC)) return back({ pr_error: "scope_missing" });

  const sites = await listSites(t.data.access_token);
  const host = new URL(row.return_url).hostname;
  // Only hand a connection to a site whose domain this Google account really manages.
  if (!sites.some((s) => propertyMatchesHost(s.siteUrl, host))) {
    fetch(`https://oauth2.googleapis.com/revoke?token=${encodeURIComponent(t.data.refresh_token)}`, { method: "POST" }).catch(() => {});
    return back({ pr_error: "no_property" });
  }

  const idc = decodeIdToken(t.data.id_token);
  const secret = rand(32);
  const { data: conn, error } = await db.from("pr_gsc_connections").insert({
    secret_hash: await sha256(secret), google_sub: idc.sub ?? null, google_email: idc.email ?? null, site_host: host,
    refresh_token_enc: await encrypt(t.data.refresh_token), scopes: String(t.data.scope ?? ""),
  }).select("id").single();
  if (error || !conn) return back({ pr_error: "store_failed" });
  // The connection secret is sealed with the one-time ticket (which only travels in the browser redirect),
  // so the database alone can never reveal it.
  const ticket = rand(32);
  const { error: terr } = await db.from("pr_gsc_tickets").insert({
    ticket_hash: await sha256(ticket), connection_id: conn.id, challenge: row.challenge, sealed_secret: await encryptWith(ticket, secret),
  });
  if (terr) return back({ pr_error: "store_failed" });
  return back({ ticket });
}

// Small helper: encrypt `plain` with a key derived from `passphrase` (used to hand the connection secret over once).
async function encryptWith(passphrase: string, plain: string) {
  const base = await crypto.subtle.importKey("raw", enc.encode(passphrase), "HKDF", false, ["deriveKey"]);
  const key = await crypto.subtle.deriveKey(
    { name: "HKDF", hash: "SHA-256", salt: enc.encode("pr-ticket"), info: enc.encode("v1") }, base, { name: "AES-GCM", length: 256 }, false, ["encrypt"],
  );
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const ct = new Uint8Array(await crypto.subtle.encrypt({ name: "AES-GCM", iv }, key, enc.encode(plain)));
  const out = new Uint8Array(iv.length + ct.length); out.set(iv); out.set(ct, iv.length);
  return b64u(out);
}
async function decryptWith(passphrase: string, token: string) {
  const base = await crypto.subtle.importKey("raw", enc.encode(passphrase), "HKDF", false, ["deriveKey"]);
  const key = await crypto.subtle.deriveKey(
    { name: "HKDF", hash: "SHA-256", salt: enc.encode("pr-ticket"), info: enc.encode("v1") }, base, { name: "AES-GCM", length: 256 }, false, ["decrypt"],
  );
  const raw = fromB64u(token);
  return new TextDecoder().decode(await crypto.subtle.decrypt({ name: "AES-GCM", iv: raw.slice(0, 12) }, key, raw.slice(12)));
}

async function exchange(body: Record<string, string>) {
  const ticket = String(body.ticket ?? ""), verifier = String(body.verifier ?? "");
  if (!ticket || !verifier) return json({ error: "bad_request" }, 400);
  const hash = await sha256(ticket);
  const { data: t } = await db.from("pr_gsc_tickets").select("*").eq("ticket_hash", hash).maybeSingle();
  if (!t || t.used_at || new Date(t.expires_at).getTime() < Date.now()) return json({ error: "invalid_ticket" }, 400);
  // single use: claim atomically
  const { data: claimed } = await db.from("pr_gsc_tickets").update({ used_at: new Date().toISOString() }).eq("ticket_hash", hash).is("used_at", null).select("ticket_hash");
  if (!claimed || claimed.length !== 1) return json({ error: "invalid_ticket" }, 400);
  if (!timingSafeEqual(await sha256(verifier), String(t.challenge)) || !t.sealed_secret) return json({ error: "bad_verifier" }, 400);
  let secret = "";
  try { secret = await decryptWith(ticket, t.sealed_secret); } catch (_) { return json({ error: "invalid_ticket" }, 400); }
  const { data: conn } = await db.from("pr_gsc_connections").select("id, google_email").eq("id", t.connection_id).maybeSingle();
  if (!conn) return json({ error: "invalid_ticket" }, 400);
  await db.from("pr_gsc_tickets").delete().eq("ticket_hash", hash);
  const at = await accessTokenFor(conn.id, secret);
  const sites = "access_token" in at ? await listSites(at.access_token) : [];
  return json({ connection_id: conn.id, connection_secret: secret, email: conn.google_email ?? "", sites, access_token: "access_token" in at ? at.access_token : undefined, expires_in: "access_token" in at ? at.expires_in : undefined });
}

async function accessTokenFor(id: string, secret: string): Promise<{ access_token: string; expires_in: number; email: string } | { error: string; status: number }> {
  if (!/^[0-9a-f-]{36}$/.test(id) || !secret) return { error: "bad_request", status: 400 };
  const { data: c } = await db.from("pr_gsc_connections").select("*").eq("id", id).maybeSingle();
  if (!c || c.revoked_at || !timingSafeEqual(await sha256(secret), c.secret_hash)) return { error: "invalid_connection", status: 401 };
  let refresh = "";
  try { refresh = await decrypt(c.refresh_token_enc); } catch (_) { return { error: "reauth", status: 401 }; }
  const t = await googleToken({ refresh_token: refresh, grant_type: "refresh_token" });
  if (!t.ok || !t.data.access_token) {
    if (t.data?.error === "invalid_grant") await db.from("pr_gsc_connections").update({ revoked_at: new Date().toISOString() }).eq("id", id);
    return { error: "reauth", status: 401 };
  }
  db.from("pr_gsc_connections").update({ last_used_at: new Date().toISOString() }).eq("id", id).then(() => {});
  return { access_token: t.data.access_token, expires_in: Number(t.data.expires_in ?? 3600), email: c.google_email ?? "" };
}

async function token(body: Record<string, string>) {
  const r = await accessTokenFor(String(body.connection_id ?? ""), String(body.connection_secret ?? ""));
  return "error" in r ? json({ error: r.error }, r.status) : json(r);
}

async function disconnect(body: Record<string, string>) {
  const id = String(body.connection_id ?? ""), secret = String(body.connection_secret ?? "");
  if (!/^[0-9a-f-]{36}$/.test(id) || !secret) return json({ error: "bad_request" }, 400);
  const { data: c } = await db.from("pr_gsc_connections").select("*").eq("id", id).maybeSingle();
  if (!c || !timingSafeEqual(await sha256(secret), c.secret_hash)) return json({ error: "invalid_connection" }, 401);
  try {
    const refresh = await decrypt(c.refresh_token_enc);
    await fetch(`https://oauth2.googleapis.com/revoke?token=${encodeURIComponent(refresh)}`, { method: "POST" });
  } catch (_) { /* already unusable */ }
  await db.from("pr_gsc_connections").delete().eq("id", id);
  return json({ ok: true });
}

Deno.serve(async (req: Request) => {
  if (req.method === "OPTIONS") {
    return new Response(null, { status: 204, headers: { "Access-Control-Allow-Origin": "*", "Access-Control-Allow-Headers": "content-type, apikey, authorization", "Access-Control-Allow-Methods": "GET, POST, OPTIONS" } });
  }
  const url = new URL(req.url);
  const route = url.pathname.replace(/^.*\/gsc-broker/, "") || "/";
  try {
    if (req.method === "GET" && route === "/health") return json({ ok: true, configured: Boolean(GOOGLE_ID && GOOGLE_SECRET), version: 1 });
    if (req.method === "GET" && route === "/start") return await start(url);
    if (req.method === "GET" && route === "/callback") return await callback(url);
    if (req.method === "POST") {
      const body = await req.json().catch(() => ({}));
      if (route === "/exchange") return await exchange(body);
      if (route === "/token") return await token(body);
      if (route === "/disconnect") return await disconnect(body);
    }
    return json({ error: "not_found" }, 404);
  } catch (e) {
    console.error("gsc-broker error", e instanceof Error ? e.message : e);
    return json({ error: "server_error" }, 500);
  }
});
