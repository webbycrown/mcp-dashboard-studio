<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services\ComponentGenerators;

use Illuminate\Support\Str;

class KpiGenerator implements ComponentGeneratorInterface
{
    public function canHandle(array $hint): bool
    {
        return ($hint['type'] ?? '') === 'kpi';
    }

    public function generate(array $hint, array $context = []): array
    {
        $id = 'kpi_' . Str::random(8);
        return [
            'id' => $id,
            'type' => 'kpi',
            'title' => $hint['label'] ?? $hint['title'] ?? 'KPI',
            'value' => $hint['value'] ?? null,
            'format' => $hint['format'] ?? $hint['unit'] ?? 'number',
            'meta' => $hint['meta'] ?? [],
            'data' => [
                'provider'  => 'database',
                'metric'    => $hint['aggregate'] ?? null,
                'table'     => $hint['table'] ?? null,
                'column'    => $hint['column'] ?? '*',
                'source'    => $hint['source'] ?? 'dynamic',
                'query'     => $hint['query'] ?? null,
            ],
        ];
    }
}
