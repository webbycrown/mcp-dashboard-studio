@php
    $value = $component['data']['value'] ?? '0';
    $format = $component['data']['format'] ?? 'number';
    // Format currency values
    if ($format === 'currency' && is_numeric($value)) {
        $value = '$' . number_format((float)$value, 2);
    } elseif (is_numeric($value)) {
        $value = number_format((float)$value, (floor($value) == $value) ? 0 : 2);
    }
@endphp
<div class="dashboard-card kpi-card">
    <h3 class="kpi-title">{{ $component['data']['title'] ?? 'KPI' }}</h3>
    <p class="kpi-value">{{ $value }}</p>
    @if(!empty($component['data']['unit']) && $component['data']['unit'] !== 'count')
        <span class="kpi-sub">{{ $component['data']['unit'] }}</span>
    @endif
</div>
