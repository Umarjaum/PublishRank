# PublishRank backend: one-click Google Search Console sign-in

Project: `pgduryvupdltsqzqoiav` (https://pgduryvupdltsqzqoiav.supabase.co)

Already done for you: tables `pr_gsc_*` (RLS on, service-role only) and Edge Function `gsc-broker` (version 2, `verify_jwt = false`).
Two things only you can do, because they need your Google account:

## 1. Create the Google OAuth client (about 5 minutes)
Google Cloud Console → pick/create a project (call it "PublishRank"):
1. **APIs & Services → Library**: enable **Google Search Console API**.
2. **OAuth consent screen** (Google Auth Platform → Branding/Audience): App name `PublishRank`, support email, user type **External**.
   Data access → add scopes `openid`, `email`, and `https://www.googleapis.com/auth/webmasters.readonly`.
3. **Clients → Create client → Web application**. Authorized redirect URIs (exact):
   - `https://pgduryvupdltsqzqoiav.supabase.co/functions/v1/gsc-broker/callback`   (Search Console sign-in)
   - `https://pgduryvupdltsqzqoiav.supabase.co/auth/v1/callback`                  (plugin account sign-in with Google, Supabase Auth)
4. Copy the **Client ID** and **Client secret**.
5. While the consent screen is "Testing", only listed test users can sign in. To let any publisher connect, **Publish app**.
   `webmasters.readonly` is a *sensitive* scope, so Google requires app verification (privacy policy URL, homepage, a short demo video) before the "unverified app" warning goes away and the 100-test-user cap is lifted.

## 2. Add the secrets (Supabase dashboard → Edge Functions → Secrets)
| Name | Value |
|---|---|
| `GOOGLE_CLIENT_ID` | the Client ID |
| `GOOGLE_CLIENT_SECRET` | the Client secret |
| `TOKEN_ENC_KEY` (optional) | any long random string; encrypts refresh tokens. Defaults to a key derived from the client secret. |

## 3. Enable Google for plugin account sign-in (optional, same client)
Supabase → Authentication → Sign In / Providers → **Google** → enable, paste the same Client ID/secret.
Authentication → URL Configuration → add each site's callback to Redirect URLs, e.g. `https://example.com/wp-admin/admin-post.php?action=publishrank_supabase_callback`.

## Check it works
`GET https://pgduryvupdltsqzqoiav.supabase.co/functions/v1/gsc-broker/health` → `{"ok":true,"configured":true,...}`.
The Search Console screen's "Sign in with Google" button stays disabled until `configured` is true.

## Security model
- Google client secret and refresh tokens never leave Supabase; refresh tokens are AES-256-GCM encrypted.
- A site receives only a revocable connection id + secret (stored hashed server-side) and 1-hour access tokens.
- A connection is issued only if the signed-in Google account has a Search Console property matching the requesting site's domain.
- One-time ticket + PKCE between the browser redirect and the site's server-to-server exchange; states expire in 10 min, tickets in 5.
- Return URLs must be `…/wp-admin/admin-post.php?action=publishrank_gsc_callback` on a public hostname.
