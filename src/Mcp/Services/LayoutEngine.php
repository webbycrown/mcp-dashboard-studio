<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services;

use Webbycrown\McpDashboardStudio\Mcp\DTO\DashboardSpec;

class LayoutEngine
{
    public function applyLayout(DashboardSpec $spec): DashboardSpec
    {
        $layout = [];
        $y = 0;

        if (count($spec->filters) > 0) {
            $layout = array_merge($layout, $this->layoutRow($spec->filters, 'filter', 12, 2, $y));
            $y += 2;
        }

        if (count($spec->kpis) > 0) {
            $layout = array_merge($layout, $this->layoutRow($spec->kpis, 'kpi', 12, 2, $y));
            $y += 2;
        }

        if (count($spec->charts) > 0) {
            $layout = array_merge($layout, $this->layoutRow($spec->charts, 'chart', 12, 5, $y));
            $y += 5;
        }

        if (count($spec->tables) > 0) {
            $layout = array_merge($layout, $this->layoutRow($spec->tables, 'table', 12, 6, $y));
            $y += 6;
        }

        $spec->layout = $layout;

        return $spec;
    }

    /**
     * Calculate optimal grid width per component type.
     *
     * Rules:
     *   - KPIs: up to 4 per row (span 3 each), wraps if more
     *   - Charts: up to 3 per row (span 4 each), wraps if more
     *   - Tables: up to 2 per row (span 6 each), wraps if more
     *   - Filters: always full width (span 12)
     */
    protected function layoutRow(array $components, string $type, int $columns, int $height, int $rowIndex): array
    {
        $layout = [];
        $count = count($components);

        $maxPerRow = match ($type) {
            'kpi'    => 4,
            'chart'  => 3,
            'table'  => 2,
            'filter' => 1,
            default  => 3,
        };

        $perRow = min($count, $maxPerRow);
        $width = intdiv($columns, max($perRow, 1));

        $x = 0;

        foreach ($components as $component) {
            $layout[] = [
                'id'   => $component['id'] ?? null,
                'type' => $component['type'] ?? 'widget',
                'x'    => $x,
                'y'    => $rowIndex,
                'w'    => $width,
                'h'    => $height,
            ];

            $x += $width;

            if ($x >= $columns) {
                $x = 0;
                $rowIndex += $height;
            }
        }

        return $layout;
    }
}
