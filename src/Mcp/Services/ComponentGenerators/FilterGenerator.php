<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services\ComponentGenerators;

use Illuminate\Support\Str;

class FilterGenerator implements ComponentGeneratorInterface
{
    public function canHandle(array $hint): bool
    {
        return ($hint['type'] ?? '') === 'filter';
    }

    public function generate(array $hint, array $context = []): array
    {
        $id = 'filter_' . Str::random(8);

        return [
            'id' => $id,
            'type' => 'filter',
            'title' => $hint['title'] ?? $hint['label'] ?? 'Filter',
            'field' => $hint['column'] ?? $hint['field'] ?? $hint['label'] ?? 'filter',
            'control' => $hint['control'] ?? ($hint['filterType'] ?? 'select'),
            'options' => $hint['options'] ?? [],
            'data' => [
                'provider' => 'database',
                'table'    => $hint['table'] ?? null,
                'column'   => $hint['column'] ?? null,
            ],
            'meta' => $hint['meta'] ?? [],
        ];
    }
}
