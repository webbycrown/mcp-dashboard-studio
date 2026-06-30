# MCP dashboard-studio Dashboard — Technical Documentation

> **Package:** `webbycrown/mcp-dashboard-studio`  
> **Namespace:** `Webbycrown\McpDashboardStudio`  
> **Version:** 0.1.0 (Unreleased features documented in CHANGELOG)

This document is a deep technical reference for developers integrating, extending, or operating the MCP dashboard-studio Dashboard Laravel package.

---

## Table of Contents

1. [Overview](#1-overview)
2. [Architecture](#2-architecture)
3. [Package Structure](#3-package-structure)
4. [MCP Server & Tools](#4-mcp-server--tools)
5. [Dashboard Generation Pipeline](#5-dashboard-generation-pipeline)
6. [Database Layer](#6-database-layer)
7. [Authentication & Authorization](#7-authentication--authorization)
8. [Routes & HTTP API](#8-routes--http-api)
9. [Dashboard Manager UI](#9-dashboard-manager-ui)
10. [Configuration Reference](#10-configuration-reference)
11. [Security Model](#11-security-model)
12. [Publishing & Customization](#12-publishing--customization)
13. [Testing](#13-testing)
14. [Extension Points](#14-extension-points)
15. [Troubleshooting](#15-troubleshooting)

---

## 1. Overview

MCP dashboard-studio Dashboard is a Laravel package that exposes your application's database as an **MCP (Model Context Protocol) server**. AI assistants (Claude, ChatGPT, Cursor, Windsurf, etc.) connect to the server, send natural-language prompts, and receive **live, interactive analytics dashboards** backed by real database queries.

### Core capabilities

| Capability | Description |
|---|---|
| **MCP server** | Registered via `laravel/mcp` at a configurable path (default: `/mcp/generate-dashboard`) |
| **Schema introspection** | Automatically discovers tables, columns, foreign keys, and relationships |
| **Dashboard generation** | Builds KPIs, charts (bar/line/pie/doughnut), data tables, and filters from live data |
| **Persistence** | Stores generated dashboards in `mcp_dashboard_definitions` with a shareable slug URL |
| **OAuth 2.1 + PKCE** | RFC 7591 dynamic client registration, RFC 8414/9728 discovery via Laravel Passport |
| **Static token auth** | Alternative auth via `MCP_SECRET_TOKEN` for Postman/cURL |
| **Manager UI** | Web CRUD panel for dashboards, access control, audit logs, import/export, trash |
| **Access control** | Public/private dashboards with system-user and custom-user (tokenized invite) access |

### Requirements

| Dependency | Version |
|---|---|
| PHP | ≥ 8.2 |
| Laravel | 11.x, 12.x, or 13.x |
| laravel/passport | ≥ 13.x (required for OAuth) |
| laravel/mcp | ≥ 0.1 |

---

## 2. Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         AI Client (Claude, Cursor, etc.)                │
└───────────────────────────────┬─────────────────────────────────────────┘
                                │ MCP JSON-RPC over HTTP
                                │ OAuth Bearer OR MCP_SECRET_TOKEN
                                ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  VerifyMcpToken Middleware                                              │
│  ├── Passport OAuth token (JWT or opaque)                               │
│  └── Static MCP_SECRET_TOKEN                                          │
└───────────────────────────────┬─────────────────────────────────────────┘
                                ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  DashboardServer (laravel/mcp)                                          │
│  ├── dashboard-tool          → full config + persist + live_url         │
│  ├── dashboard-analysis-tool → NLP prompt analysis                      │
│  ├── dashboard-spec-tool     → structured spec + persist                │
│  ├── dashboard-html-tool     → HTML/CSS/JS + live_url + raw_data        │
│  ├── dashboard-export-tool   → JSON export                              │
│  └── dashboard-blade-create  → Blade file in host project                 │
└───────────────────────────────┬─────────────────────────────────────────┘
                                ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  DashboardGenerator (orchestrator)                                      │
│  Prompt → Planner → SpecBuilder → DataSourceResolver → Validator        │
│         → LayoutEngine → HtmlRenderer                                   │
└───────────────────────────────┬─────────────────────────────────────────┘
                                ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  Database (schema introspection + live queries)                         │
│  SchemaAnalyzer, MetricDiscoveryService, DynamicQueryBuilder/Executor   │
└───────────────────────────────┬─────────────────────────────────────────┘
                                ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  DashboardStorageService → mcp_dashboard_definitions                    │
└───────────────────────────────┬─────────────────────────────────────────┘
                                ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  DashboardStudioController → /dashboard-studio/{slug}                     │
│  CheckDashboardAccess (public / private / custom user)                  │
└─────────────────────────────────────────────────────────────────────────┘
```

### Boot sequence

The `McpDashboardStudioServiceProvider` handles:

1. **register()** — merges config, binds `NlpClientInterface`, registers MCP tool singletons
2. **boot()** — (when `MCP_ENABLED=true`):
   - Loads routes from `Routes/ai.php` and `Routes/web.php`
   - Registers middleware aliases: `mcp.dashboard.access`, `mcp.manager.access`
   - Loads migrations
   - Configures Passport views, scopes, and token TTL
   - Loads views under namespace `mcp-dashboard-studio`
   - Registers publishable assets (config, migrations, assets, views)

---

## 3. Package Structure

```
src/
├── Config/mcp-dashboard-studio.php       # Default configuration
├── Database/migrations/                 # Package migrations (auto-loaded)
├── Http/
│   ├── Controllers/                   # Web, API, OAuth, Manager controllers
│   ├── Middleware/                    # VerifyMcpToken, CheckDashboardAccess, RequireManagerAccess
│   └── Requests/                      # Form request validation
├── Mcp/
│   ├── Servers/DashboardServer.php    # MCP server entry point
│   ├── Tools/                         # Six MCP tool classes
│   ├── Services/                      # Generation, rendering, query, NLP pipeline
│   ├── DTO/                           # Data transfer objects
│   └── DataProviders/                 # Schema/database data providers
├── Models/                            # Eloquent models
├── Providers/McpDashboardStudioServiceProvider.php
├── Resources/
│   ├── assets/                        # CSS/JS for dashboards and manager
│   └── views/                         # Blade templates (mcp-dashboard-studio:: namespace)
├── Routes/
│   ├── ai.php                         # MCP endpoint registration
│   └── web.php                        # All HTTP routes (dashboard, manager, OAuth)
├── Services/AuditLogger.php
└── Support/RoutePaths.php             # Configurable route path resolver
```

---

## 4. MCP Server & Tools

### Server registration

The MCP endpoint is registered in `Routes/ai.php`:

```php
Mcp::web(RoutePaths::mcpPath(), DashboardServer::class)
    ->middleware(VerifyMcpToken::class);
```

Default path: `/mcp/generate-dashboard` (configurable via `MCP_PATH` and `MCP_ROUTE_PREFIX`).

### DashboardServer

- **Name:** `Dashboard Generator MCP`
- **Version:** `1.0.0`
- **Instructions:** Loaded from `config('mcp-dashboard-studio.instructions')` or built-in defaults
- **Tools:** Enabled individually via `config('mcp-dashboard-studio.tools')`

### Tool reference

| Config key | Tool class | Purpose |
|---|---|---|
| `dashboard-tool` | `DashboardTool` | Primary tool — generates full dashboard config, persists to DB, returns `live_url`, `slug`, `uuid`, and `dashboard` payload |
| `dashboard-analysis-tool` | `DashboardAnalysisTool` | Analyzes a prompt for intent, title, and component hints (KPIs, charts, tables, filters) without full generation |
| `dashboard-spec-tool` | `DashboardSpecTool` | Builds and persists a structured dashboard specification |
| `dashboard-html-tool` | `DashboardHtmlTool` | Full render pipeline — returns `live_url`, `raw_data`, and pre-rendered HTML/CSS/JS |
| `dashboard-export-tool` | `DashboardExportTool` | Exports dashboard config as pretty-printed JSON |
| `dashboard-blade-create` | `DashboardBladeCreateTool` | Creates `resources/views/dashboard-studio/dashboard-studio.blade.php` from an existing slug |

> **Note:** `database-query-tool` appears in config but is not yet registered in `DashboardServer`. It is reserved for a future release.

### Typical AI workflow

1. User asks: *"Show me a sales overview with revenue by month"*
2. AI calls `dashboard-tool` (or `dashboard-html-tool`) with the prompt
3. Tool returns `live_url` — AI presents this URL to the user
4. AI optionally summarizes metrics from `raw_data` / `dashboard` payload
5. AI may offer Blade file creation via `dashboard-blade-create` using the returned `slug`

### MCP server instructions (built-in defaults)

The server ships with strict instructions for AI models:

- Never ask users for schema, SQL dumps, or migrations
- Always prefer `live_url` for dashboard delivery
- Never generate custom HTML/CSS/JS — use the tools
- Use `raw_data` for text summaries only
- Never fabricate data — all values are from live queries

Override via `config('mcp-dashboard-studio.instructions')`.

---

## 5. Dashboard Generation Pipeline

`DashboardGenerator` orchestrates the full pipeline:

```
User Prompt
    │
    ▼
EnhancedPromptAnalyzer / PromptAnalyzer
    │  (extract intent, component hints)
    ▼
DashboardPlanner
    │  (build explicit plan: KPIs, charts, tables, filters)
    ▼
DashboardSpecBuilder
    │  (construct DashboardSpec DTO)
    ▼
DataSourceResolver::hydrate()
    │  (populate KPI values, chart datasets, table rows from DB)
    ▼
DashboardValidator
    │  (validate spec integrity)
    ▼
LayoutEngine
    │  (apply responsive grid layout)
    ▼
HtmlRenderer / Database Renderers
    │  (produce HTML, CSS, JS output)
    ▼
DashboardStorageService::storeSpec()
    │  (persist to mcp_dashboard_definitions)
```

### Component generators

| Generator | Output |
|---|---|
| `KpiGenerator` | KPI cards with computed values |
| `ChartGenerator` | Chart.js configs (bar, line, pie, doughnut) |
| `TableGenerator` | Data tables with live rows |
| `FilterGenerator` | AJAX-powered filter controls |

### Database services

| Service | Role |
|---|---|
| `DatabaseSchemaExplorer` | Discovers tables and columns |
| `SchemaAnalyzer` | Analyzes column types and semantics |
| `RelationshipDetector` | Detects FK relationships |
| `MetricDiscoveryService` | Finds measurable columns |
| `MetricRecommendationEngine` | Recommends KPIs from schema |
| `EntityDiscoveryService` | Identifies business entities |
| `SchemaCache` | Caches schema introspection results |
| `DynamicQueryBuilder` | Builds safe dynamic SQL |
| `DynamicQueryExecutor` | Executes queries with limits |
| `QueryResultFormatter` | Formats results for charts/tables |

### Data modes

| Mode | Config | Behavior |
|---|---|---|
| `schema` (default) | `MCP_DATA_MODE=schema` | Schema introspection drives generation |
| `database` | `MCP_DATA_MODE=database` | Uses live database data (`MCP_DB_ENABLED=true`) |

---

## 6. Database Layer

### Tables

#### `mcp_dashboard_definitions`

| Column | Type | Description |
|---|---|---|
| `id` | bigint | Primary key |
| `uuid` | uuid | Unique identifier for manager routes |
| `name` | string | Human-readable dashboard name |
| `slug` | string | URL slug (unique) |
| `prompt` | text | Original user prompt |
| `description` | text | Optional description |
| `layout_json` | json | Full dashboard specification |
| `status` | enum | `public` or `private` (default: `private`) |
| `version` | int | Schema version (default: 1) |
| `created_by` | bigint | Optional creator user ID |
| `hash` | string | Optional cache hash |
| `deleted_at` | timestamp | Soft delete support |

#### `mcp_dashboard_access`

Pivot table linking system users (host app's `users` table) to private dashboards.

#### `mcp_dashboard_custom_users`

External/invited users for private dashboards:

- Email + hashed access token
- Optional password for login form
- Token expiry (`token_expires_at`)

#### `mcp_dashboard_audit_logs`

Audit trail for manager actions (create, update, delete, access changes, etc.).

### Excluded tables

The schema discovery engine excludes framework and OAuth tables by default (see `config('mcp-dashboard-studio.database.discovery.excluded_tables')`).

---

## 7. Authentication & Authorization

### MCP endpoint authentication (`VerifyMcpToken`)

Two strategies, tried in order:

#### Strategy A: Passport OAuth Bearer token

- Supports JWT tokens (extracts `jti` claim) and opaque tokens
- Validates against `oauth_access_tokens` table
- Checks revocation and expiry
- Sets authenticated user via `Auth::setUser()`

#### Strategy B: Static secret token

- Reads `MCP_SECRET_TOKEN` from config
- Accepted via:
  - `Authorization: Bearer <token>`
  - `X-MCP-TOKEN` header
  - `mcp-token` header
  - `?token=<token>` query parameter
- Uses `hash_equals()` for timing-safe comparison

On failure: returns JSON-RPC 2.0 error with HTTP 401 and `WWW-Authenticate` header.

### OAuth 2.1 flow

```
AI Client                          Laravel App
    │                                   │
    │  GET /.well-known/oauth-*         │
    │ ─────────────────────────────────►│  Discovery metadata
    │                                   │
    │  POST /oauth/register             │
    │ ─────────────────────────────────►│  RFC 7591 dynamic registration
    │                                   │
    │  GET /oauth/authorize             │
    │ ─────────────────────────────────►│  User login + consent
    │                                   │
    │  POST /oauth/token                │
    │ ─────────────────────────────────►│  Access + refresh tokens
    │                                   │
    │  MCP requests with Bearer token   │
    │ ─────────────────────────────────►│  VerifyMcpToken
```

**Discovery endpoints:**

- `GET /.well-known/oauth-authorization-server` — RFC 8414
- `GET /.well-known/oauth-protected-resource` — RFC 9728

**Dynamic registration:** `POST /oauth/register` (RFC 7591)

- Supports public PKCE clients (`token_endpoint_auth_method: none`)
- Rate limited: 10 requests/minute per IP
- HTTPS enforced on redirect URIs in non-local environments
- Domain allowlist via `oauth.allowed_redirect_domains`

**Scopes:** `mcp-access` — "Access MCP Dashboard API"

**Token TTL:** Configurable (default: 30 days access / 90 days refresh)

### Dashboard viewer access (`CheckDashboardAccess`)

Applied to `GET /dashboard-studio/{slug}`:

| Condition | Result |
|---|---|
| Dashboard not found | 404 |
| `status = public` | Allow |
| Custom user session valid | Allow |
| Authenticated system user in access list | Allow |
| Authenticated system user NOT in list | 403 |
| Valid `?access_token=` query param | Redirect to password form |
| Invalid/expired token | 401 |
| No credentials | 401 |

### Manager access (`RequireManagerAccess`)

| Check | Result |
|---|---|
| `MCP_MANAGER_ENABLED=false` | 503 |
| Not authenticated | Redirect to login or 401 |
| `MCP_MANAGER_REQUIRE_ADMIN=true` and user lacks `is_admin` | 403 |

---

## 8. Routes & HTTP API

All customizable routes honor `MCP_ROUTE_PREFIX` plus per-segment config. OAuth discovery and Passport routes stay at the app root (RFC requirements).

### MCP endpoint

| Method | Path | Middleware | Purpose |
|---|---|---|---|
| * | `/{prefix}/mcp/generate-dashboard` | `VerifyMcpToken` | MCP JSON-RPC server |

### Dashboard viewer

| Method | Path | Middleware | Purpose |
|---|---|---|---|
| GET | `/{prefix}/dashboard-studio/{slug}` | `web`, `CheckDashboardAccess` | Live dashboard |
| POST | `/{prefix}/dashboard-studio/{slug}/filter` | `web` | AJAX filter |
| GET/POST | `/{prefix}/dashboard-studio/{slug}/custom-login` | `web` | Custom user login |

### REST API (prefix: `api`)

| Method | Path | Purpose |
|---|---|---|
| POST | `/{prefix}/api/dashboard/chat` | Chat endpoint |
| POST | `/{prefix}/api/dashboard/generate` | Generate dashboard |
| GET | `/{prefix}/api/dashboard/render/{slug}` | Render dashboard |
| GET | `/{prefix}/api/dashboard/html/{slug}` | HTML output |
| GET | `/{prefix}/api/dashboard/css` | Dashboard CSS |
| GET | `/{prefix}/api/dashboard/js` | Dashboard JS |

### OAuth (app root)

| Method | Path | Purpose |
|---|---|---|---|
| GET | `/.well-known/oauth-authorization-server` | OAuth discovery |
| GET | `/.well-known/oauth-protected-resource` | Protected resource metadata |
| POST | `/oauth/register` | Dynamic client registration |
| GET | `/oauth/authorize` | Authorization (Passport) |
| POST | `/oauth/token` | Token exchange (Passport) |
| GET | `/login` | Package login page (if enabled) |

### Route configuration

```ini
MCP_ROUTE_PREFIX=                    # Global prefix for package routes
MCP_DASHBOARD_PREFIX=dashboard-studio # Dashboard viewer segment
MCP_PATH=mcp/generate-dashboard      # MCP endpoint segment
MCP_API_PREFIX=api                   # API segment
MCP_RENDER_PREFIX=dashboard          # Render segment
MCP_MANAGER_PREFIX=mcp-manager       # Manager UI segment
```

Use `RoutePaths::mcpUrl()` and `RoutePaths::dashboardShowUrl($slug)` in code for URL generation.

---

## 9. Dashboard Manager UI

Accessible at `/{prefix}/mcp-manager/dashboards` (requires authentication).

### Features

| Feature | Route name | Description |
|---|---|---|
| List dashboards | `mcp.manager.dashboards.index` | Paginated list with search/filter |
| Edit dashboard | `mcp.manager.dashboards.edit` | Update name, status, description |
| Soft delete | `mcp.manager.dashboards.destroy` | Move to trash |
| Bulk actions | `mcp.manager.dashboards.bulk` | Bulk status change, delete |
| Export | `mcp.manager.dashboards.export` | Export dashboard JSON |
| Import | `mcp.manager.dashboards.import` | Import dashboard JSON |
| Audit log | `mcp.manager.dashboards.audit` | View action history |
| Trash | `mcp.manager.dashboards.trash` | View/restore/purge deleted |
| Access control | `mcp.manager.dashboards.access.*` | Grant/revoke system and custom users |

### Access management

**System users:** Grant access by user ID from the host app's `users` table.

**Custom users:** Invite external users by email. They receive a tokenized URL (`?access_token=...`) that redirects to a password form, then establishes a session.

Custom user token TTL: `MCP_CUSTOM_USER_TOKEN_TTL_DAYS` (default: 30 days).

---

## 10. Configuration Reference

Full config file: `config/mcp-dashboard-studio.php` (publish with `--tag=mcp-dashboard-studio-config`).

### Environment variables

| Variable | Default | Description |
|---|---|---|
| `MCP_ENABLED` | `true` | Master on/off switch |
| `MCP_SECRET_TOKEN` | — | Static token for non-OAuth clients |
| `MCP_SERVER_URL` | `APP_URL` | Public MCP server URL |
| `MCP_OAUTH_ENABLED` | `true` | Enable OAuth (requires Passport) |
| `MCP_OAUTH_LOGIN_ROUTES` | `true` | Register `/login` and `/logout` routes |
| `MCP_REQUIRE_ADMIN_CONSENT` | `false` | Restrict OAuth consent to admin users |
| `MCP_TOKEN_TTL_DAYS` | `30` | OAuth access token lifetime |
| `MCP_REFRESH_TOKEN_TTL_DAYS` | `90` | OAuth refresh token lifetime |
| `MCP_ROUTE_PREFIX` | — | Global route prefix |
| `MCP_DASHBOARD_PREFIX` | `dashboard-studio` | Dashboard URL segment |
| `MCP_PATH` | `mcp/generate-dashboard` | MCP endpoint segment |
| `MCP_DATA_MODE` | `schema` | `schema` or `database` |
| `MCP_DB_ENABLED` | `false` | Enable live DB data mode |
| `MCP_DB_CONNECTION` | — | Database connection name |
| `MCP_MAX_TABLES` | `100` | Max tables to discover |
| `MCP_MAX_COLUMNS` | `8` | Max columns per table |
| `MCP_LIMIT` | `10` | Sample row limit |
| `MCP_MAX_QUERY_LIMIT` | `100` | Max query result rows |
| `MCP_CACHE_TTL` | `3600` | Schema cache TTL (seconds) |
| `MCP_LOGGING_ENABLED` | `false` | Verbose debug logging |
| `MCP_MANAGER_ENABLED` | `true` | Enable manager UI |
| `MCP_MANAGER_REQUIRE_ADMIN` | `false` | Restrict manager to admins |
| `MCP_MANAGER_PREFIX` | `mcp-manager` | Manager URL prefix |
| `MCP_MANAGER_PER_PAGE` | `10` | Dashboards per page |
| `MCP_CUSTOM_USER_TOKEN_TTL_DAYS` | `30` | Custom user invite expiry |

### Tool toggles

```php
'tools' => [
    'dashboard-tool'          => true,
    'dashboard-analysis-tool' => true,
    'dashboard-spec-tool'     => true,
    'dashboard-html-tool'     => true,
    'dashboard-export-tool'   => true,
    'database-query-tool'     => true,  // reserved, not yet wired
    'dashboard-blade-create'  => true,
],
```

---

## 11. Security Model

| Risk | Mitigation |
|---|---|
| Unauthorized MCP access | `VerifyMcpToken` — OAuth or static token required |
| Unauthorized dashboard viewing | `CheckDashboardAccess` — public/private + access lists |
| Unauthorized manager access | `RequireManagerAccess` — auth + optional admin gate |
| Rogue OAuth client registration | Domain allowlist, HTTPS enforcement, rate limiting (10/min) |
| Non-admin OAuth consent | `MCP_REQUIRE_ADMIN_CONSENT` gate |
| Long-lived tokens | Configurable TTL (default 30/90 days vs Passport's 1 year) |
| SQL injection in dynamic queries | Parameterized queries via `DynamicQueryBuilder` with limits |
| Timing attacks on static token | `hash_equals()` comparison |
| Sensitive table exposure | Configurable excluded/whitelisted tables |

---

## 12. Publishing & Customization

```bash
# Configuration
php artisan vendor:publish --tag=mcp-dashboard-studio-config

# Database migrations (optional — auto-loaded by default)
php artisan vendor:publish --tag=mcp-dashboard-studio-migrations

# Frontend assets (CSS/JS)
php artisan vendor:publish --tag=mcp-dashboard-studio-assets

# Blade views (override package templates)
php artisan vendor:publish --tag=mcp-dashboard-studio-views
```

### View overrides

Published views go to `resources/views/vendor/mcp-dashboard-studio/`. Passport consent view can be overridden at `resources/views/vendor/mcp-dashboard-studio/passport/authorize.blade.php`.

Package views use the `mcp-dashboard-studio::` namespace (e.g., `mcp-dashboard-studio::manager.index`).

### NLP client

Bind a custom NLP client by implementing `NlpClientInterface` and rebinding in a service provider:

```php
$this->app->bind(
    \Webbycrown\McpDashboardStudio\Mcp\Services\Contracts\NlpClientInterface::class,
    YourCustomNlpClient::class
);
```

Default: `DefaultNlpClient` (rule-based analysis, no external API).

---

## 13. Testing

```bash
cd vendor/webbycrown/mcp-dashboard-studio
vendor/bin/phpunit
```

Uses Orchestra Testbench with SQLite `:memory:`.

### Test coverage

| Test | Coverage |
|---|---|
| `DynamicClientRegistrationTest` | RFC 7591 client registration |
| `OAuthDiscoveryTest` | RFC 8414/9728 metadata endpoints |
| `OAuthLoginTest` | Login flow and redirects |
| `VerifyMcpTokenTest` | OAuth and static token validation |

---

## 14. Extension Points

| Extension | How |
|---|---|
| Custom NLP analysis | Implement `NlpClientInterface` |
| Custom data provider | Implement `DataProviderInterface` |
| Custom component generator | Implement `ComponentGeneratorInterface` |
| Route paths | Configure `route_prefix` and `routes.*` |
| MCP instructions | Set `instructions` in config |
| Tool enable/disable | Toggle `tools.*` in config |
| View theming | Publish and override `mcp-dashboard-studio::` views |
| Admin detection | Add `is_admin` to User model for manager/consent gates |
| OAuth redirect domains | Set `oauth.allowed_redirect_domains` |

---

## 15. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| OAuth warning in logs | Passport not installed | `composer require laravel/passport` + migrate + keys |
| `invalid_client` on token exchange | Confidential client for PKCE-only AI tool | Re-register as public client via `/oauth/register` |
| `Session store not set` | Route missing `web` middleware | Ensure package is up to date (fixed in unreleased) |
| MCP returns 401 | Missing/invalid token | Set `MCP_SECRET_TOKEN` or complete OAuth flow |
| Dashboard shows no data | DB mode disabled | Set `MCP_DB_ENABLED=true` and `MCP_DATA_MODE=database` |
| Manager returns 503 | Manager disabled | Set `MCP_MANAGER_ENABLED=true` |
| AI asks for schema | Wrong tool or instructions | Ensure MCP server instructions are loaded; use `dashboard-tool` |
| Assets missing | Assets not published | `vendor:publish --tag=mcp-dashboard-studio-assets` |
| ngrok/OAuth redirect fails | Proxy headers not trusted | Add `trustProxies(at: '*')` in `bootstrap/app.php` |

---

## License

MIT © [Archi Patel](https://github.com/webbycrown)
