@extends('mcp-dashboard-studio::dashboard-studio.layouts.app')

@section('title', $layout['title'] ?? 'Dashboard')

@section('content')
    <header class="dashboard-header">
        <h1 class="dashboard-title">{{ $layout['title'] ?? 'Dashboard' }}</h1>
        @if(!empty($layout['description']))
            <p class="dashboard-description">{{ $layout['description'] }}</p>
        @endif
    </header>

    {{-- Filters render FIRST as a control bar above all dashboard content --}}
    @php
        $filterComponents = collect($layout['components'] ?? [])->filter(fn($c) => ($c['type'] ?? '') === 'filter')->values();
        $otherComponents  = collect($layout['components'] ?? [])->filter(fn($c) => ($c['type'] ?? '') !== 'filter')->values();
    @endphp

    @if($filterComponents->isNotEmpty())
        <div class="dashboard-filters" id="dashboard-filters">
            @foreach($filterComponents as $component)
                @include('mcp-dashboard-studio::dashboard-studio.components.filter', ['component' => $component])
            @endforeach
        </div>
    @endif

    {{-- KPIs render in their own responsive auto-fit grid --}}
    @php
        $kpiComponents   = $otherComponents->filter(fn($c) => ($c['type'] ?? '') === 'kpi')->values();
        $gridComponents  = $otherComponents->filter(fn($c) => ($c['type'] ?? '') !== 'kpi')->values();
    @endphp

    @if($kpiComponents->isNotEmpty())
        <div class="kpi-grid">
            @foreach($kpiComponents as $component)
                @include('mcp-dashboard-studio::dashboard-studio.components.kpi', ['component' => $component])
            @endforeach
        </div>
    @endif

    {{-- Charts, Tables, and other components in the 12-col grid --}}
    <div class="dashboard-grid">
        @foreach($gridComponents as $component)
            @switch($component['type'] ?? '')
                @case('bar_chart')
                @case('line_chart')
                @case('pie_chart')
                @case('doughnut_chart')
                @case('donut_chart')
                @case('chart')
                    @include('mcp-dashboard-studio::dashboard-studio.components.chart', ['component' => $component])
                    @break

                @case('table')
                    @include('mcp-dashboard-studio::dashboard-studio.components.table', ['component' => $component])
                    @break

                @case('card')
                    @include('mcp-dashboard-studio::dashboard-studio.components.card', ['component' => $component])
                    @break

                @case('text')
                    @include('mcp-dashboard-studio::dashboard-studio.components.text', ['component' => $component])
                    @break

                @case('metric')
                    @include('mcp-dashboard-studio::dashboard-studio.components.metric', ['component' => $component])
                    @break

                @case('progress')
                    @include('mcp-dashboard-studio::dashboard-studio.components.progress', ['component' => $component])
                    @break

                @case('stat')
                    @include('mcp-dashboard-studio::dashboard-studio.components.stat', ['component' => $component])
                    @break

                @default
                    @include('mcp-dashboard-studio::dashboard-studio.components.card', ['component' => $component])
                    @break
            @endswitch
        @endforeach
    </div>
@endsection

@section('scripts')
    <script>
    window.mcpCharts = {{ Js::from($charts) }};
    window.mcpDashboardSlug = {{ Js::from($slug ?? '') }};
    window.mcpDashboardFilterUrl = {{ Js::from(route('dashboard-studio.filter', ['slug' => $slug ?? ''])) }};
</script>
@endsection
