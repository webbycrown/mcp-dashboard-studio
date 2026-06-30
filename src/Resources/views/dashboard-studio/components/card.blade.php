<div class="dashboard-card" style="grid-column: span {{ $component['grid']['w'] ?? 3 }}; grid-row: span {{ $component['grid']['h'] ?? 2 }};">
    @if(!empty($component['data']['title']))
        <h3 class="chart-title" style="margin-bottom: 12px;">{{ $component['data']['title'] }}</h3>
    @endif
    <div class="card-content">
        {{ $component['data']['content'] ?? $component['data']['description'] ?? '' }}
    </div>
</div>
