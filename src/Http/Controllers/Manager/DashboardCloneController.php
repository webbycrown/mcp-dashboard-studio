<?php

namespace Webbycrown\McpDashboardStudio\Http\Controllers\Manager;

use Webbycrown\McpDashboardStudio\Http\Controllers\Controller;
use Webbycrown\McpDashboardStudio\Models\McpDashboardDefinition;
use Webbycrown\McpDashboardStudio\Models\McpDashboardAuditLog;
use Webbycrown\McpDashboardStudio\Services\AuditLogger;
use Webbycrown\McpDashboardStudio\Services\DashboardAuthorizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DashboardCloneController extends Controller
{
    /** POST /mcp-manager/dashboards/{uuid}/clone */
    public function clone(Request $request, string $uuid): RedirectResponse
    {
        $source = McpDashboardDefinition::where('uuid', $uuid)->firstOrFail();

        // Check authorization - user must be able to view the source dashboard
        $this->authorizeClone($source);

        try {
            // Generate a unique slug for the clone
            $baseSlug = $source->slug . '-copy';
            $slug     = $baseSlug;
            $attempt  = 1;

            while (McpDashboardDefinition::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $attempt++;
            }

            $clone = McpDashboardDefinition::create([
                'uuid'        => (string) Str::uuid(),
                'name'        => $source->name . ' (Copy)',
                'slug'        => $slug,
                'prompt'      => $source->prompt,
                'layout_json' => $source->layout_json,
                'status'      => McpDashboardDefinition::STATUS_PRIVATE,
                'version'     => $source->version,
                'description' => $source->description,
                'created_by'  => Auth::id(),
            ]);

            AuditLogger::fromRequest($request, $source->id, McpDashboardAuditLog::EVENT_CLONE, [
                'cloned_to_uuid' => $clone->uuid,
                'cloned_to_slug' => $clone->slug,
                'user_id' => Auth::id(),
            ]);
            AuditLogger::fromRequest($request, $clone->id, McpDashboardAuditLog::EVENT_IMPORT, [
                'cloned_from_uuid' => $source->uuid,
                'cloned_from_slug' => $source->slug,
                'user_id' => Auth::id(),
            ]);

            Log::info('[MCP Manager] Dashboard cloned.', [
                'source_uuid' => $source->uuid,
                'clone_uuid' => $clone->uuid,
                'user_id' => Auth::id(),
            ]);

            return redirect()->route('mcp.manager.dashboards.edit', $clone->uuid)
                ->with('success', "Dashboard cloned as \"{$clone->name}\". It is set to Private — edit and publish when ready.");

        } catch (\Throwable $e) {
            Log::error('[MCP Manager] Failed to clone dashboard.', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return back()->with('error', 'Failed to clone dashboard. Please try again.');
        }
    }

    // 
    //  Authorization Helpers
    // 
    
    /**
     * Check if the current user can clone the dashboard.
     * User must be able to view the source dashboard.
     * Aborts with 403 if not authorized.
     *
     * @param McpDashboardDefinition $dashboard
     * @return void
     */
    protected function authorizeClone(McpDashboardDefinition $dashboard): void
    {
        try {
            // Check if authorization is enabled
            $authEnabled = config('mcp-dashboard-studio.authorization.enabled', true);
            
            if (!$authEnabled) {
                return; // Authorization disabled, allow access
            }

            $user = Auth::user();
            if (!$user) {
                abort(403, 'You must be logged in to clone dashboards.');
            }

            $authService = app(DashboardAuthorizationService::class);
            
            // User must be able to view the dashboard to clone it
            if (!$authService->canViewDashboard($user, $dashboard)) {
                Log::warning('[MCP Manager] Unauthorized clone attempt', [
                    'user_id' => $user->id,
                    'dashboard_id' => $dashboard->id,
                    'dashboard_uuid' => $dashboard->uuid,
                    'ip' => request()->ip(),
                ]);

                abort(403, 'You do not have permission to clone this dashboard.');
            }

        } catch (\Throwable $e) {
            Log::error('[MCP Manager] Error checking clone authorization', [
                'dashboard_id' => $dashboard->id,
                'error' => $e->getMessage(),
            ]);

            // Fail open - don't break the application due to auth errors
            abort(500, 'Unable to verify authorization. Please try again.');
        }
    }
}