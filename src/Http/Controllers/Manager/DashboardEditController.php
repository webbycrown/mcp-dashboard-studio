<?php

namespace Webbycrown\McpDashboardStudio\Http\Controllers\Manager;

use Webbycrown\McpDashboardStudio\Http\Controllers\Controller;
use Webbycrown\McpDashboardStudio\Http\Requests\Manager\UpdateDashboardRequest;
use Webbycrown\McpDashboardStudio\Models\McpDashboardAuditLog;
use Webbycrown\McpDashboardStudio\Models\McpDashboardDefinition;
use Webbycrown\McpDashboardStudio\Services\AuditLogger;
use Webbycrown\McpDashboardStudio\Services\DashboardAuthorizationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * DashboardEditController
 *
 * Handles edit form display and saving changes to a dashboard.
 * Editable fields: name, description, status.
 * Includes ownership-based authorization checks.
 */
class DashboardEditController extends Controller
{
    /**
     * GET /mcp-manager/dashboards/{uuid}/edit
     *
     * Show the edit form for a dashboard.
     *
     * @return \Illuminate\View\View
     */
    public function edit(string $uuid)
    {
        $dashboard = $this->findOrFail($uuid);

        // Check authorization
        $this->authorizeEdit($dashboard);

        return view('mcp-dashboard-studio::manager.edit', compact('dashboard'));
    }

    /**
     * PATCH /mcp-manager/dashboards/{uuid}
     *
     * Save edits to name, description, and status.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(UpdateDashboardRequest $request, string $uuid)
    {
        $dashboard = $this->findOrFail($uuid);

        // Check authorization
        $this->authorizeEdit($dashboard);

        try {
            // Decode JSON sections
            $meta = json_decode($request->input('layout_meta'), true);
            $layout = json_decode($request->input('layout_layout'), true);
            $components = json_decode($request->input('layout_components'), true);

            // Validate JSON
            foreach (
                [
                    'layout_meta' => $meta,
                    'layout_layout' => $layout,
                    'layout_components' => $components,
                ] as $field => $value
            ) {

                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($value)) {
                    return back()
                        ->withErrors([
                            $field => 'Invalid JSON: ' . json_last_error_msg(),
                        ])
                        ->withInput();
                }
            }

            // Existing JSON
            $layoutJson = is_array($dashboard->layout_json)
                ? $dashboard->layout_json
                : json_decode($dashboard->layout_json, true);

            // Preserve other keys and update edited ones
            $layoutJson['title'] = $request->validated('name');
            $layoutJson['description'] = $request->validated('description');
            $layoutJson['meta'] = $meta;
            $layoutJson['layout'] = $layout;
            $layoutJson['components'] = $components;

            $old = [
                'name' => $dashboard->name,
                'description' => $dashboard->description,
                'status' => $dashboard->status,
                'layout_json' => $dashboard->layout_json,
            ];

            $dashboard->update([
                'name' => $request->validated('name'),
                'description' => $request->validated('description'),
                'status' => $request->validated('status'),
                'layout_json' => $layoutJson,
            ]);

            $new = [
                'name' => $dashboard->name,
                'description' => $dashboard->description,
                'status' => $dashboard->status,
                'layout_json' => $dashboard->layout_json,
            ];

            $event = $old['status'] !== $new['status']
                ? McpDashboardAuditLog::EVENT_STATUS_CHANGE
                : McpDashboardAuditLog::EVENT_EDIT;

            AuditLogger::fromRequest($request, $dashboard->id, $event, [
                'before' => $old,
                'after' => $new,
            ]);

            Log::info('[MCP Manager] Dashboard updated.', [
                'uuid' => $dashboard->uuid,
                'name' => $dashboard->name,
                'status' => $dashboard->status,
                'user_id' => Auth::id(),
            ]);
        } catch (\Throwable $e) {

            Log::error('[MCP Manager] Failed to update dashboard.', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id(),
            ]);

            return back()
                ->withInput()
                ->with('error', 'Failed to save dashboard.');
        }

        return redirect()
            ->route('mcp.manager.dashboards.index')
            ->with('success', "Dashboard \"{$dashboard->name}\" updated successfully.");
    }

    // 
    //  Authorization Helpers
    // 
    
    /**
     * Check if the current user can edit the dashboard.
     * Aborts with 403 if not authorized.
     *
     * @param McpDashboardDefinition $dashboard
     * @return void
     */
    protected function authorizeEdit(McpDashboardDefinition $dashboard): void
    {
        try {
            // Check if authorization is enabled
            $authEnabled = config('mcp-dashboard-studio.authorization.enabled', true);
            
            if (!$authEnabled) {
                return; // Authorization disabled, allow access
            }

            $user = Auth::user();
            if (!$user) {
                abort(403, 'You must be logged in to edit dashboards.');
            }

            $authService = app(DashboardAuthorizationService::class);
            
            if (!$authService->canEditDashboard($user, $dashboard)) {
                Log::warning('[MCP Manager] Unauthorized edit attempt', [
                    'user_id' => $user->id,
                    'dashboard_id' => $dashboard->id,
                    'dashboard_uuid' => $dashboard->uuid,
                    'ip' => request()->ip(),
                ]);

                abort(403, 'You do not have permission to edit this dashboard.');
            }

        } catch (\Throwable $e) {
            Log::error('[MCP Manager] Error checking edit authorization', [
                'dashboard_id' => $dashboard->id,
                'error' => $e->getMessage(),
            ]);

            // Fail open - don't break the application due to auth errors
            abort(500, 'Unable to verify authorization. Please try again.');
        }
    }

    // 
    //  Helpers
    // 
    
    /**
     * Find a dashboard by UUID or abort 404.
     */
    private function findOrFail(string $uuid): McpDashboardDefinition
    {
        $dashboard = McpDashboardDefinition::where('uuid', $uuid)->first();

        if (! $dashboard) {
            abort(404, "Dashboard not found (UUID: {$uuid}).");
        }

        return $dashboard;
    }
}