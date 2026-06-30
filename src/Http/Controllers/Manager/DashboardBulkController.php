<?php

namespace Webbycrown\McpDashboardStudio\Http\Controllers\Manager;

use Webbycrown\McpDashboardStudio\Http\Controllers\Controller;
use Webbycrown\McpDashboardStudio\Models\McpDashboardAuditLog;
use Webbycrown\McpDashboardStudio\Models\McpDashboardDefinition;
use Webbycrown\McpDashboardStudio\Services\AuditLogger;
use Webbycrown\McpDashboardStudio\Services\DashboardAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DashboardBulkController extends Controller
{
    /** POST /mcp-manager/dashboards/bulk */
    public function handle(Request $request): RedirectResponse
    {
        $request->validate([
            'action' => ['required', 'in:delete,make_public,make_private'],
            'uuids' => ['required', 'array', 'min:1'],
            'uuids.*' => ['string'],
        ]);

        $action = $request->input('action');
        $uuids = $request->input('uuids');

        $dashboards = McpDashboardDefinition::whereIn('uuid', $uuids)->get();

        if ($dashboards->isEmpty()) {
            return back()->with('error', 'No matching dashboards found.');
        }

        // Filter dashboards based on authorization
        $authorizedDashboards = $this->filterAuthorizedDashboards($dashboards, $action);
        
        if ($authorizedDashboards->isEmpty()) {
            return back()->with('error', 'You do not have permission to perform this action on any of the selected dashboards.');
        }

        $count = 0;
        foreach ($authorizedDashboards as $dashboard) {
            match ($action) {
                'delete' => $this->bulkDelete($request, $dashboard),
                'make_public' => $this->bulkStatus($request, $dashboard, 'public'),
                'make_private' => $this->bulkStatus($request, $dashboard, 'private'),
            };
            $count++;
        }

        $labels = [
            'delete' => "{$count} dashboard(s) moved to trash.",
            'make_public' => "{$count} dashboard(s) set to Public.",
            'make_private' => "{$count} dashboard(s) set to Private.",
        ];

        // Log if some dashboards were skipped due to authorization
        if ($count < $dashboards->count()) {
            $skipped = $dashboards->count() - $count;
            Log::info('[MCP Manager] Bulk operation skipped unauthorized dashboards', [
                'action' => $action,
                'total_selected' => $dashboards->count(),
                'authorized_count' => $count,
                'skipped_count' => $skipped,
                'user_id' => Auth::id(),
            ]);
            
            return back()->with('success', $labels[$action] . " ({$skipped} dashboard(s) skipped due to insufficient permissions).");
        }

        return back()->with('success', $labels[$action]);
    }

    private function bulkDelete(Request $request, McpDashboardDefinition $d): void
    {
        AuditLogger::fromRequest($request, $d->id, McpDashboardAuditLog::EVENT_DELETE, [
            'bulk' => true,
        ]);
        $d->delete();
    }

    private function bulkStatus(Request $request, McpDashboardDefinition $d, string $status): void
    {
        $old = $d->status;
        $d->update(['status' => $status]);
        AuditLogger::fromRequest($request, $d->id, McpDashboardAuditLog::EVENT_STATUS_CHANGE, [
            'from' => $old,
            'to' => $status,
            'bulk' => true,
        ]);
    }

    /** POST /mcp-manager/dashboards/validate-bulk */
    public function validate(Request $request): JsonResponse
    {
        $request->validate([
            'uuids' => ['required', 'array', 'min:1'],
            'uuids.*' => ['string', 'uuid'],
        ]);

        $uuids = $request->input('uuids');
        $found = McpDashboardDefinition::whereIn('uuid', $uuids)->count();
        $total = count($uuids);

        if ($found === 0) {
            return response()->json([
                'valid' => false,
                'message' => 'No matching dashboards found.',
            ]);
        }

        if ($found !== $total) {
            $missing = $total - $found;

            return response()->json([
                'valid' => false,
                'message' => "{$missing} dashboard(s) were not found or may have been deleted.",
            ]);
        }

        return response()->json([
            'valid' => true,
            'message' => 'All selected dashboards are valid.',
            'count' => $found,
        ]);
    }

    // 
    //  Authorization Helpers
    // 
    
    /**
     * Filter dashboards based on authorization for the given action.
     *
     * @param \Illuminate\Database\Eloquent\Collection $dashboards
     * @param string $action
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function filterAuthorizedDashboards($dashboards, string $action)
    {
        try {
            // Check if authorization is enabled
            $authEnabled = config('mcp-dashboard-studio.authorization.enabled', true);
            
            if (!$authEnabled) {
                return $dashboards; // Authorization disabled, allow all
            }

            $user = Auth::user();
            if (!$user) {
                return collect(); // No user, no access
            }

            $authService = app(DashboardAuthorizationService::class);
            
            return $dashboards->filter(function ($dashboard) use ($user, $authService, $action) {
                switch ($action) {
                    case 'delete':
                        return $authService->canDeleteDashboard($user, $dashboard);
                    case 'make_public':
                    case 'make_private':
                        // Status changes require edit permission
                        return $authService->canEditDashboard($user, $dashboard);
                    default:
                        return false;
                }
            });

        } catch (\Throwable $e) {
            Log::error('[MCP Manager] Error filtering authorized dashboards for bulk operation', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            // Fail open - return empty collection on error
            return collect();
        }
    }
}