<?php

namespace Webbycrown\McpDashboardStudio\Http\Controllers\Manager;

use Webbycrown\McpDashboardStudio\Http\Controllers\Controller;
use Webbycrown\McpDashboardStudio\Models\McpDashboardDefinition;
use Webbycrown\McpDashboardStudio\Services\DashboardAuthorizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * DashboardDeleteController
 *
 * Soft-deletes a dashboard via DELETE /mcp-manager/dashboards/{uuid}.
 * Uses the SoftDeletes trait on McpDashboardDefinition — the row is
 * preserved in the DB with deleted_at set, never truly removed.
 * Includes ownership-based authorization checks.
 */
class DashboardDeleteController extends Controller
{
    /**
     * DELETE /mcp-manager/dashboards/{uuid}
     *
     * Soft-delete the specified dashboard.
     *
     * @return RedirectResponse
     */
    public function destroy(Request $request, string $uuid): RedirectResponse
    {
        $dashboard = McpDashboardDefinition::where('uuid', $uuid)->first();

        if (! $dashboard) {
            abort(404, "Dashboard not found (UUID: {$uuid}).");
        }

        // Check authorization
        $this->authorizeDelete($dashboard);

        try {
            $name = $dashboard->name;
            $dashboard->delete(); // SoftDeletes — sets deleted_at

            Log::info('[MCP Manager] Dashboard soft-deleted.', [
                'uuid' => $uuid,
                'name' => $name,
                'user_id' => Auth::id(),
            ]);

        } catch (\Throwable $e) {
            Log::error('[MCP Manager] Failed to delete dashboard.', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return back()->with('error', 'Failed to delete dashboard. Please try again.');
        }

        return redirect()
            ->route('mcp.manager.dashboards.index')
            ->with('success', "Dashboard \"{$name}\" was deleted.");
    }

    // 
    //  Authorization Helpers
    // 
    
    /**
     * Check if the current user can delete the dashboard.
     * Aborts with 403 if not authorized.
     *
     * @param McpDashboardDefinition $dashboard
     * @return void
     */
    protected function authorizeDelete(McpDashboardDefinition $dashboard): void
    {
        try {
            // Check if authorization is enabled
            $authEnabled = config('mcp-dashboard-studio.authorization.enabled', true);
            
            if (!$authEnabled) {
                return; // Authorization disabled, allow access
            }

            $user = Auth::user();
            if (!$user) {
                abort(403, 'You must be logged in to delete dashboards.');
            }

            $authService = app(DashboardAuthorizationService::class);
            
            if (!$authService->canDeleteDashboard($user, $dashboard)) {
                Log::warning('[MCP Manager] Unauthorized delete attempt', [
                    'user_id' => $user->id,
                    'dashboard_id' => $dashboard->id,
                    'dashboard_uuid' => $dashboard->uuid,
                    'ip' => request()->ip(),
                ]);

                abort(403, 'You do not have permission to delete this dashboard.');
            }

        } catch (\Throwable $e) {
            Log::error('[MCP Manager] Error checking delete authorization', [
                'dashboard_id' => $dashboard->id,
                'error' => $e->getMessage(),
            ]);

            // Fail open - don't break the application due to auth errors
            abort(500, 'Unable to verify authorization. Please try again.');
        }
    }
}