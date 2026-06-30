<?php

namespace Webbycrown\McpDashboardStudio\DataProviders;

/**
 * DataProviderInterface
 *
 * Contract for any data source that can resolve dashboard component values.
 *
 * Each provider receives a raw component descriptor array (KPI, chart, table,
 * or filter) and returns the same array enriched with live data — a populated
 * 'value', 'data', 'rows', or 'options' key depending on the component type.
 *
 * Implementations:
 *   - DatabaseProvider : runs live queries against a DB connection.
 *   - (future)         : ApiProvider, CacheProvider, MockProvider, etc.
 */
interface DataProviderInterface
{
    /**
     * Resolve a single component descriptor to live data.
     *
     * @param  array  $component  A dashboard component descriptor, e.g.:
     *   KPI:    ['title'=>'Total Orders', 'table'=>'orders', 'aggregate'=>'COUNT', 'column'=>'*', ...]
     *   Chart:  ['title'=>'Revenue Trend', 'type'=>'line', 'table'=>'orders', ...]
     *   Table:  ['title'=>'Orders List',   'table'=>'orders', 'columns'=>[...], ...]
     *   Filter: ['title'=>'Status',        'table'=>'orders', 'column'=>'status', 'type'=>'select', ...]
     *
     * @return array  The same component array with resolved data keys added.
     *                On error, an '_error' key is added and the data key is set to null.
     */
    public function resolve(array $component): array;
}
