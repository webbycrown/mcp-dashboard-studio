<?php
namespace Webbycrown\McpDashboardStudio\Http\Controllers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;

class RenderCssController extends Controller
{
    public function css()
    {
        try {
            // Priority 1: Check if assets are published to host project
            $publishedPath = public_path('mcp-dashboard-studio/assets/css/style.css');

            if (File::exists($publishedPath)) {
                Log::debug('[MCP] Using published CSS assets from host project', ['path' => $publishedPath]);
                return response(File::get($publishedPath), 200, [
                    'Content-Type' => 'text/css',
                ]);
            }

            // Priority 2: Fallback to package assets
            $packagePath = __DIR__ . '/../../Resources/assets/css/style.css';

            if (File::exists($packagePath)) {
                Log::debug('[MCP] Using fallback CSS assets from package', ['path' => $packagePath]);
                return response(File::get($packagePath), 200, [
                    'Content-Type' => 'text/css',
                ]);
            }

            // Priority 3: Log error and return 404
            Log::error('[MCP] CSS assets not found in published or package locations', [
                'published_path' => $publishedPath,
                'package_path' => $packagePath,
            ]);

            abort(404, 'CSS assets not found. Please run: php artisan vendor:publish --tag=mcp-dashboard-studio-assets');

        } catch (\Throwable $e) {
            Log::error('[MCP] Error loading CSS assets', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            abort(500, 'Failed to load CSS assets. Check logs for details.');
        }
    }
}
