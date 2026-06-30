<?php

namespace Webbycrown\McpDashboardStudio\Support;

use Illuminate\Support\Facades\Route;

/**
 * Resolves configurable package route segments from config/mcp-dashboard-studio.php.
 *
 * OAuth discovery (/.well-known) and Passport (/oauth/*) stay at the app root.
 * All other package routes honour route_prefix plus per-area segments.
 */
class RoutePaths
{
    private const DEFAULTS = [
        'dashboard' => 'dashboard-studio',
        'mcp'       => 'mcp/generate-dashboard',
        'api'       => 'api',
        'render'    => 'dashboard',
    ];

    public static function globalPrefix(): string
    {
        return trim((string) config('mcp-dashboard-studio.route_prefix', ''), '/');
    }

    public static function segment(string $key): string
    {
        $default = self::DEFAULTS[$key] ?? '';

        return trim((string) config("mcp-dashboard-studio.routes.{$key}", $default), '/');
    }

    public static function managerSegment(): string
    {
        return trim((string) config('mcp-dashboard-studio.manager.prefix', 'mcp-manager'), '/');
    }

    /** Register a callback inside the optional global route prefix group. */
    public static function withGlobalPrefix(callable $callback): void
    {
        $prefix = self::globalPrefix();

        if ($prefix !== '') {
            Route::prefix($prefix)->group($callback);
        } else {
            $callback();
        }
    }

    /** Absolute path for the MCP endpoint (leading slash, no domain). */
    public static function mcpPath(): string
    {
        return self::join(self::globalPrefix(), self::segment('mcp'));
    }

    /** Full URL for the MCP endpoint. */
    public static function mcpUrl(): string
    {
        return url(self::mcpPath());
    }

    public static function dashboardShowUrl(string $slug): string
    {
        return route('dashboard-studio.show', ['slug' => $slug]);
    }

    public static function dashboardFilterUrl(string $slug): string
    {
        return route('dashboard-studio.filter', ['slug' => $slug]);
    }

    private static function join(string ...$parts): string
    {
        $parts = array_values(array_filter(
            array_map(static fn (string $part): string => trim($part, '/'), $parts),
            static fn (string $part): bool => $part !== ''
        ));

        return '/' . implode('/', $parts);
    }
}
