<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services\Database;

use Illuminate\Support\Str;

/**
 * EntityDiscoveryService
 *
 * Identifies entity types from table/column structure.
 * Provides metadata hints for metric generation without domain classification.
 *
 * Entity types (structural, not business-domain):
 *   - people:       tables with name, email, phone columns
 *   - product:      tables with price, sku, stock columns
 *   - transaction:  tables with amount, total, quantity, date columns
 *   - event:        tables with date, time, status columns
 *   - content:      tables with title, body, slug columns
 *
 * This is metadata enrichment — never classifies the entire database.
 */
class EntityDiscoveryService
{
    /**
     * Discover entity types for each business table.
     *
     * @param  array  $schema  Full schema from DatabaseSchemaExplorer
     * @param  string[]  $businessTables  Tables to analyze (framework filtered)
     * @return array<string, array{type: string, signals: string[]}>
     */
    public function discover(array $schema, array $businessTables): array
    {
        $entities = [];

        foreach ($businessTables as $table) {
            $definition = $schema[$table] ?? [];
            $columns    = $definition['columns'] ?? [];
            $colNames   = array_map(fn($c) => strtolower($c['name'] ?? ''), $columns);

            $type    = $this->classifyEntity($table, $colNames, $columns);
            $signals = $this->collectSignals($table, $colNames);

            $entities[$table] = [
                'type'    => $type,
                'signals' => $signals,
            ];
        }

        return $entities;
    }

    /**
     * Get tables grouped by entity type.
     */
    public function groupByType(array $schema, array $businessTables): array
    {
        $entities = $this->discover($schema, $businessTables);

        $groups = [];
        foreach ($entities as $table => $info) {
            $groups[$info['type']][] = $table;
        }

        return $groups;
    }

    // =========================================================================
    // Classification
    // =========================================================================

    protected function classifyEntity(string $table, array $colNames, array $columns): string
    {
        $scores = [
            'people'      => $this->scorePeople($colNames),
            'product'     => $this->scoreProduct($colNames),
            'transaction' => $this->scoreTransaction($colNames),
            'event'       => $this->scoreEvent($colNames),
            'content'     => $this->scoreContent($colNames),
        ];

        arsort($scores);
        $top = array_key_first($scores);

        return $scores[$top] > 0 ? $top : 'data';
    }

    protected function scorePeople(array $cols): int
    {
        $signals = ['name', 'first_name', 'last_name', 'email', 'phone',
                     'address', 'birth_date', 'gender', 'avatar'];
        return count(array_intersect($cols, $signals));
    }

    protected function scoreProduct(array $cols): int
    {
        $signals = ['price', 'sku', 'stock', 'cost', 'weight',
                     'product_code', 'product_name', 'unit_price',
                     'selling_price', 'cost_price', 'barcode'];
        return count(array_intersect($cols, $signals));
    }

    protected function scoreTransaction(array $cols): int
    {
        $signals = ['amount', 'total', 'quantity', 'subtotal', 'tax',
                     'discount', 'payment_method', 'transaction_id',
                     'invoice_number', 'receipt_number'];
        return count(array_intersect($cols, $signals));
    }

    protected function scoreEvent(array $cols): int
    {
        $signals = ['date', 'time', 'start_time', 'end_time', 'duration',
                     'event_date', 'scheduled_at', 'completed_at',
                     'date_from', 'date_to'];
        return count(array_intersect($cols, $signals));
    }

    protected function scoreContent(array $cols): int
    {
        $signals = ['title', 'body', 'content', 'slug', 'excerpt',
                     'author', 'published_at', 'description'];
        return count(array_intersect($cols, $signals));
    }

    protected function collectSignals(string $table, array $colNames): array
    {
        $signals = [];

        if (in_array('name', $colNames) || in_array('first_name', $colNames))
            $signals[] = 'has_name';
        if (in_array('email', $colNames))
            $signals[] = 'has_email';
        if (in_array('status', $colNames))
            $signals[] = 'has_status';
        if (in_array('created_at', $colNames))
            $signals[] = 'has_timestamps';
        if (in_array('price', $colNames) || in_array('amount', $colNames) || in_array('total', $colNames))
            $signals[] = 'has_monetary';

        // Check for category/type FK
        foreach ($colNames as $col) {
            if (str_ends_with($col, '_id') && $col !== 'id') {
                $signals[] = 'has_fk:' . $col;
            }
        }

        return $signals;
    }
}
