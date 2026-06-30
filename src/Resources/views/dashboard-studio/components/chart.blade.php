@php
    $gridW = $component['grid']['w'] ?? 6;
    $gridH = $component['grid']['h'] ?? 5;
@endphp
<div class="dashboard-card chart-card" id="{{ $component['id'] }}_wrapper">
    <h3 class="chart-title">{{ $component['data']['title'] ?? 'Chart' }}</h3>
    <div class="chart-body">
        <canvas id="{{ $component['id'] }}_canvas"></canvas>
    </div>
</div>
