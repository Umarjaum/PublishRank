-- Already applied to project pgduryvupdltsqzqoiav (migrations publishrank_gsc_broker_tables + publishrank_gsc_tickets_sealed_secret).
create table if not exists public.pr_gsc_oauth_states (
  state text primary key, site_url text not null, return_url text not null, challenge text not null, google_verifier text not null,
  created_at timestamptz not null default now(), expires_at timestamptz not null default (now() + interval '10 minutes'));
create table if not exists public.pr_gsc_connections (
  id uuid primary key default gen_random_uuid(), secret_hash text not null, google_sub text, google_email text, site_host text not null,
  refresh_token_enc text not null, scopes text, created_at timestamptz not null default now(), last_used_at timestamptz, revoked_at timestamptz);
create table if not exists public.pr_gsc_tickets (
  ticket_hash text primary key, connection_id uuid not null references public.pr_gsc_connections(id) on delete cascade, challenge text not null,
  created_at timestamptz not null default now(), expires_at timestamptz not null default (now() + interval '5 minutes'), used_at timestamptz, sealed_secret text);
create index if not exists pr_gsc_oauth_states_expires_idx on public.pr_gsc_oauth_states (expires_at);
create index if not exists pr_gsc_tickets_expires_idx on public.pr_gsc_tickets (expires_at);
create index if not exists pr_gsc_tickets_connection_idx on public.pr_gsc_tickets (connection_id);
create index if not exists pr_gsc_connections_host_idx on public.pr_gsc_connections (site_host);
alter table public.pr_gsc_oauth_states enable row level security;
alter table public.pr_gsc_connections  enable row level security;
alter table public.pr_gsc_tickets      enable row level security;
revoke all on public.pr_gsc_oauth_states, public.pr_gsc_connections, public.pr_gsc_tickets from anon, authenticated;
