@php
    $filterData = $component['data'] ?? [];
    $datasource = $component['datasource'] ?? [];
    $title = $filterData['title'] ?? $filterData['label'] ?? 'Filter';
    $control = $filterData['control'] ?? $filterData['filterType'] ?? $filterData['type'] ?? 'select';
    $options = $filterData['options'] ?? [];
    $column = $datasource['column'] ?? $filterData['column'] ?? $filterData['field'] ?? '';
    $table = $datasource['table'] ?? $filterData['table'] ?? '';
@endphp
<div class="filter-control">
    <label class="filter-label">{{ $title }}</label>
    @if($control === 'date_range')
        <div class="filter-date-inputs">
            <input type="date" class="filter-input mcp-filter-input"
                   data-filter-column="{{ $column }}"
                   data-filter-table="{{ $table }}"
                   data-filter-type="date_from">
            <span class="filter-date-sep">to</span>
            <input type="date" class="filter-input mcp-filter-input"
                   data-filter-column="{{ $column }}"
                   data-filter-table="{{ $table }}"
                   data-filter-type="date_to">
        </div>
    @else
        <select class="filter-select mcp-filter-select"
                data-filter-column="{{ $column }}"
                data-filter-table="{{ $table }}">
            <option value="">All</option>
            @foreach($options as $opt)
                <option value="{{ is_array($opt) ? ($opt['value'] ?? '') : $opt }}">
                    {{ is_array($opt) ? ($opt['label'] ?? $opt['value'] ?? '') : ucwords(str_replace('_', ' ', $opt)) }}
                </option>
            @endforeach
        </select>
    @endif
</div>
