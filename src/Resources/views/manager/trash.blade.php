@extends('mcp-dashboard-studio::manager.layouts.manager')
@section('title', 'Trash')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h5 class="fw-bold mb-0"><i class="bi bi-trash me-2 text-danger"></i>Trash</h5>
        <small class="text-secondary">Soft-deleted dashboards — restore or permanently purge.</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('mcp.manager.dashboards.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
        @if($dashboards->isNotEmpty())
            <form method="POST" action="{{ route('mcp.manager.dashboards.trash.empty') }}"
                  onsubmit="return confirm('Permanently delete ALL {{ $dashboards->count() }} dashboard(s)? This cannot be undone.')">
                @csrf
                <button type="submit" class="btn btn-sm btn-danger">
                    <i class="bi bi-trash-fill me-1"></i>Empty Trash ({{ $dashboards->count() }})
                </button>
            </form>
        @endif
    </div>
</div>

<div class="table-card">
    <div class="table-responsive">
        <table id="trash-table" class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th>Name / Slug</th>
                    <th>Status</th>
                    <th>Version</th>
                    <th>Deleted</th>
                    <th width="180">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($dashboards as $dashboard)
                <tr>
                    <td>
                        <div class="fw-semibold text-white">{{ $dashboard->name }}</div>
                        <span class="slug-code">{{ $dashboard->slug }}</span>
                    </td>
                    <td>
                        @if($dashboard->status === 'public')
                            <span class="badge badge-public rounded-pill"><i class="bi bi-globe me-1"></i>Public</span>
                        @else
                            <span class="badge badge-private rounded-pill"><i class="bi bi-lock me-1"></i>Private</span>
                        @endif
                    </td>
                    <td><span class="badge bg-secondary">v{{ $dashboard->version }}</span></td>
                    <td class="text-secondary small">{{ $dashboard->deleted_at->diffForHumans() }}</td>
                    <td>
                        <div class="d-flex gap-1">
                            <form method="POST" action="{{ route('mcp.manager.dashboards.restore', $dashboard->uuid) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-success action-btn" title="Restore">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </button>
                            </form>
                            <form method="POST" action="{{ route('mcp.manager.dashboards.purge', $dashboard->uuid) }}"
                                  class="d-inline"
                                  onsubmit="return confirm('Permanently delete \'{{ addslashes($dashboard->name) }}\'? This cannot be undone.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger action-btn" title="Purge">
                                    <i class="bi bi-x-octagon"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center py-5 text-secondary">
                        <i class="bi bi-trash display-6 d-block mb-2 opacity-25"></i>
                        Trash is empty.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    $('#trash-table').DataTable({
        paging: true, pageLength: 10, ordering: true, searching: true,
        order: [[3, 'desc']],
        dom: '<"d-flex justify-content-between mb-2"fB>rtp',
        buttons: [
            { extend: 'csvHtml5', text: '<i class="bi bi-filetype-csv me-1"></i>CSV', className: 'btn btn-sm btn-outline-secondary' }
        ],
        language: { paginate: { previous: '<', next: '>' } }
    });
});
</script>
@endpush
