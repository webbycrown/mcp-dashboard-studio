<?php

namespace Webbycrown\McpDashboardStudio\Http\Controllers\Manager;

use Webbycrown\McpDashboardStudio\Http\Controllers\Controller;
use Webbycrown\McpDashboardStudio\Models\McpDashboardAuditLog;
use Webbycrown\McpDashboardStudio\Models\McpDashboardDefinition;
use Illuminate\Http\Request;

class DashboardAuditController extends Controller
{
    /** GET /mcp-manager/dashboards/{uuid}/audit */
    public function index(Request $request, string $uuid)
    {
        $dashboard = McpDashboardDefinition::withTrashed()
            ->where('uuid', $uuid)
            ->firstOrFail();

        $sort = $request->query('sort', 'created_at');
        $dir  = $request->query('dir', 'desc');
        $q    = trim((string) $request->query('q', ''));

        $allowedSorts = ['created_at', 'event', 'actor_type', 'actor_email', 'ip_address'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'created_at';
        }

        if (!in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'desc';
        }

        $query = McpDashboardAuditLog::where('dashboard_id', $dashboard->id);

        if ($q !== '') {
            $query->where(function ($query) use ($q) {
                $query->where('event', 'like', "%{$q}%")
                    ->orWhere('actor_email', 'like', "%{$q}%")
                    ->orWhere('ip_address', 'like', "%{$q}%");
            });
        }

        $logs = $query->orderBy($sort, $dir)
            ->paginate(50)
            ->appends($request->query());

        return view('mcp-dashboard-studio::manager.audit', compact('dashboard', 'logs', 'sort', 'dir', 'q'));
    }

    /** POST /mcp-manager/dashboards/{uuid}/audit/bulk */
    public function bulk(Request $request, string $uuid)
    {
        $dashboard = McpDashboardDefinition::withTrashed()
            ->where('uuid', $uuid)
            ->firstOrFail();

        $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:mcp_dashboard_audit_logs,id'],
        ]);

        $ids = $request->input('ids');

        $logs = McpDashboardAuditLog::where('dashboard_id', $dashboard->id)
            ->whereIn('id', $ids)
            ->get();

        if ($logs->isEmpty()) {
            return back()->with('error', 'No matching audit logs found.');
        }

        $count = 0;
        foreach ($logs as $log) {
            $log->delete();
            $count++;
        }

        return back()->with('success', "{$count} audit log(s) deleted permanently.");
    }
}
