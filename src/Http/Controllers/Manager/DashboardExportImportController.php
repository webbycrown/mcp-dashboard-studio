<?php

namespace Webbycrown\McpDashboardStudio\Http\Controllers\Manager;

use Webbycrown\McpDashboardStudio\Http\Controllers\Controller;
use Webbycrown\McpDashboardStudio\Models\McpDashboardAuditLog;
use Webbycrown\McpDashboardStudio\Models\McpDashboardDefinition;
use Webbycrown\McpDashboardStudio\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DashboardExportImportController extends Controller
{
    /** GET /mcp-manager/dashboards/{uuid}/export */
    public function export(Request $request, string $uuid)
    {
        $dashboard = McpDashboardDefinition::where('uuid', $uuid)->firstOrFail();

        AuditLogger::fromRequest($request, $dashboard->id, McpDashboardAuditLog::EVENT_EXPORT);

        $payload = [
            '_mcp_export_version' => '1.0',
            'exported_at'         => now()->toIso8601String(),
            'uuid'                => $dashboard->uuid,
            'name'                => $dashboard->name,
            'slug'                => $dashboard->slug,
            'status'              => $dashboard->status,
            'version'             => $dashboard->version,
            'description'         => $dashboard->description,
            'prompt'              => $dashboard->prompt,
            'layout_json'         => $dashboard->layout_json,
        ];

        $filename = 'dashboard-' . $dashboard->slug . '-' . now()->format('Ymd-His') . '.json';

        return response()->json($payload, 200, [
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Content-Type'        => 'application/json',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /** POST /mcp-manager/dashboards/import */
    public function import(Request $request)
    {
        $request->validate([
            'json_file' => ['required', 'file', 'mimes:json,txt', 'max:5120'],
        ]);

        $content = file_get_contents($request->file('json_file')->getRealPath());
        $data    = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
            return back()->with('error', 'Invalid JSON file. Please upload a valid dashboard export.');
        }

        $required = ['name', 'slug', 'layout_json', 'prompt'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return back()->with('error', "Missing required field: {$field}");
            }
        }

        // Generate a unique slug
        $baseSlug = Str::slug($data['slug']);
        $slug     = $baseSlug;
        $attempt  = 1;
        while (McpDashboardDefinition::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $attempt++;
        }

        $dashboard = McpDashboardDefinition::create([
            'uuid'        => (string) Str::uuid(),
            'name'        => $data['name'],
            'slug'        => $slug,
            'prompt'      => $data['prompt'],
            'layout_json' => $data['layout_json'],
            'status'      => McpDashboardDefinition::STATUS_PRIVATE,
            'version'     => $data['version'] ?? 1,
            'description' => $data['description'] ?? null,
        ]);

        AuditLogger::fromRequest($request, $dashboard->id, McpDashboardAuditLog::EVENT_IMPORT, [
            'original_slug' => $data['slug'],
            'source'        => 'json_upload',
        ]);

        return redirect()->route('mcp.manager.dashboards.edit', $dashboard->uuid)
            ->with('success', "Dashboard \"{$dashboard->name}\" imported successfully. Set to Private — review and publish when ready.");
    }
}
