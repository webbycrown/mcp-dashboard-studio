<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services\ComponentGenerators;

use Illuminate\Support\Str;

class ChartGenerator implements ComponentGeneratorInterface
{
    public function canHandle(array $hint): bool
    {
        return ($hint['type'] ?? '') === 'chart';
    }

    public function generate(array $hint, array $context = []): array
    {
        $id = 'chart_' . Str::random(8);
        return [
            'id' => $id,
            'type' => 'chart',
            'title' => $hint['label'] ?? $hint['title'] ?? 'Chart',
            'chartType' => $hint['chartType'] ?? 'line',
            'options' => $hint['options'] ?? [],
            'series' => $hint['series'] ?? [],
            'data' => [
                'provider'    => 'database',
                'table'       => $hint['table'] ?? null,
                'group_by'    => $hint['group_by'] ?? null,
                'x_column'    => $hint['x_column'] ?? null,
                'x_group_by'  => $hint['x_group_by'] ?? null,
                'y_column'    => $hint['y_column'] ?? null,
                'y_aggregate' => $hint['y_aggregate'] ?? null,
                'aggregate'   => $hint['aggregate'] ?? 'COUNT',
                'source'      => $hint['source'] ?? 'dynamic',
                'query'       => $hint['query'] ?? null,
            ],
            'meta' => $hint['meta'] ?? [],
        ];
    }
}
