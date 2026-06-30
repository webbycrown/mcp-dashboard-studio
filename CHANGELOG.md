# Changelog

All notable changes to `mcp-dashboard-studio` will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [Unreleased]

### Added
- **RFC 7591 Dynamic Client Registration** (`POST /oauth/register`) — ChatGPT, Cursor, Windsurf and other AI tools now self-register automatically with no manual setup
- **RFC 8414 OAuth Server Metadata** (`GET /.well-known/oauth-authorization-server`) — AI clients auto-discover all OAuth endpoints
- **RFC 9728 Protected Resource Metadata** (`GET /.well-known/oauth-protected-resource`) — resource server advertises its capabilities
- **Built-in login page** (`GET /login`) — package ships its own glassmorphism login UI for the OAuth consent flow
- **Built-in consent screen** (`passport/authorize.blade.php`) — styled OAuth authorization screen
- **Public PKCE client support** — supports `--public` clients (no client_secret), required by Claude, ChatGPT, and the MCP OAuth 2.1 spec
- **Domain allowlist for dynamic registration** — `oauth.allowed_redirect_domains` config key for restricting which AI tool domains may auto-register
- **Rate limiting on `/oauth/register`** — 10 registrations per minute per IP to prevent abuse
- **HTTPS enforcement** — dynamic registration rejects HTTP redirect URIs in non-local environments
- **`web` middleware on browser-facing routes** — fixes `Session store not set on request` error that occurred when routes were loaded without session middleware

### Fixed
- **`invalid_client` on token exchange** — root cause: confidential client was created for Claude which uses public PKCE-only flow; recreated as `--public` client
- **`Session store not set on request`** — `loadRoutesFrom()` in Laravel packages does NOT apply `web` middleware automatically; wrapped browser routes explicitly
- **`$errors` undefined in login.blade.php** — changed to `isset($errors) && $errors->any()` to safely guard against null on fresh page loads
- **`registration_endpoint: /oauth/clients`** — changed to `/oauth/register` (Passport v13 removed the public client management API)
- **Post-login redirect sends to broken `/oauth/authorize`** — now uses `url.intended` stored by Passport directly, falls back to `/` if no OAuth flow was in progress
- **DB typo `locakhost`** — fixed `DB_HOST=localhost` in environment configuration

### Changed
- All browser-facing routes now wrapped in `Route::middleware('web')` group
- Discovery `registration_endpoint` updated from `/oauth/clients` → `/oauth/register`
- `composer.json` updated with proper description, keywords, homepage, and suggest section

---

## [0.1.0] — 2026-06-23

### Added
- Initial release
- MCP server integration via `laravel/mcp`
- Auto schema discovery and dashboard generation
- Chart.js interactive dashboards (KPI cards, bar/line/pie charts, data tables)
- `VerifyMcpToken` middleware supporting OAuth Bearer tokens and static `MCP_SECRET_TOKEN`
- Dashboard persistence via `mcp_dashboard_definitions` table
- Dashboard viewer at `/dashboard-studio/{slug}`
