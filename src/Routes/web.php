<?php

use Webbycrown\McpDashboardStudio\Http\Controllers\Auth\OAuthLoginController;
use Webbycrown\McpDashboardStudio\Http\Controllers\CustomUserLoginController;
use Webbycrown\McpDashboardStudio\Http\Controllers\DashboardController;
use Webbycrown\McpDashboardStudio\Http\Controllers\DashboardGenerateController;
use Webbycrown\McpDashboardStudio\Http\Controllers\DashboardRenderController;
use Webbycrown\McpDashboardStudio\Http\Controllers\DynamicClientRegistrationController;
use Webbycrown\McpDashboardStudio\Http\Controllers\Manager\DashboardAccessController;
use Webbycrown\McpDashboardStudio\Http\Controllers\Manager\DashboardAuditController;
use Webbycrown\McpDashboardStudio\Http\Controllers\Manager\DashboardBulkController;
use Webbycrown\McpDashboardStudio\Http\Controllers\Manager\DashboardDeleteController;
use Webbycrown\McpDashboardStudio\Http\Controllers\Manager\DashboardEditController;
use Webbycrown\McpDashboardStudio\Http\Controllers\Manager\DashboardExportImportController;
use Webbycrown\McpDashboardStudio\Http\Controllers\Manager\DashboardManagerController;
use Webbycrown\McpDashboardStudio\Http\Controllers\Manager\DashboardTrashController;
use Webbycrown\McpDashboardStudio\Http\Controllers\DashboardStudioController;
use Webbycrown\McpDashboardStudio\Http\Controllers\OAuthDiscoveryController;
use Webbycrown\McpDashboardStudio\Http\Controllers\RenderCssController;
use Webbycrown\McpDashboardStudio\Http\Controllers\RenderHtmlController;
use Webbycrown\McpDashboardStudio\Http\Controllers\RenderJsController;
use Webbycrown\McpDashboardStudio\Http\Middleware\CheckDashboardAccess;
use Webbycrown\McpDashboardStudio\Http\Middleware\RequireManagerAccess;
use Webbycrown\McpDashboardStudio\Support\RoutePaths;
use Illuminate\Support\Facades\Route;

$passportInstalled = class_exists(\Laravel\Passport\Passport::class);
$oauthEnabled      = config('mcp-dashboard-studio.oauth.enabled', true);

// ── OAuth discovery + login (app root — RFC / Passport requirements) ─────────
Route::middleware('web')->group(function () use ($passportInstalled, $oauthEnabled) {
    if (! Route::has('mcp.oauth.authorization-server')) {
        Route::get('/.well-known/oauth-authorization-server', [OAuthDiscoveryController::class, 'authorizationServer'])
            ->name('mcp.oauth.authorization-server');
    }

    if (! Route::has('mcp.oauth.protected-resource')) {
        Route::get('/.well-known/oauth-protected-resource', [OAuthDiscoveryController::class, 'protectedResource'])
            ->name('mcp.oauth.protected-resource');
    }

    if ($passportInstalled && $oauthEnabled) {
        Route::get('/authorize', function () {
            return redirect(url('/oauth/authorize') . '?' . request()->getQueryString());
        })->name('mcp.oauth.authorize-alias');

        if (config('mcp-dashboard-studio.oauth.login_routes', true) && ! Route::has('login')) {
            Route::get('/login', [OAuthLoginController::class, 'showLogin'])->name('login');
            Route::post('/login', [OAuthLoginController::class, 'login'])->name('mcp.login');
            Route::get('/logout', [OAuthLoginController::class, 'logout'])->name('mcp.logout');
        }
    }
});

// ── RFC 7591 Dynamic Client Registration (stateless — AI servers, not browsers) ─
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/oauth/register', [DynamicClientRegistrationController::class, 'register']);
    Route::options('/oauth/register', [DynamicClientRegistrationController::class, 'options']);
});

