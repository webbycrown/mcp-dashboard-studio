<?php

namespace Webbycrown\McpDashboardStudio\Tests;

use Webbycrown\McpDashboardStudio\Providers\McpDashboardStudioServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

/**
 * Base Test Case
 *
 * Boots the package service provider via Orchestra Testbench so all
 * package routes, config, views, and migrations are available in tests.
 */
abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            \Laravel\Passport\PassportServiceProvider::class,
            McpDashboardStudioServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Use SQLite in-memory for fast test runs
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Auth guard setup required by Passport
        $app['config']->set('auth.guards.api', [
            'driver'   => 'passport',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model'  => \Webbycrown\McpDashboardStudio\Tests\Fixtures\User::class,
        ]);

        // Package config
        $app['config']->set('mcp-dashboard-studio.oauth.enabled', true);
        $app['config']->set('mcp-dashboard-studio.mcp_secret_token', 'test-secret-token');
        $app['config']->set('mcp-dashboard-studio.oauth.token_ttl_days', 30);
        $app['config']->set('mcp-dashboard-studio.oauth.refresh_token_ttl_days', 90);
        $app['config']->set('mcp-dashboard-studio.oauth.allowed_redirect_domains', []);
        $app['config']->set('mcp-dashboard-studio.oauth.require_admin_for_consent', false);

        // Needed for session/CSRF in web middleware tests
        $app['config']->set('session.driver', 'array');
        $app['config']->set('app.key', 'base64:2fl+Ktvkfl+Ktvkfl+Ktvkfl+Ktvkfl+KtvkY=');
    }

    /**
     * Run Passport + package migrations before each test.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../src/Database/migrations');
        $this->artisan('migrate', ['--database' => 'testing'])->run();
        $this->artisan('passport:keys', ['--force' => true])->run();
    }
}
