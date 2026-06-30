<?php

namespace Webbycrown\McpDashboardStudio\Http\Controllers\Manager;

use Webbycrown\McpDashboardStudio\Http\Controllers\Controller;
use Webbycrown\McpDashboardStudio\Models\McpDashboardDefinition;
use Webbycrown\McpDashboardStudio\Services\DashboardAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DashboardManagerController extends Controller
{
    /**
     * GET /mcp-manager/dashboards
     *
     * Enhanced listing with sort, search, status filter, date range, and version filter.
     * Uses DataTables client-side for sorting/pagination within filtered results.
     * Now includes ownership-based authorization.
     */
    public function index(Request $request)
    {
        try {
            // Check if authorization is enabled in config
            $authEnabled = config('mcp-dashboard-studio.authorization.enabled', true);
            $user = Auth::user();

            // 
            //  Filter inputs
            // 
            
            $search   = trim($request->query('q', ''));
            $status   = $request->query('status', '');
            $sort     = $request->query('sort', 'created_at');
            $dir      = $request->query('dir', 'desc') === 'asc' ? 'asc' : 'desc';
            $dateFrom = $request->query('date_from', '');
            $dateTo   = $request->query('date_to', '');
            $version  = $request->query('version', '');

            // Whitelist sortable columns
            $allowedSorts = ['name', 'status', 'view_count', 'created_at', 'last_viewed_at', 'version'];
            if (! in_array($sort, $allowedSorts)) {
                $sort = 'created_at';
            }

            // 
            //  Build query with authorization filter
            // 
            
            if ($authEnabled && $user) {
                // Use authorization service to get accessible dashboards
                $query = app(DashboardAuthorizationService::class)->getAccessibleDashboards($user);
                
                Log::debug('[MCP Manager] Using authorization filter for dashboard list', [
                    'user_id' => $user->id,
                ]);
            } else {
                // Fallback to all dashboards if auth disabled or no user
                $query = McpDashboardDefinition::query();
                
                if ($authEnabled && !$user) {
                    Log::warning('[MCP Manager] Authorization enabled but no authenticated user', [
                        'ip' => $request->ip(),
                    ]);
                }
            }

            // 
            //  Apply additional filters
            // 
            
            if (in_array($status, [
                McpDashboardDefinition::STATUS_PUBLIC,
                McpDashboardDefinition::STATUS_PRIVATE,
            ])) {
                $query->where('status', $status);
            }

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                      ->orWhere('slug', 'LIKE', "%{$search}%");
                });
            }

            if ($dateFrom !== '') {
                $query->whereDate('created_at', '>=', $dateFrom);
            }

            if ($dateTo !== '') {
                $query->whereDate('created_at', '<=', $dateTo);
            }

            if ($version !== '') {
                $query->where('version', $version);
            }

            $query->orderBy($sort, $dir);

            // Load all matching rows — DataTables handles client-side pagination
            $dashboards = $query->get()->map(function (McpDashboardDefinition $d) {
                $d->component_count = count($d->layout_json['components'] ?? []);
                return $d;
            });

            // 
            //  Stats (always unfiltered totals for admin view)
            // 
            
            if ($authEnabled && $user && app(DashboardAuthorizationService::class)->canViewAllDashboards($user)) {
                // Admin sees global stats
                $stats = [
                    'total'       => McpDashboardDefinition::count(),
                    'public'      => McpDashboardDefinition::where('status', McpDashboardDefinition::STATUS_PUBLIC)->count(),
                    'private'     => McpDashboardDefinition::where('status', McpDashboardDefinition::STATUS_PRIVATE)->count(),
                    'trash'       => McpDashboardDefinition::onlyTrashed()->count(),
                    'total_views' => McpDashboardDefinition::sum('view_count'),
                ];
            } else {
                // Regular users see their own stats
                $stats = [
                    'total'       => $dashboards->count(),
                    'public'      => $dashboards->where('status', McpDashboardDefinition::STATUS_PUBLIC)->count(),
                    'private'     => $dashboards->where('status', McpDashboardDefinition::STATUS_PRIVATE)->count(),
                    'trash'       => 0, // Not shown for non-admin
                    'total_views' => $dashboards->sum('view_count'),
                ];
            }

            // Available versions for the version dropdown filter
            $versions = McpDashboardDefinition::distinct()
                ->orderBy('version')
                ->pluck('version')
                ->filter()
                ->values();

        } catch (\Throwable $e) {
            Log::error('[MCP Manager] Failed to load dashboard list.', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            abort(500, 'Unable to load dashboard list. Please check the application logs.');
        }

        return view('mcp-dashboard-studio::manager.index', compact(
            'dashboards', 'stats', 'versions',
            'search', 'status', 'sort', 'dir',
            'dateFrom', 'dateTo', 'version'
        ));
    }
}