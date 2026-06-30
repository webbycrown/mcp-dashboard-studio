<?php

namespace Webbycrown\McpDashboardStudio\Http\Controllers\Manager;

use Webbycrown\McpDashboardStudio\Http\Controllers\Controller;
use Webbycrown\McpDashboardStudio\Models\McpDashboardDefinition;
use Webbycrown\McpDashboardStudio\Models\McpDashboardAuditLog;
use Webbycrown\McpDashboardStudio\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardTrashController extends Controller
{
    /** GET /mcp-manager/dashboards/trash */
    public function index()
    {
        $dashboards = McpDashboardDefinition::onlyTrashed()
            ->latest('deleted_at')
            ->get();

        return view('mcp-dashboard-studio::manager.trash', compact('dashboards'));
    }

    /** POST /mcp-manager/dashboards/{uuid}/restore */
    public function restore(Request $request, string $uuid): RedirectResponse
    {
        $dashboard = McpDashboardDefinition::onlyTrashed()
            ->where('uuid', $uuid)
            ->firstOrFail();

        $dashboard->restore();

        AuditLogger::fromRequest($request, $dashboard->id, McpDashboardAuditLog::EVENT_RESTORE);

        return redirect()->route('mcp.manager.dashboards.index')
            ->with('success', "\"{$dashboard->name}\" has been restored.");
    }

    /** DELETE /mcp-manager/dashboards/{uuid}/purge */
    public function purge(Request $request, string $uuid): RedirectResponse
    {
        $dashboard = McpDashboardDefinition::onlyTrashed()
            ->where('uuid', $uuid)
            ->firstOrFail();

        $name = $dashboard->name;
        $dashboard->forceDelete();

        return redirect()->route('mcp.manager.dashboards.trash')
            ->with('success', "\"{$name}\" has been permanently deleted.");
    }

    /** POST /mcp-manager/dashboards/trash/empty */
    public function empty(Request $request): RedirectResponse
    {
        $count = McpDashboardDefinition::onlyTrashed()->count();
        McpDashboardDefinition::onlyTrashed()->forceDelete();

        return redirect()->route('mcp.manager.dashboards.trash')
            ->with('success', "{$count} dashboard(s) permanently deleted.");
    }
}
