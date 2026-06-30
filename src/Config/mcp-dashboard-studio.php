<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable / Disable Package
    |--------------------------------------------------------------------------
    | Set to false to completely disable all MCP routes and functionality.
    */
    'enabled' => env('MCP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | OAuth 2.1 Configuration
    |--------------------------------------------------------------------------
    | Settings for the OAuth 2.1 + PKCE authentication layer.
    |
    | 'enabled'      — Enable OAuth support (requires Laravel Passport).
    | 'login_routes' — Register /login and /logout routes for Passport consent flow.
    |                  Set to false if your host app already has an auth system.
    | 'scopes'       — OAuth scopes this MCP server supports.
    |                  Key = scope identifier, Value = human-readable description.
    */
    'oauth' => [
        'enabled'      => env('MCP_OAUTH_ENABLED', true),
        'login_routes' => env('MCP_OAUTH_LOGIN_ROUTES', true),
        'scopes'       => [
            'mcp-access' => 'Access MCP Dashboard API',
        ],

        /*
        |----------------------------------------------------------------------
        | Dynamic Client Registration — Domain Allowlist  (Security: Risk 2)
        |----------------------------------------------------------------------
        | Controls which AI tool domains may auto-register via POST /oauth/register.
        |
        | Empty array  → any domain can register (open, default).
        | Populated    → only listed domains are accepted; others receive HTTP 400.
        |
        | Supports wildcard subdomains:  '*.claude.ai'
        |
        | Example:
        |   'allowed_redirect_domains' => ['claude.ai', 'chatgpt.com', 'cursor.com'],
        */
        'allowed_redirect_domains' => [],

        /*
        |----------------------------------------------------------------------
        | Admin-Only Consent Gate  (Security: Risk 3)
        |----------------------------------------------------------------------
        | When true, only users where the 'is_admin' attribute is truthy
        | may approve the OAuth consent screen. All other logged-in users
        | are redirected back with a 403 error.
        |
        | Set to false (default) to allow any logged-in user to authorize.
        | Override MCP_REQUIRE_ADMIN_CONSENT=true in .env to enable.
        */
        'require_admin_for_consent' => env('MCP_REQUIRE_ADMIN_CONSENT', false),

        /*
        |----------------------------------------------------------------------
        | Token Lifetime  (Security: Risk 4)
        |----------------------------------------------------------------------
        | How long issued OAuth access tokens and refresh tokens remain valid.
        | Shorter values improve security at the cost of more frequent re-logins.
        |
        | Defaults: 30 days access / 90 days refresh.
        | Passport's built-in default is 1 year — reducing this is recommended.
        */
        'token_ttl_days'         => env('MCP_TOKEN_TTL_DAYS', 30),
        'refresh_token_ttl_days' => env('MCP_REFRESH_TOKEN_TTL_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Static MCP Secret Token
    |--------------------------------------------------------------------------
    | Used by Postman, cURL, and other simple API clients (not OAuth flow).
    | Set in .env:  MCP_SECRET_TOKEN=your-secret
    | Send as:      Authorization: Bearer <token>
    |               OR X-MCP-TOKEN: <token>
    |               OR ?token=<token>
    |
    | Can be null if only OAuth is used.
    */
    'mcp_secret_token' => env('MCP_SECRET_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | MCP Server URL
    |--------------------------------------------------------------------------
    | The publicly accessible URL of this MCP server.
    | Defaults to APP_URL if not set.
    */
    'server_url' => env('MCP_SERVER_URL', null),

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    | Optional global prefix prepended to all customizable package routes below.
    | Does NOT apply to /.well-known/* or Passport /oauth/* (RFC requirements).
    |
    | Example: MCP_ROUTE_PREFIX=apps/analytics →
    |   /apps/analytics/dashboard-studio/{slug}
    |   /apps/analytics/mcp/generate-dashboard
    */
    'route_prefix' => env('MCP_ROUTE_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Route Segments
    |--------------------------------------------------------------------------
    | Customize individual URL paths. Combined with route_prefix when registered.
    | Named routes (dashboard-studio.show, mcp.manager.*) stay stable for views.
    */
    'routes' => [
        'dashboard' => env('MCP_DASHBOARD_PREFIX', 'dashboard-studio'),
        'mcp'       => env('MCP_PATH', 'mcp/generate-dashboard'),
        'api'       => env('MCP_API_PREFIX', 'api'),
        'render'    => env('MCP_RENDER_PREFIX', 'dashboard'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Mode
    |--------------------------------------------------------------------------
    | 'schema'   — Use schema introspection for dashboard generation (default).
    | 'database' — Use live database data.
    */
    'data_mode' => env('MCP_DATA_MODE', 'schema'),

    /*
    |--------------------------------------------------------------------------
    | Database Configuration
    |--------------------------------------------------------------------------
    */
    'database' => [
        'enabled'    => env('MCP_DB_ENABLED', false),
        'connection' => env('MCP_DB_CONNECTION', null),

        'discovery' => [
            'max_tables'      => env('MCP_MAX_TABLES', 100),
            'max_columns'     => env('MCP_MAX_COLUMNS', 8),
            'sample_rows'     => env('MCP_LIMIT', 10),
            'max_query_limit' => env('MCP_MAX_QUERY_LIMIT', 100),
            'excluded_tables' => [
                'users',
                'password_resets',
                'password_reset_tokens',
                'sessions',
                'personal_access_tokens',
                'failed_jobs',
                'jobs',
                'migrations',
                'mcp_dashboard_definitions',
                'oauth_access_tokens',
                'oauth_auth_codes',
                'oauth_clients',
                'oauth_personal_access_clients',
                'oauth_refresh_tokens',
            ],
            'whitelisted_tables' => [],
        ],

        'schema' => [
            'cache_ttl'    => env('MCP_CACHE_TTL', 3600),
            'cache_prefix' => env('MCP_CACHE_PREFIX', 'mcp_schema'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    | Controls whether debug/info logs are written during execution.
    | Keep false in production to reduce log noise.
    */
    'logging_enabled' => env('MCP_LOGGING_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | MCP Server Instructions
    |--------------------------------------------------------------------------
    | Instructions sent to the AI model during the MCP initialization handshake.
    | Set to null to use the built-in defaults.
    */
    'instructions' => "## YOUR CAPABILITIES
                        - You have tools that automatically discover ALL database tables, columns, and relationships.
                        - Every tool performs live schema introspection — no manual schema input is needed.
                        - All KPI values, chart datasets, and table rows come from REAL database queries.
                        - Generated dashboards are persisted and accessible via a live interactive URL.

                        ## CRITICAL RULES

                        ### 1. NEVER ask the user for database information
                        Do NOT ask for: database schema, SQL dumps, migration files, table structures, or column names.
                        Your tools discover everything automatically from the live database connection.

                        ### 2. ALWAYS prefer the live_url for dashboard delivery
                        When a tool returns a `live_url`, present it prominently to the user.

                        ### 3. Use raw_data for text summaries ONLY
                        Do NOT rebuild the dashboard as HTML/CSS/JS — the live_url renders a professional interactive dashboard.

                        ### 4. NEVER generate mock or placeholder data
                        All values returned by the tools are real. Report 0 or empty values honestly.",

    /*
    |--------------------------------------------------------------------------
    | Schema Analysis
    |--------------------------------------------------------------------------
    */
    'schema_analysis' => [
        'framework_tables' => [
            'mcp_dashboard_definitions',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Tools — Enable / Disable Individual Tools
    |--------------------------------------------------------------------------
    */
    'tools' => [
        'dashboard-tool'          => true,
        'dashboard-analysis-tool' => true,
        'dashboard-spec-tool'     => true,
        'dashboard-html-tool'     => true,
        'dashboard-export-tool'   => true,
        'database-query-tool'     => true,
        'dashboard-blade-create'  => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard Manager UI
    |--------------------------------------------------------------------------
    | Settings for the web-based CRUD panel at /{prefix}/dashboards.
    |
    | 'enabled'        — Set false to disable the manager UI entirely (HTTP 503).
    | 'require_admin'  — Set true to restrict access to users where is_admin=true.
    | 'prefix'         — URL prefix for all manager routes.
    | 'per_page'       — Dashboards per page in the list view.
    |
    | Token expiry for custom user invites:
    | 'custom_user_token_ttl_days' — null = never expires, integer = days.
    */
    'manager' => [
        'enabled'                  => env('MCP_MANAGER_ENABLED', true),
        'require_admin'            => env('MCP_MANAGER_REQUIRE_ADMIN', false),
        'prefix'                   => env('MCP_MANAGER_PREFIX', 'mcp-manager'),
        'per_page'                 => env('MCP_MANAGER_PER_PAGE', 10),
        'custom_user_token_ttl_days' => env('MCP_CUSTOM_USER_TOKEN_TTL_DAYS', 30),
    ],



];

