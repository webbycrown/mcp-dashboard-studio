<?php
namespace Webbycrown\McpDashboardStudio\Http\Controllers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;

class RenderJsController extends Controller
{
    public function js()
    {
        try {
            // Priority 1: Check if assets are published to host project
            $publishedPath = public_path('mcp-dashboard-studio/assets/js/app.js');

            if (File::exists($publishedPath)) {
                Log::debug('[MCP] Using published JS assets from host project', ['path' => $publishedPath]);
                return response(File::get($publishedPath), 200, [
                    'Content-Type' => 'application/javascript',
                ]);
            }

            // Priority 2: Fallback to package assets
            $packagePath = __DIR__ . '/../../Resources/assets/js/app.js';

            if (File::exists($packagePath)) {
                Log::debug('[MCP] Using fallback JS assets from package', ['path' => $packagePath]);
                return response(File::get($packagePath), 200, [
                    'Content-Type' => 'application/javascript',
                ]);
            }

            // Priority 3: Log error and return 404
            Log::error('[MCP] JS assets not found in published or package locations', [
                'published_path' => $publishedPath,
                'package_path' => $packagePath,
            ]);

            abort(404, 'JS assets not found. Please run: php artisan vendor:publish --tag=mcp-dashboard-studio-assets');

        } catch (\Throwable $e) {
            Log::error('[MCP] Error loading JS assets', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            abort(500, 'Failed to load JS assets. Check logs for details.');
        }
    }
}
