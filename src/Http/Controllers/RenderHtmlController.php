<?php

namespace Webbycrown\McpDashboardStudio\Http\Controllers;

use Webbycrown\McpDashboardStudio\Models\McpDashboardDefinition;

class RenderHtmlController extends Controller
{
    public function html(string $slug)
    {
        $definition = McpDashboardDefinition::where('slug', $slug)->firstOrFail();
        $layout = $definition->layout_json;

        $charts = [];
        foreach ($layout['components'] ?? [] as $component) {
            if (str_contains($component['type'] ?? '', 'chart')) {
                $charts[] = array_merge($component['data'] ?? [], [
                    'id' => $component['id'],
                    'chartType' => $component['data']['chartType'] ?? str_replace('_chart', '', $component['type']),
                ]);
            }
        }

        return view('mcp-dashboard-studio::dashboard-studio.index', compact('layout', 'charts'))->render();
    }
}
