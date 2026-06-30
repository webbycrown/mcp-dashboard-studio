<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services\ComponentGenerators;

use Illuminate\Support\Str;

class TableGenerator implements ComponentGeneratorInterface
{
    public function canHandle(array $hint): bool
    {
        return ($hint['type'] ?? '') === 'table';
    }

    public function generate(array $hint, array $context = []): array
    {
        $id = 'table_' . Str::random(8);
        return [
            'id' => $id,
            'type' => 'table',
            'title' => $hint['title'] ?? $hint['label'] ?? 'Table',
            'columns' => $hint['columns'] ?? [],
            'data' => array_merge([
                'provider' => 'database',
                'table'    => $hint['table'] ?? null,
                'source'   => $hint['source'] ?? 'dynamic',
                'query'    => $hint['query'] ?? null,
                'sort'     => $hint['default_sort'] ?? null,
                'order'    => $hint['default_order'] ?? 'desc',
            ], $hint['data'] ?? []),
            'meta' => $hint['meta'] ?? [],
        ];
    }
}
