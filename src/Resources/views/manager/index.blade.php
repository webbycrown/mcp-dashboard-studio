@extends('mcp-dashboard-studio::manager.layouts.manager')
@section('title', 'Dashboard Manager')

@php
    /* Helper: build a sort URL flipping direction for the given column */
    function sortUrl(string $col, string $currentSort, string $currentDir, array $extra = []): string
    {
        $newDir = $currentSort === $col && $currentDir === 'asc' ? 'desc' : 'asc';
        return request()->fullUrlWithQuery(array_merge($extra, ['sort' => $col, 'dir' => $newDir]));
    }
    /* Helper: CSS class for active sort column */
    function sortClass(string $col, string $currentSort, string $currentDir): string
    {
        if ($currentSort !== $col) {
            return 'sortable';
        }
        return 'sortable sort-' . $currentDir;
    }
@endphp

@section('content')

    {{-- Page Header --}}
    <div class="dashboard-header">
        <h1 class="dashboard-title">Dashboard Manager</h1>
        <p class="dashboard-description">Manage, filter, and control access to all generated AI dashboards.</p>
    </div>

    {{-- SECTION 1 — Stats --}}
    <div class="kpi-grid">
        <div class="dashboard-card kpi-card">
            <h3 class="kpi-title" style="color: #6366f1;"><i class="bi bi-grid-1x2"></i> Total Dashboards</h3>
            <div class="kpi-value">{{ $stats['total'] }}</div>
        </div>
        <div class="dashboard-card kpi-card">
            <h3 class="kpi-title kpi-success"><i class="bi bi-globe"></i> Public</h3>
            <div class="kpi-value success">{{ $stats['public'] }}</div>
        </div>
        <div class="dashboard-card kpi-card">
            <h3 class="kpi-title kpi-warning"><i class="bi bi-lock-fill"></i> Private</h3>
            <div class="kpi-value warning">{{ $stats['private'] }}</div>
        </div>
        <div class="dashboard-card kpi-card">
            <h3 class="kpi-title kpi-info" style="color: #8b5cf6;"><i class="bi bi-eye"></i> Total Views</h3>
            <div class="kpi-value info" style="color: #8b5cf6;">{{ number_format($stats['total_views']) }}</div>
        </div>
        {{-- <div class="dashboard-card kpi-card">
            <h3 class="kpi-title kpi-danger"><i class="bi bi-trash"></i> Trash</h3>
            <div class="kpi-value danger">{{ $stats['trash'] }}</div>
        </div> --}}
    </div>

    {{-- Filter Form --}}
    <form method="GET" action="{{ route('mcp.manager.dashboards.index') }}" id="mgr-filter-form"
        class="dashboard-filters">
        <div class="filter-control filter-control-wide">
            <label class="filter-label" for="mgr-search">Search</label>
            <input type="text" id="mgr-search" name="q" class="filter-input" value="{{ $search }}"
                placeholder="Name or slug…" autocomplete="off">
        </div>

        <div class="filter-control">
            <label class="filter-label" for="mgr-status">Status</label>
            <select id="mgr-status" name="status" class="filter-select">
                <option value="">All</option>
                <option value="public" {{ $status === 'public' ? 'selected' : '' }}>Public</option>
                <option value="private" {{ $status === 'private' ? 'selected' : '' }}>Private</option>
            </select>
        </div>

        <div class="filter-control">
            <label class="filter-label" for="mgr-version">Version</label>
            <select id="mgr-version" name="version" class="filter-select">
                <option value="">All</option>
                @foreach ($versions as $v)
                    <option value="{{ $v }}" {{ $version == $v ? 'selected' : '' }}>v{{ $v }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="filter-control">
            <label class="filter-label" for="mgr-date-from">From</label>
            <input type="date" id="mgr-date-from" name="date_from" class="filter-input" value="{{ $dateFrom }}">
        </div>

        <div class="filter-control">
            <label class="filter-label" for="mgr-date-to">To</label>
            <input type="date" id="mgr-date-to" name="date_to" class="filter-input" value="{{ $dateTo }}">
        </div>

        <div class="filter-control">
            <label class="filter-label" for="mgr-sort">Sort By</label>
            <select id="mgr-sort" name="sort" class="filter-select">
                <option value="created_at" {{ $sort === 'created_at' ? 'selected' : '' }}>Created</option>
                <option value="name" {{ $sort === 'name' ? 'selected' : '' }}>Name</option>
                <option value="status" {{ $sort === 'status' ? 'selected' : '' }}>Status</option>
                <option value="view_count" {{ $sort === 'view_count' ? 'selected' : '' }}>Most Viewed</option>
                <option value="last_viewed_at"{{ $sort === 'last_viewed_at' ? 'selected' : '' }}>Last Viewed</option>
                <option value="version" {{ $sort === 'version' ? 'selected' : '' }}>Version</option>
            </select>
        </div>

        <div class="filter-control" style="min-width:100px;">
            <label class="filter-label" for="mgr-dir">Order</label>
            <select id="mgr-dir" name="dir" class="filter-select">
                <option value="desc" {{ $dir === 'desc' ? 'selected' : '' }}>↓ Desc</option>
                <option value="asc" {{ $dir === 'asc' ? 'selected' : '' }}>↑ Asc</option>
            </select>
        </div>

        <div class="filter-control filter-control-actions">
            <button type="submit" class="filter-btn"><i class="bi bi-search"></i> Search</button>
            @if ($search || $status || $version || $dateFrom || $dateTo || $sort !== 'created_at' || $dir !== 'desc')
                <a href="{{ route('mcp.manager.dashboards.index') }}" class="filter-btn filter-btn-secondary"><i
                        class="bi bi-x-circle"></i> Clear</a>
            @endif
        </div>
    </form>


















    {{-- BULK ACTION BAR --}}
    <form method="POST" action="{{ route('mcp.manager.dashboards.bulk') }}" id="mgr-bulk-form">
        @csrf
        <div class="mgr-bulk-bar" id="mgr-bulk-bar">
            <span class="mgr-bulk-label" id="mgr-bulk-count">0 selected</span>

            <div class="mgr-bulk-actions">
                <button type="submit" name="action" value="make_public" class="filter-btn action-public">
                    <i class="bi bi-globe"></i> Make Public
                </button>

                <button type="submit" name="action" value="make_private" class="filter-btn action-private">
                    <i class="bi bi-lock-fill"></i> Make Private
                </button>

                <button type="submit" name="action" value="delete" class="filter-btn action-delete">
                    <i class="bi bi-trash"></i> Delete
                </button>
            </div>

            <span class="mgr-bulk-spacer"></span>

            <button type="button" id="mgr-bulk-clear" class="filter-btn mgr-bulk-clear">
                <i class="bi bi-x-circle"></i> Deselect
            </button>
        </div>

        {{-- Dashboard Table --}}
        <div class="dashboard-card table-card-wrapper">
            <div class="table-title">
                <div class="table-title-left">
                    <span>All Dashboards</span>
                    <span class="table-badge">
                        {{ $dashboards instanceof \Illuminate\Pagination\LengthAwarePaginator ? $dashboards->total() : $dashboards->count() }}
                        {{ Str::plural('result', $dashboards instanceof \Illuminate\Pagination\LengthAwarePaginator ? $dashboards->total() : $dashboards->count()) }}
                    </span>
                </div>
                <div class="col-chooser-wrap">
                    {{-- <button type="button" id="mgr-col-chooser-btn" class="filter-btn filter-btn-secondary"><i class="bi bi-gear-fill"></i> Columns</button> --}}
                    <div class="col-chooser-menu" id="mgr-col-chooser-menu">
                        <label class="col-chooser-label"><input type="checkbox" data-col="status" checked> Status</label>
                        <label class="col-chooser-label"><input type="checkbox" data-col="components" checked>
                            Components</label>
                        <label class="col-chooser-label"><input type="checkbox" data-col="views" checked> Views</label>
                        <label class="col-chooser-label"><input type="checkbox" data-col="last_viewed" checked> Last
                            Viewed</label>
                        <label class="col-chooser-label"><input type="checkbox" data-col="version" checked>
                            Version</label>
                        <label class="col-chooser-label"><input type="checkbox" data-col="created" checked>
                            Created</label>
                    </div>
                </div>
            </div>

            @if ($dashboards->isEmpty())
                <div class="table-empty">
                    <div class="table-empty-icon">
                        @if ($search || $status || $version || $dateFrom || $dateTo)
                            <i class="bi bi-search"></i>
                        @else
                            <i class="bi bi-bar-chart-line"></i>
                        @endif
                    </div>
                    @if ($search || $status || $version || $dateFrom || $dateTo)
                        <p>No dashboards match your filters.<br>
                            <a href="{{ route('mcp.manager.dashboards.index') }}"><i class="bi bi-x-circle"></i> Clear
                                filters</a>
                        </p>
                    @else
                        <p>No dashboards yet. Ask an AI tool to generate one.</p>
                    @endif
                </div>
            @else
                <div class="table-scroll">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th class="table-col-checkbox"><input type="checkbox" id="mgr-select-all"
                                        class="table-checkbox"></th>
                                <th class="table-col-sticky">
                                    <a href="{{ sortUrl('name', $sort, $dir) }}"
                                        class="sortable-link {{ sortClass('name', $sort, $dir) }}">
                                        Name / Slug <span class="sort-icon"><i
                                                class="{{ $sort === 'name' ? ($dir === 'asc' ? 'bi bi-caret-up-fill' : 'bi bi-caret-down-fill') : 'bi bi-arrow-down-up' }}"></i></span>
                                    </a>
                                </th>
                                <th data-col="status" class="table-col-sticky">
                                    <a href="{{ sortUrl('status', $sort, $dir) }}"
                                        class="sortable-link {{ sortClass('status', $sort, $dir) }}">
                                        Status <span class="sort-icon"><i
                                                class="{{ $sort === 'status' ? ($dir === 'asc' ? 'bi bi-caret-up-fill' : 'bi bi-caret-down-fill') : 'bi bi-arrow-down-up' }}"></i></span>
                                    </a>
                                </th>
                                <th data-col="components" class="table-col-sticky">Comps</th>
                                <th data-col="views" class="table-col-sticky">
                                    <a href="{{ sortUrl('view_count', $sort, $dir) }}"
                                        class="sortable-link {{ sortClass('view_count', $sort, $dir) }}">
                                        Views <span class="sort-icon"><i
                                                class="{{ $sort === 'view_count' ? ($dir === 'asc' ? 'bi bi-caret-up-fill' : 'bi bi-caret-down-fill') : 'bi bi-arrow-down-up' }}"></i></span>
                                    </a>
                                </th>
                                <th data-col="version" class="table-col-sticky">
                                    <a href="{{ sortUrl('version', $sort, $dir) }}"
                                        class="sortable-link {{ sortClass('version', $sort, $dir) }}">
                                        Ver <span class="sort-icon"><i
                                                class="{{ $sort === 'version' ? ($dir === 'asc' ? 'bi bi-caret-up-fill' : 'bi bi-caret-down-fill') : 'bi bi-arrow-down-up' }}"></i></span>
                                    </a>
                                </th>

                                <th class="table-col-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($dashboards as $dashboard)
                                <tr>
                                    <td class="table-col-checkbox"><input type="checkbox" name="uuids[]"
                                            class="mgr-row-check table-checkbox" data-uuid="{{ $dashboard->uuid }}"></td>
                                    <td>
                                        <div class="dashboard-name">{{ $dashboard->name }}</div>
                                        <span class="dashboard-slug">{{ $dashboard->slug }}</span>
                                    </td>
                                    <td data-col="status">
                                        @if ($dashboard->status === 'public')
                                            <span class="status-pill sp-success"><i class="bi bi-globe"></i> Public</span>
                                        @else
                                            <span class="status-pill sp-warning"><i class="bi bi-lock-fill"></i>
                                                Private</span>
                                        @endif
                                    </td>
                                    <td data-col="components">
                                        <span
                                            class="table-badge table-badge-secondary">{{ $dashboard->component_count }}</span>
                                    </td>
                                    <td data-col="views">
                                        @if ($dashboard->view_count > 0)
                                            <span
                                                class="dashboard-views-count">{{ number_format($dashboard->view_count) }}</span>
                                        @else
                                            <span class="dashboard-views-empty">—</span>
                                        @endif
                                    </td>
                                    <td data-col="version">
                                        <span class="table-badge table-badge-secondary">v{{ $dashboard->version }}</span>
                                    </td>

                                    <td>
                                        <div class="row-actions">
                                            <a href="{{ route('dashboard-studio.show', $dashboard->slug) }}" target="_blank"
                                                class="filter-btn row-action-btn" title="View"
                                                style="background-color: var(--primary);color: white;"><i
                                                    class="bi bi-eye"></i></a>
                                            <a href="{{ route('mcp.manager.dashboards.edit', $dashboard->uuid) }}"
                                                class="filter-btn row-action-btn" title="Edit"
                                                style="background-color: #06b6d4;color: white;"><i
                                                    class="bi bi-pencil-square"></i></a>
                                            <a href="{{ route('mcp.manager.dashboards.access.index', $dashboard->uuid) }}"
                                                class="filter-btn row-action-btn" title="Access"
                                                style="background-color: #8b5cf6;color: white;"><i
                                                    class="bi bi-shield-lock"></i></a>
                                            <a href="{{ route('mcp.manager.dashboards.audit', $dashboard->uuid) }}"
                                                class="filter-btn row-action-btn" title="Audit Log"
                                                style="background-color: #f59e0b;color: white;"><i
                                                    class="bi bi-graph-up"></i></a>
                                            <button type="button" class="filter-btn row-action-btn row-action-delete"
                                                style="background-color: var(--danger);color:white"
                                                data-url="{{ route('mcp.manager.dashboards.destroy', $dashboard->uuid) }}">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                            {{-- <form method="POST"
                                                action="{{ route('mcp.manager.dashboards.destroy', $dashboard->uuid) }}"
                                                class="mgr-delete-form" data-name="{{ $dashboard->name }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="filter-btn row-action-btn row-action-delete"
                                                    title="Delete" style="background-color: var(--danger);color:white"><i
                                                        class="bi bi-trash"></i></button>
                                            </form> --}}
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Pagination --}}
                @if ($dashboards instanceof \Illuminate\Pagination\LengthAwarePaginator && $dashboards->hasPages())
                    <div class="mcp-pagination-controls">
                        <span class="pagination-info">
                            Showing {{ $dashboards->firstItem() }}–{{ $dashboards->lastItem() }} of
                            {{ $dashboards->total() }}
                        </span>
                        <div class="pagination-buttons">
                            @if ($dashboards->onFirstPage())
                                <span class="pagination-btn" disabled><i class="bi bi-chevron-left"></i></span>
                            @else
                                <a href="{{ $dashboards->previousPageUrl() }}" class="pagination-btn"><i
                                        class="bi bi-chevron-left"></i></a>
                            @endif

                            @foreach ($dashboards->getUrlRange(max(1, $dashboards->currentPage() - 2), min($dashboards->lastPage(), $dashboards->currentPage() + 2)) as $page => $url)
                                @if ($page === $dashboards->currentPage())
                                    <span class="pagination-btn active">{{ $page }}</span>
                                @else
                                    <a href="{{ $url }}" class="pagination-btn">{{ $page }}</a>
                                @endif
                            @endforeach

                            @if ($dashboards->hasMorePages())
                                <a href="{{ $dashboards->nextPageUrl() }}" class="pagination-btn"><i
                                        class="bi bi-chevron-right"></i></a>
                            @else
                                <span class="pagination-btn" disabled><i class="bi bi-chevron-right"></i></span>
                            @endif
                        </div>
                    </div>
                @endif
            @endif
        </div>

    </form>{{-- end mgr-bulk-form --}}

@endsection

@push('scripts')
    <script>
        window.mcpManagerRoutes = {
            validateBulk: @json(route('mcp.manager.dashboards.validate-bulk')),
        };
    </script>
    <script src="/mcp-dashboard-studio/assets/js/bulk-actions.js"></script>
@endpush
