@php
    $title = $component['data']['title'] ?? 'Data List';
    $columns = $component['data']['columns'] ?? [];
    $headers = $component['data']['headers'] ?? [];
    $rows = $component['data']['rows'] ?? [];
    $rowCount = count($rows);

    // Use headers if columns are empty (DatabaseProvider returns headers)
    if (empty($columns) && !empty($headers)) {
        $columns = $headers;
    }
@endphp
<div class="dashboard-card table-card-wrapper">
    <div class="table-title">
        <span>{{ $title }}</span>
        @if($rowCount > 0)
            <span class="table-badge">{{ $rowCount }} {{ Str::plural('record', $rowCount) }}</span>
        @endif
    </div>
    <div class="table-search">
        <input type="text" class="table-search-input" placeholder="Search this table…" autocomplete="off">
    </div>
    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr>
                    @foreach($columns as $col)
                        <th>{{ is_array($col) ? ($col['title'] ?? $col['name'] ?? '') : ucwords(str_replace('_', ' ', $col)) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    <tr>
                        @foreach($columns as $col)
                            @php
                                $colKey = is_array($col) ? ($col['name'] ?? '') : $col;
                                $cellVal = is_array($row) ? ($row[$colKey] ?? '') : ($row->{$colKey} ?? '');

                                // Format numeric values
                                if (is_numeric($cellVal) && strlen((string)$cellVal) > 0) {
                                    $num = (float)$cellVal;
                                    if ($num == floor($num) && abs($num) < 1000000) {
                                        $cellVal = number_format($num, 0);
                                    } elseif (abs($num) < 1000000) {
                                        $cellVal = number_format($num, 2);
                                    }
                                }

                                // Truncate long text
                                if (is_string($cellVal) && strlen($cellVal) > 60) {
                                    $cellVal = substr($cellVal, 0, 57) . '...';
                                }

                                // Format stock_status values
                                $isStatus = str_contains(strtolower($colKey), 'status') ||
                                            str_contains(strtolower($colKey), 'active') ||
                                            str_contains(strtolower($colKey), 'state');
                            @endphp
                            <td>
                                @if($isStatus && is_string($cellVal))
                                    @php
                                        $statusClass = match(strtolower(str_replace(['_', '-'], '', $cellVal))) {
                                            'instock', 'active', 'completed', 'approved', '1', 'yes' => 'sp-success',
                                            'outofstock', 'inactive', 'cancelled', 'rejected', '0', 'no' => 'sp-danger',
                                            'pending', 'processing', 'onhold' => 'sp-warning',
                                            default => '',
                                        };
                                    @endphp
                                    @if($statusClass)
                                        <span class="status-pill {{ $statusClass }}">{{ str_replace('_', ' ', $cellVal) }}</span>
                                    @else
                                        {{ str_replace('_', ' ', $cellVal) }}
                                    @endif
                                @else
                                    {{ $cellVal }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ max(count($columns), 1) }}" class="table-empty">
                            No records found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
