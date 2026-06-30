@extends('mcp-dashboard-studio::manager.layouts.manager')
@section('title', 'Audit — ' . $dashboard->name)

@push('styles')
    <style>
        .bulk-action-modal {
            position: fixed;
            inset: 0;
            z-index: 10000;

            display: none;
            /* Hidden by default */

            align-items: center;
            justify-content: center;

            animation: fadeIn 0.2s ease;
        }

        .bulk-action-modal.open {
            display: flex;
            /* Show only when .open exists */
        }
    </style>
@endpush

@section('content')
    @php
        function sortUrl(string $col, string $currentSort, string $currentDir, array $extra = []): string
        {
            $newDir = $currentSort === $col && $currentDir === 'asc' ? 'desc' : 'asc';
            return request()->fullUrlWithQuery(array_merge($extra, ['sort' => $col, 'dir' => $newDir]));
        }
        function sortClass(string $col, string $currentSort, string $currentDir): string
        {
            if ($currentSort !== $col) {
                return 'sortable';
            }
            return 'sortable sort-' . $currentDir;
        }
    @endphp

    <div class="page-header" style="border-bottom: 1px solid var(--border-color);padding-bottom:10px">
        <div>
            <h5 class="page-title">Audit Trail</h5>
            <p class="page-subtitle">
                <strong class="text-white">{{ $dashboard->name }}</strong>
                <span class="badge slug-code">{{ $dashboard->slug }}</span>
            </p>
        </div>
        <a href="{{ route('mcp.manager.dashboards.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>

    <form method="GET" action="{{ route('mcp.manager.dashboards.audit', $dashboard->uuid) }}" class="dashboard-filters">
        <div class="filter-control filter-control-wide">
            <label class="filter-label" for="audit-search">Search</label>
            <input type="text" id="audit-search" name="q" class="filter-input" value="{{ $q }}"
                placeholder="Events, emails, IPs…" autocomplete="off">
        </div>

        <div class="filter-control">
            <label class="filter-label" for="audit-sort">Sort By</label>
            <select id="audit-sort" name="sort" class="filter-select" onchange="this.form.submit()">
                <option value="created_at" {{ $sort === 'created_at' ? 'selected' : '' }}>Date/Time</option>
                <option value="event" {{ $sort === 'event' ? 'selected' : '' }}>Event</option>
                <option value="actor_email" {{ $sort === 'actor_email' ? 'selected' : '' }}>Actor Email</option>
                <option value="ip_address" {{ $sort === 'ip_address' ? 'selected' : '' }}>IP Address</option>
                <option value="actor_type" {{ $sort === 'actor_type' ? 'selected' : '' }}>Actor Type</option>
            </select>
        </div>

        <div class="filter-control">
            <label class="filter-label" for="audit-dir">Direction</label>
            <select id="audit-dir" name="dir" class="filter-select" onchange="this.form.submit()">
                <option value="desc" {{ $dir === 'desc' ? 'selected' : '' }}>Newest First</option>
                <option value="asc" {{ $dir === 'asc' ? 'selected' : '' }}>Oldest First</option>
            </select>
        </div>

        <div class="filter-actions" style="gap: 6px;display: flex;">
            <button type="submit" class="filter-btn">Apply</button>
            <a href="{{ route('mcp.manager.dashboards.audit', $dashboard->uuid) }}"
                class="filter-btn filter-btn-ghost">Reset</a>
        </div>
    </form>

    <form method="POST" action="{{ route('mcp.manager.dashboards.audit.bulk', $dashboard->uuid) }}" id="audit-bulk-form">
        @csrf
        <div class="mgr-bulk-bar" id="audit-bulk-bar" style="display:none;">
            <span class="mgr-bulk-label" id="audit-bulk-count">0 selected</span>
            <div class="mgr-bulk-actions">
                <button type="button" id="audit-delete-btn" class="row-action-delete filter-btn action-delete">
                    <i class="bi bi-trash"></i> Delete Selected
                </button>
            </div>
            <span class="mgr-bulk-spacer"></span>
            <button type="button" id="audit-bulk-clear" class="filter-btn mgr-bulk-clear">
                <i class="bi bi-x-circle"></i> Deselect
            </button>
        </div>

        <div class="dashboard-card table-card-wrapper">
            <div class="table-title">
                <div class="table-title-left">
                    <span>All Logs</span>
                    <span class="table-badge">
                        {{ $logs->total() }} {{ Str::plural('result', $logs->total()) }}
                    </span>
                </div>
            </div>
            <div class="table-scroll table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="table-col-checkbox"><input type="checkbox" id="audit-select-all"
                                    class="table-checkbox"></th>
                            <th class="{{ sortClass('event', $sort, $dir) }}">
                                <a href="{{ sortUrl('event', $sort, $dir) }}" class="sortable">
                                    Event <i class="bi bi-chevron-expand ms-1"></i>
                                </a>
                            </th>
                            <th class="{{ sortClass('actor_email', $sort, $dir) }}">
                                <a href="{{ sortUrl('actor_email', $sort, $dir) }}" class="sortable">
                                    Actor <i class="bi bi-chevron-expand ms-1"></i>
                                </a>
                            </th>
                            <th class="{{ sortClass('ip_address', $sort, $dir) }}">
                                <a href="{{ sortUrl('ip_address', $sort, $dir) }}" class="sortable">
                                    IP Address <i class="bi bi-chevron-expand ms-1"></i>
                                </a>
                            </th>
                            <th>Details</th>
                            <th class="{{ sortClass('created_at', $sort, $dir) }}">
                                <a href="{{ sortUrl('created_at', $sort, $dir) }}" class="sortable">
                                    Date/Time <i class="bi bi-chevron-expand ms-1"></i>
                                </a>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($logs as $log)
                            <tr>
                                <td class="table-col-checkbox"><input type="checkbox" name="ids[]"
                                        class="audit-row-check table-checkbox" value="{{ $log->id }}"></td>
                                <td>
                                    <span class="status-pill {{ $log->event_badge_class }}">
                                        {{ $log->event_label }}
                                    </span>
                                </td>
                                <td>
                                    @if ($log->actor_email)
                                        <div class="small fw-semibold text-white">{{ $log->actor_email }}</div>
                                    @endif
                                    @if ($log->actor_type)
                                        <span
                                            class="status-pill sp-info text-capitalize">{{ str_replace('_', ' ', $log->actor_type) }}</span>
                                    @else
                                        <span class="text-secondary small">—</span>
                                    @endif
                                </td>
                                <td class="text-secondary small font-monospace">{{ $log->ip_address ?? '—' }}</td>
                                <td>
                                    @if ($log->metadata)
                                        <button type="button" class="btn btn-sm btn-outline-info json-preview-btn"
                                            data-json='@json($log->metadata)'>
                                            <i class="bi bi-braces"></i>
                                            <span>
                                                {{ \Illuminate\Support\Str::limit(json_encode($log->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 60, '...') }}
                                            </span>
                                        </button>
                                    @else
                                        <span class="text-secondary small">—</span>
                                    @endif
                                </td>
                                <td class="text-secondary small">
                                    {{ $log->created_at->format('d M Y, H:i:s') }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="table-empty">
                                        <div><i class="bi bi-clock-history"></i></div>
                                        No audit events recorded yet.
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($logs instanceof \Illuminate\Pagination\LengthAwarePaginator && $logs->hasPages())
                <div class="mcp-pagination-controls">
                    <span class="pagination-info">
                        Showing {{ $logs->firstItem() }}–{{ $logs->lastItem() }} of
                        {{ $logs->total() }}
                    </span>
                    <div class="pagination-buttons">
                        @if ($logs->onFirstPage())
                            <span class="pagination-btn" disabled><i class="bi bi-chevron-left"></i></span>
                        @else
                            <a href="{{ $logs->previousPageUrl() }}" class="pagination-btn"><i
                                    class="bi bi-chevron-left"></i></a>
                        @endif

                        @foreach ($logs->getUrlRange(max(1, $logs->currentPage() - 2), min($logs->lastPage(), $logs->currentPage() + 2)) as $page => $url)
                            @if ($page === $logs->currentPage())
                                <span class="pagination-btn active">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" class="pagination-btn">{{ $page }}</a>
                            @endif
                        @endforeach

                        @if ($logs->hasMorePages())
                            <a href="{{ $logs->nextPageUrl() }}" class="pagination-btn"><i
                                    class="bi bi-chevron-right"></i></a>
                        @else
                            <span class="pagination-btn" disabled><i class="bi bi-chevron-right"></i></span>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        {{-- json mopdal --}}
        <div class="modal-overlay" id="jsonModal">
            <div class="modal" style="max-width:560px; width:100%;">
                <h2><i class="bi bi-braces me-2"></i>Audit Details</h2>
                <pre id="jsonModalContent"
                    style="background:var(--bg-app); border:1px solid var(--border-color);
                    border-radius:10px; padding:1rem; font-size:0.8rem;
                    color:var(--primary-light); white-space:pre-wrap;
                    max-height:400px; overflow-y:auto;"></pre>
                <div class="modal-actions">
                    <button class="mgr-btn mgr-btn-secondary"
                        onclick="document.getElementById('jsonModal').classList.remove('open')">
                        Close
                    </button>
                </div>
            </div>
        </div>

        {{-- delete audit --}}
        <div class="bulk-action-modal" id="auditDeleteModal">
            <div class="modal-backdrop"></div>
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title">Confirm Delete</h2>
                    <button type="button" class="modal-close" aria-label="Close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <p class="modal-message" id="audit-delete-message">
                        Delete selected audit log(s)?
                    </p>
                    <div class="modal-stats">
                        <div class="stat">
                            <span class="stat-label">Selected:</span>
                            <span class="stat-value" id="audit-delete-count">0</span>
                        </div>
                        <div class="stat">
                            <span class="stat-label">Action:</span>
                            <span class="stat-value">Delete</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="modal-cancel btn btn-outline-secondary">Cancel</button>
                    <button type="button" class="modal-confirm btn btn-indigo">Confirm</button>
                </div>
            </div>
        </div>


    @endsection

    @push('scripts')
        <script>
            (function() {
                const selectAll = document.getElementById('audit-select-all');
                const bulkBar = document.getElementById('audit-bulk-bar');
                const bulkCount = document.getElementById('audit-bulk-count');
                const clearBtn = document.getElementById('audit-bulk-clear');
                const checks = document.querySelectorAll('.audit-row-check');

                function updateBulkBar() {
                    const count = document.querySelectorAll('.audit-row-check:checked').length;
                    bulkCount.textContent = count + ' selected';
                    bulkBar.style.display = count > 0 ? 'flex' : 'none';
                }

                if (selectAll) {
                    selectAll.addEventListener('change', function() {
                        checks.forEach(function(cb) {
                            cb.checked = selectAll.checked;
                        });
                        updateBulkBar();
                    });
                }

                checks.forEach(function(cb) {
                    cb.addEventListener('change', updateBulkBar);
                });

                if (clearBtn) {
                    clearBtn.addEventListener('click', function() {
                        checks.forEach(function(cb) {
                            cb.checked = false;
                        });
                        if (selectAll) {
                            selectAll.checked = false;
                        }
                        updateBulkBar();
                    });
                }

                updateBulkBar();
            })();

            document.querySelectorAll('.json-preview-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    let jsonData = btn.dataset.json;
                    try {
                        document.getElementById('jsonModalContent').textContent =
                            JSON.stringify(
                                JSON.parse(jsonData),
                                null,
                                4
                            );
                    } catch (error) {
                        document.getElementById('jsonModalContent').textContent = jsonData;
                    }
                    document.getElementById('jsonModal').classList.add('open');
                });
            });
            document.getElementById('jsonModal').addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('open');
                }
            });
        </script>
    @endpush


    @push('scripts')
        <script>
            const bulkForm = document.getElementById('audit-bulk-form');
            const deleteBtn = document.getElementById('audit-delete-btn');

            const deleteModal = document.getElementById('auditDeleteModal');

            const confirmBtn = deleteModal.querySelector('.modal-confirm');
            const cancelBtn = deleteModal.querySelector('.modal-cancel');
            const closeBtn = deleteModal.querySelector('.modal-close');

            const countElement = document.getElementById('audit-delete-count');
            const messageElement = document.getElementById('audit-delete-message');

            function openDeleteModal() {

                const count = document.querySelectorAll('.audit-row-check:checked').length;

                if (count === 0) {
                    return;
                }

                countElement.textContent = count;

                messageElement.textContent =
                    `Delete ${count} audit log${count > 1 ? 's' : ''}?`;

                deleteModal.classList.add('open');
            }

            function closeDeleteModal() {
                deleteModal.classList.remove('open');
            }

            deleteBtn.addEventListener('click', openDeleteModal);

            cancelBtn.addEventListener('click', closeDeleteModal);

            closeBtn.addEventListener('click', closeDeleteModal);

            deleteModal.querySelector('.modal-backdrop')
                .addEventListener('click', closeDeleteModal);

            confirmBtn.addEventListener('click', function() {
                bulkForm.submit();
            });
        </script>
    @endpush
