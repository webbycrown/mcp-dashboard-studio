@extends('mcp-dashboard-studio::manager.layouts.manager')
@section('title', 'Edit — ' . $dashboard->name)

@section('content')


@php
$layoutJson = is_array($dashboard->layout_json)
? $dashboard->layout_json
: json_decode($dashboard->layout_json, true);
@endphp

{{-- Page Header --}}
<div class="page-header" style="border-bottom: 1px solid var(--border-color);padding-bottom:10px">
    <div>
        <h5 class="page-title">Edit Dashboard</h5>
        <!-- <div class="page-subtitle">UUID: <span class="slug-code">{{ $dashboard->uuid }}</span></div> -->
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('dashboard-studio.show', $dashboard->slug) }}" target="_blank" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-eye me-1"></i>View Live
        </a>
        <a href="{{ route('mcp.manager.dashboards.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

{{-- Two-column layout --}}
<div class="edit-layout">

    {{-- Left: Edit Form --}}
    <div>
        <div class="mgr-card">
            <div class="mgr-card-header">
                <span class="mgr-card-title"><i class="bi bi-sliders me-2"></i>Dashboard Details</span>
            </div>
            <div class="mgr-card-body">
                <form action="{{ route('mcp.manager.dashboards.update', $dashboard->uuid) }}" method="POST">
                    @csrf
                    @method('PATCH')

                    <div class="form-group">
                        <label class="form-label" for="edit-name">Name</label>
                        <input type="text" id="edit-name" name="name"
                            class="mgr-input @error('name') is-invalid @enderror"
                            value="{{ old('name', $dashboard->name) }}" maxlength="191" required>
                        @error('name')<div class="form-error">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="edit-description">
                            Description <span class="form-label-optional">(optional)</span>
                        </label>
                        <textarea id="edit-description" name="description"
                            class="mgr-textarea @error('description') is-invalid @enderror"
                            rows="3" maxlength="1000"
                            placeholder="What does this dashboard show?">{{ old('description', $dashboard->description) }}</textarea>
                        @error('description')<div class="form-error">{{ $message }}</div>@enderror
                    </div>

                    <!-- layout_json -->
                    <div class="form-group">
                        <label class="form-label">Meta</label>

                        <textarea
                            name="layout_meta"
                            rows="8"
                            class="mgr-textarea"
                            style="font-family: monospace;">{{ old(
            'layout_meta',
            json_encode($layoutJson['meta'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ) }}</textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Layout</label>

                        <textarea
                            name="layout_layout"
                            rows="15"
                            class="mgr-textarea"
                            style="font-family: monospace;">{{ old(
            'layout_layout',
            json_encode($layoutJson['layout'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ) }}</textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Components</label>

                        <textarea
                            name="layout_components"
                            rows="30"
                            class="mgr-textarea"
                            style="font-family: monospace;">{{ old(
            'layout_components',
            json_encode($layoutJson['components'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ) }}</textarea>
                    </div>


                    <!-- layout_json -->

                    <div class="form-group">
                        <label class="form-label">Visibility</label>
                        <div class="visibility-picker">
                            <label class="visibility-option {{ old('status', $dashboard->status) === 'public' ? 'is-selected' : '' }}">
                                <input type="radio" name="status" value="public"
                                    {{ old('status', $dashboard->status) === 'public' ? 'checked' : '' }}>
                                <i class="bi bi-globe2 visibility-icon text-success"></i>
                                <div>
                                    <div class="visibility-title">Public</div>
                                    <div class="visibility-desc">Anyone with the URL</div>
                                </div>
                            </label>
                            <label class="visibility-option {{ old('status', $dashboard->status) === 'private' ? 'is-selected' : '' }}">
                                <input type="radio" name="status" value="private"
                                    {{ old('status', $dashboard->status) === 'private' ? 'checked' : '' }}>
                                <i class="bi bi-lock-fill visibility-icon text-warning"></i>
                                <div>
                                    <div class="visibility-title">Private</div>
                                    <div class="visibility-desc">Only authorised users</div>
                                </div>
                            </label>
                        </div>
                        @error('status')<div class="form-error mt-1">{{ $message }}</div>@enderror
                        {{-- <div class="form-hint">
                            When Private, manage access on the
                            <a href="{{ route('mcp.manager.dashboards.access.index', $dashboard->uuid) }}" class="link-primary">Access tab</a>.
                    </div> --}}
            </div>

            {{-- Meta info panel --}}
            <div class="meta-panel">
                <div class="meta-item">
                    <div class="meta-label">Slug</div>
                    <span class="slug-code">{{ $dashboard->slug }}</span>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Version</div>
                    <span class="meta-badge">v{{ $dashboard->version }}</span>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Views</div>
                    <span class="meta-views">{{ number_format($dashboard->view_count) }}</span>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="mgr-btn mgr-btn-primary">
                    <i class="bi bi-floppy me-1"></i>Save Changes
                </button>
                <a href="{{ route('mcp.manager.dashboards.index') }}" class="mgr-btn mgr-btn-secondary">
                    Cancel
                </a>
            </div>
            </form>
        </div>
    </div>
</div>

{{-- Right: Sidebar --}}
<div class="edit-sidebar">

    {{-- Quick Actions --}}
    <div class="mgr-card">
        <div class="mgr-card-header">
            <span class="mgr-card-title"><i class="bi bi-lightning me-2"></i>Quick Actions</span>
        </div>
        <div class="mgr-card-body action-list">
            <a href="{{ route('mcp.manager.dashboards.access.index', $dashboard->uuid) }}" class="mgr-btn mgr-btn-info w-100">
                <i class="bi bi-shield-lock me-1"></i>Manage Access
            </a>
            <a href="{{ route('mcp.manager.dashboards.audit', $dashboard->uuid) }}" class="mgr-btn mgr-btn-secondary w-100">
                <i class="bi bi-clock-history me-1"></i>View Audit Trail
            </a>
            <a href="{{ route('mcp.manager.dashboards.export', $dashboard->uuid) }}" class="mgr-btn mgr-btn-secondary w-100">
                <i class="bi bi-download me-1"></i>Export as JSON
            </a>
            <div class="action-separator"></div>
            <form method="POST" action="{{ route('mcp.manager.dashboards.destroy', $dashboard->uuid) }}"
                onsubmit="return confirm('Move to trash?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="mgr-btn mgr-btn-danger w-100">
                    <i class="bi bi-trash me-1"></i>Move to Trash
                </button>
            </form>
        </div>
    </div>

    {{-- Original Prompt --}}
    <div class="mgr-card prompt-card">
        <div class="mgr-card-header">
            <span class="mgr-card-title"><i class="bi bi-chat-left-text me-2"></i>Original Prompt</span>
        </div>
        <div class="mgr-card-body">
            <p class="prompt-preview">{{ $dashboard->prompt }}</p>
        </div>
    </div>

</div>
</div>

@endsection
