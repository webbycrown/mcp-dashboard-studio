<?php

namespace Webbycrown\McpDashboardStudio\Providers;

use Webbycrown\McpDashboardStudio\Http\Middleware\CheckDashboardAccess;
use Webbycrown\McpDashboardStudio\Http\Middleware\RequireManagerAccess;
use Webbycrown\McpDashboardStudio\Http\Middleware\VerifyMcpToken;
use Webbycrown\McpDashboardStudio\Services\DashboardAuthorizationService;
use Webbycrown\McpDashboardStudio\Mcp\Services\Contracts\NlpClientInterface;
use Webbycrown\McpDashboardStudio\Mcp\Services\Nlp\DefaultNlpClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class McpDashboardStudioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge package config
        $this->mergeConfigFrom(
            __DIR__ . '/../Config/mcp-dashboard-studio.php',
            'mcp-dashboard-studio'
        );

        // NLP Client binding
        $this->app->bind(NlpClientInterface::class, DefaultNlpClient::class);

        // Register Dashboard Authorization Service
        $this->app->singleton(DashboardAuthorizationService::class, function () {
            return new DashboardAuthorizationService();
        });

        // Register core MCP tools
        $this->registerTools();
    }

    public function boot(): void
    {
        if (! config('mcp-dashboard-studio.enabled', true)) {
            return;
        }

        //  Routes 
        $this->loadRoutesFrom(__DIR__ . '/../Routes/ai.php');
        $this->loadRoutesFrom(__DIR__ . '/../Routes/web.php');

        //  Middleware Aliases 
        // Allows views/routes to reference these by short alias if needed.
        $this->app['router']->aliasMiddleware('mcp.dashboard.access', CheckDashboardAccess::class);
        $this->app['router']->aliasMiddleware('mcp.manager.access', RequireManagerAccess::class);

        //  Database 
        try {
            $this->loadMigrationsFrom(__DIR__ . '/../Database/migrations');
            Log::debug('[MCP] Migrations loaded successfully from package');
        } catch (\Throwable $e) {
            Log::error('[MCP] Failed to load migrations', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        //  Passport OAuth Views 
        // Set Passport's authorize/login views to use our package views,
        // but only if Passport is installed and OAuth is enabled.
        $this->bootPassportViews();

        //  Register Passport OAuth Scopes 
        $this->bootPassportScopes();

        //  Token Lifetime (Security: Risk 4) 
        $this->bootPassportTokenTtl();

        //  Developer Warning: Passport Missing 
        $this->warnIfPassportMissing();

        //  Views 
        // Load package views under namespace 'mcp-dashboard-studio'
        // Host apps can override by publishing views to resources/views/vendor/mcp-dashboard-studio/
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'mcp-dashboard-studio');

        //  Publishable Assets 
        $this->registerPublishables();
    }

    // 
    //  Passport OAuth Integration
    // 
    
    /**
     * Configure Passport to use our custom authorization view.
     *
     * Only runs when:
     *   - Laravel Passport is installed
     *   - mcp-dashboard-studio.oauth.enabled = true
     *   - The host app has not set its own Passport authorization view
     */
    protected function bootPassportViews(): void
    {
        if (! class_exists(\Laravel\Passport\Passport::class)) {
            return;
        }

        if (! config('mcp-dashboard-studio.oauth.enabled', true)) {
            return;
        }

        try {
            // Use host-published view if it exists, otherwise fall back to package view
            $customView = resource_path('views/vendor/mcp-dashboard-studio/passport/authorize.blade.php');
            if (file_exists($customView)) {
                \Laravel\Passport\Passport::authorizationView('vendor/mcp-dashboard-studio/passport/authorize');
            } else {
                \Laravel\Passport\Passport::authorizationView('mcp-dashboard-studio::passport.authorize');
            }
        } catch (\Throwable $e) {
            Log::error('[MCP] Could not configure Passport authorization view', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Register required OAuth scopes with Passport.
     */
    protected function bootPassportScopes(): void
    {
        if (! class_exists(\Laravel\Passport\Passport::class)) {
            return;
        }

        if (! config('mcp-dashboard-studio.oauth.enabled', true)) {
            return;
        }

        try {
            $scopes  = config('mcp-dashboard-studio.oauth.scopes', ['mcp-access' => 'Access MCP Dashboard API']);
            $current = \Laravel\Passport\Passport::$scopes ?? [];

            foreach ($scopes as $id => $description) {
                if (! array_key_exists($id, $current)) {
                    $current[$id] = $description;
                }
            }

            \Laravel\Passport\Passport::tokensCan($current);
        } catch (\Throwable $e) {
            Log::error('[MCP] Could not register Passport scopes', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Apply token + refresh-token lifetimes from package config to Passport.
     *
     * Reads:
     *   mcp-dashboard-studio.oauth.token_ttl_days         (default 30)
     *   mcp-dashboard-studio.oauth.refresh_token_ttl_days (default 90)
     *
     * Only runs when Passport is installed and OAuth is enabled.
     * Wrapped in try/catch — if a future Passport version changes the API,
     * this fails gracefully with a log message instead of crashing the app.
     */
    protected function bootPassportTokenTtl(): void
    {
        if (! class_exists(\Laravel\Passport\Passport::class)) {
            return;
        }

        if (! config('mcp-dashboard-studio.oauth.enabled', true)) {
            return;
        }

        try {
            $accessTtl  = (int) config('mcp-dashboard-studio.oauth.token_ttl_days', 30);
            $refreshTtl = (int) config('mcp-dashboard-studio.oauth.refresh_token_ttl_days', 90);

            \Laravel\Passport\Passport::tokensExpireIn(
                now()->addDays($accessTtl)
            );

            \Laravel\Passport\Passport::refreshTokensExpireIn(
                now()->addDays($refreshTtl)
            );

            // Log::debug('[MCP] Passport token TTL applied', [
            //     'access_token_days'  => $accessTtl,
            //     'refresh_token_days' => $refreshTtl,
            // ]);

        } catch (\Throwable $e) {
            Log::error('[MCP] Could not set Passport token TTL', [
                'error' => $e->getMessage(),
                'hint'  => 'Check that laravel/passport ^13.0 is installed.',
            ]);
        }
    }

    // 
    //  Developer Warnings
    // 
    
    /**
     * Warn when OAuth is enabled in config but Passport is not installed.
     *
     * Logged only once per boot at WARNING level.
     * The package continues to work — MCP tools work with MCP_SECRET_TOKEN.
     */
    protected function warnIfPassportMissing(): void
    {
        if (! config('mcp-dashboard-studio.oauth.enabled', true)) {
            return;
        }

        if (class_exists(\Laravel\Passport\Passport::class)) {
            return;
        }

        Log::warning(
            '[MCP Dashboard Studio] OAuth 2.1 is DISABLED: Laravel Passport is not installed.',
            [
                'impact'     => 'AI clients (Claude, ChatGPT) cannot connect via OAuth.',
                'workaround' => 'Set MCP_SECRET_TOKEN in .env to use static token auth instead.',
                'fix'        => implode(' && ', [
                    'composer require laravel/passport',
                    'php artisan migrate',
                    'php artisan passport:install --uuids',
                    'php artisan passport:keys',
                ]),
                'disable_warning' => 'Set MCP_OAUTH_ENABLED=false in .env to suppress this warning.',
            ]
        );
    }

    // 
    //  Tools Registration
    // 
    
    protected function registerTools(): void
    {
        $this->app->singleton('mcp.dashboard.tool', function () {
            return new \Webbycrown\McpDashboardStudio\Mcp\Tools\DashboardTool();
        });

        $this->app->singleton('mcp.dashboard.analysis.tool', function () {
            return new \Webbycrown\McpDashboardStudio\Mcp\Tools\DashboardAnalysisTool();
        });

        $this->app->singleton('mcp.dashboard.spec.tool', function () {
            return new \Webbycrown\McpDashboardStudio\Mcp\Tools\DashboardSpecTool();
        });

        $this->app->singleton('mcp.dashboard.html.tool', function () {
            return new \Webbycrown\McpDashboardStudio\Mcp\Tools\DashboardHtmlTool();
        });

        $this->app->singleton('mcp.dashboard.export.tool', function () {
            return new \Webbycrown\McpDashboardStudio\Mcp\Tools\DashboardExportTool();
        });

        $this->app->singleton('mcp.dashboard.blade.create.tool', function () {
            return new \Webbycrown\McpDashboardStudio\Mcp\Tools\DashboardBladeCreateTool();
        });
    }

    // 
    //  Publishable Assets
    // 
    
    protected function registerPublishables(): void
    {
        $this->publishes([
            __DIR__ . '/../Config/mcp-dashboard-studio.php' => config_path('mcp-dashboard-studio.php'),
        ], 'mcp-dashboard-studio-config');

        $this->publishes([
            __DIR__ . '/../Database/migrations' => database_path('migrations'),
        ], 'mcp-dashboard-studio-migrations');

        $this->publishes([
            __DIR__ . '/../Resources/assets' => public_path('mcp-dashboard-studio/assets'),
        ], 'mcp-dashboard-studio-assets');

        $this->publishes([
            __DIR__ . '/../Resources/views' => resource_path('views/vendor/mcp-dashboard-studio'),
        ], 'mcp-dashboard-studio-views');
    }
}