// ── Customizable package routes (honour route_prefix + routes.* config) ────────
RoutePaths::withGlobalPrefix(function (): void {
    $dashboardPrefix = RoutePaths::segment('dashboard');
    $renderPrefix    = RoutePaths::segment('render');
    $apiPrefix       = RoutePaths::segment('api');
    $managerPrefix   = RoutePaths::managerSegment();

    Route::middleware('web')->group(function () use ($dashboardPrefix, $renderPrefix) {
        Route::prefix($dashboardPrefix)->group(function () {
            Route::get('{slug}', [DashboardStudioController::class, 'show'])
                ->middleware(CheckDashboardAccess::class)
                ->name('dashboard-studio.show');
            Route::post('{slug}/filter', [DashboardStudioController::class, 'filter'])
                ->name('dashboard-studio.filter');
            Route::get('{slug}/custom-login', [CustomUserLoginController::class, 'showForm'])
                ->name('dashboard-studio.custom-login');
            Route::post('{slug}/custom-login', [CustomUserLoginController::class, 'verify'])
                ->name('dashboard-studio.custom-login.verify');
        });

        Route::prefix($renderPrefix)->group(function () {
            Route::get('html/{slug}', [RenderHtmlController::class, 'html']);
            Route::get('css', [RenderCssController::class, 'css']);
            Route::get('js', [RenderJsController::class, 'js']);
        });
    });

    Route::prefix($apiPrefix)->group(function () use ($renderPrefix) {
        Route::post('dashboard/chat', [DashboardController::class, 'chat']);
        Route::post('dashboard/generate', [DashboardGenerateController::class, 'generate']);
        Route::get('dashboard/render/{slug}', [DashboardRenderController::class, 'render']);
        Route::get('dashboard/html/{slug}', [RenderHtmlController::class, 'html']);
        Route::get('dashboard/css', [RenderCssController::class, 'css']);
        Route::get('dashboard/js', [RenderJsController::class, 'js']);
    });

    Route::middleware(['web', RequireManagerAccess::class])
        ->prefix($managerPrefix)
        ->name('mcp.manager.')
        ->group(function () {
            Route::get('dashboards', [DashboardManagerController::class, 'index'])
                ->name('dashboards.index');
            Route::get('dashboards/{uuid}/edit', [DashboardEditController::class, 'edit'])
                ->name('dashboards.edit');
            Route::patch('dashboards/{uuid}', [DashboardEditController::class, 'update'])
                ->name('dashboards.update');
            Route::delete('dashboards/{uuid}', [DashboardDeleteController::class, 'destroy'])
                ->name('dashboards.destroy');
            Route::post('dashboards/bulk', [DashboardBulkController::class, 'handle'])
                ->name('dashboards.bulk');
            Route::post('dashboards/validate-bulk', [DashboardBulkController::class, 'validate'])
                ->name('dashboards.validate-bulk');
            Route::get('dashboards/{uuid}/export', [DashboardExportImportController::class, 'export'])
                ->name('dashboards.export');
            Route::post('dashboards/import', [DashboardExportImportController::class, 'import'])
                ->name('dashboards.import');
            Route::get('dashboards/{uuid}/audit', [DashboardAuditController::class, 'index'])
                ->name('dashboards.audit');
            Route::post('dashboards/{uuid}/audit/bulk', [DashboardAuditController::class, 'bulk'])
                ->name('dashboards.audit.bulk');
            Route::get('dashboards/trash', [DashboardTrashController::class, 'index'])
                ->name('dashboards.trash');
            Route::post('dashboards/{uuid}/restore', [DashboardTrashController::class, 'restore'])
                ->name('dashboards.restore');
            Route::delete('dashboards/{uuid}/purge', [DashboardTrashController::class, 'purge'])
                ->name('dashboards.purge');
            Route::post('dashboards/trash/empty', [DashboardTrashController::class, 'empty'])
                ->name('dashboards.trash.empty');
            Route::get('dashboards/{uuid}/access', [DashboardAccessController::class, 'index'])
                ->name('dashboards.access.index');
            Route::post('dashboards/{uuid}/access/system-user', [DashboardAccessController::class, 'grantSystemUser'])
                ->name('dashboards.access.system-user.grant');
            Route::delete('dashboards/{uuid}/access/{accessId}', [DashboardAccessController::class, 'revokeSystemUser'])
                ->name('dashboards.access.system-user.revoke');
            Route::post('dashboards/{uuid}/access/custom-user', [DashboardAccessController::class, 'grantCustomUser'])
                ->name('dashboards.access.custom-user.grant');
            Route::delete('dashboards/{uuid}/access/custom/{customUserId}', [DashboardAccessController::class, 'revokeCustomUser'])
                ->name('dashboards.access.custom-user.revoke');
        });
});
