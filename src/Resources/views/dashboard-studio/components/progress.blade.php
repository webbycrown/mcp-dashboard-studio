<div class="dashboard-card" style="grid-column: span {{ $component['grid']['w'] ?? 4 }}; grid-row: span {{ $component['grid']['h'] ?? 2 }};">
    <h3 class="kpi-title">{{ $component['data']['title'] ?? 'Progress' }}</h3>
    @php
        $val = (float)($component['data']['value'] ?? 0);
        $max = (float)($component['data']['max'] ?? 100);
        $percent = $max > 0 ? min(100, max(0, ($val / $max) * 100)) : 0;
    @endphp
    <div style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 8px;">
        {{ $val }} / {{ $max }}
    </div>
    <div style="background-color: var(--bg-app); border-radius: 9999px; height: 10px; overflow: hidden; width: 100%;">
        <div style="background-color: var(--primary); width: {{ $percent }}%; height: 100%; border-radius: 9999px;"></div>
    </div>
</div>
