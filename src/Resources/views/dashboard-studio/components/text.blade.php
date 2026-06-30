<div class="dashboard-card" style="grid-column: span {{ $component['grid']['w'] ?? 4 }}; grid-row: span {{ $component['grid']['h'] ?? 2 }};">
    @if(!empty($component['data']['title']))
        <h3 class="chart-title" style="margin-bottom: 8px;">{{ $component['data']['title'] }}</h3>
    @endif
    <p style="margin: 0; color: var(--text-secondary); font-size: 0.9rem; line-height: 1.5;">
        {{ $component['data']['text'] ?? $component['data']['value'] ?? '' }}
    </p>
</div>
