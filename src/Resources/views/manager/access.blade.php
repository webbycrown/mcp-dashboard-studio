@extends('mcp-dashboard-studio::manager.layouts.manager')
@section('title', 'Access — ' . $dashboard->name)

@section('content')

    {{-- Page Header --}}
    <div class="page-header" style="border-bottom: 1px solid var(--border-color);padding-bottom:10px">
        <div>
            <h5 class="page-title" >Access Management</h5>
            <div class="page-subtitle">
                <strong>{{ $dashboard->name }}</strong>
                <span class="page-subtitle-sep">·</span>
                @if ($dashboard->status === 'public')
                    <span class="badge-public"><i class="bi bi-globe me-1"></i>Public</span>
                @else
                    <span class="badge-private"><i class="bi bi-lock me-1"></i>Private</span>
                @endif
            </div>
        </div>
        <a href="{{ route('mcp.manager.dashboards.edit', $dashboard->uuid) }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Edit
        </a>
    </div>

    {{-- Public visibility warning --}}
    @if ($dashboard->status === 'public')
        <div class="mgr-alert mgr-alert-warning">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>
                This dashboard is <strong>Public</strong>. Access controls below only apply when status is
                <strong>Private</strong>.
                <a href="{{ route('mcp.manager.dashboards.edit', $dashboard->uuid) }}" class="alert-action-link">Change
                    status <i class="bi bi-arrow-right"></i></a>
            </span>
        </div>
    @endif

    {{-- Invite link reveal (shown once after creation) --}}
    @if (session('mcp_invite_url'))
        <div class="invite-reveal">

            <div class="invite-reveal-header">
                <i class="bi bi-link-45deg"></i>
                Invite created for <strong>{{ session('mcp_invite_email') }}</strong> — share this link once. It will not be
                shown again.
            </div>

            <div class="invite-copy-wrapper">
                <code id="inviteUrl" class="invite-reveal-url">
                    {{ session('mcp_invite_url') }}
                </code>

                <button type="button" class="mgr-btn mgr-btn-secondary copy-invite-btn" onclick="copyInviteLink()"
                    title="Copy invite link">
                    <i class="bi bi-clipboard"></i>
                    <span>Copy</span>
                </button>
            </div>

            <div class="invite-reveal-expiry">
                Expires:
                @if (config('mcp-dashboard-studio.manager.custom_user_token_ttl_days'))
                    {{ now()->addDays(config('mcp-dashboard-studio.manager.custom_user_token_ttl_days'))->format('M j, Y') }}
                @else
                    Never
                @endif
            </div>

        </div>
    @endif

    {{-- Two-column access panels --}}
    <div class="access-grid">

        {{-- ── System Users ──────────────────────────────────────────────── --}}
        <div class="mgr-card">
            <div class="mgr-card-header">
                <span class="mgr-card-title"><i class="bi bi-person-check me-2"></i>System Users</span>
                <span class="count-badge">{{ $systemAccess->count() }}</span>
            </div>

            @if ($systemAccess->isNotEmpty())
                <div class="access-table-wrap">
                    <table class="access-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th class="col-action"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($systemAccess as $access)
                                <tr>
                                    <td>
                                        @if ($access->user)
                                            <div class="user-name">{{ $access->user->name ?? '—' }}</div>
                                            <div class="user-email">{{ $access->user->email ?? '' }}</div>
                                        @else
                                            <span class="user-missing">User #{{ $access->user_id }} (not found)</span>
                                        @endif
                                    </td>
                                    <td>
                                        <form method="POST"
                                            action="{{ route('mcp.manager.dashboards.access.system-user.revoke', [$dashboard->uuid, $access->id]) }}"
                                            onsubmit="return confirm('Remove access?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="mgr-btn mgr-btn-danger icon-btn"
                                                title="Revoke access">
                                                <i class="bi bi-person-x"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="access-empty">
                    <i class="bi bi-person-slash"></i>
                    <span>No system users with access.</span>
                </div>
            @endif

            {{-- Grant system user --}}
            <div class="access-footer">
                <div class="access-footer-label">Grant Access</div>
                <form method="POST"
                    action="{{ route('mcp.manager.dashboards.access.system-user.grant', $dashboard->uuid) }}">
                    @csrf
                    @if ($selectableUsers->isEmpty())
                        <p class="access-all-granted">All users already have access.</p>
                    @else
                        <div class="grant-row">
                            <select name="user_id" class="mgr-select mgr-select-sm" required>
                                <option value="">— Select a user —</option>
                                @foreach ($selectableUsers as $u)
                                    <option value="{{ $u->id }}">#{{ $u->id }} — {{ $u->name }}
                                        ({{ $u->email }})
                                    </option>
                                @endforeach
                            </select>
                            <button type="submit" class="mgr-btn mgr-btn-success icon-btn">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </div>
                        @error('user_id')
                            <div class="form-error mt-1">{{ $message }}</div>
                        @enderror
                    @endif
                </form>
            </div>
        </div>

        {{-- ── Custom Users ──────────────────────────────────────────────── --}}
        <div class="mgr-card">
            <div class="mgr-card-header">
                <span class="mgr-card-title"><i class="bi bi-envelope-check me-2"></i>Custom Users</span>
                <span class="count-badge">{{ $customUsers->count() }}</span>
            </div>

            @if ($customUsers->isNotEmpty())
                <div class="access-table-wrap">
                    <table class="access-table">
                        <thead>
                            <tr>
                                <th>Name / Email</th>
                                <th>Expires</th>
                                <th class="col-action"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($customUsers as $cu)
                                <tr>
                                    <td>
                                        <div class="user-name">{{ $cu->name }}</div>
                                        <div class="user-email">{{ $cu->email }}</div>
                                    </td>
                                    <td>
                                        @if ($cu->token_expires_at)
                                            @if ($cu->isTokenExpired())
                                                <span class="expiry-badge expired">Expired</span>
                                            @else
                                                <span
                                                    class="expiry-date">{{ $cu->token_expires_at->format('M j, Y') }}</span>
                                            @endif
                                        @else
                                            <span class="expiry-never">Never</span>
                                        @endif
                                    </td>
                                    <td>
                                        <form method="POST"
                                            action="{{ route('mcp.manager.dashboards.access.custom-user.revoke', [$dashboard->uuid, $cu->id]) }}"
                                            onsubmit="return confirm('Revoke invite for {{ addslashes($cu->email) }}?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="mgr-btn mgr-btn-danger icon-btn"
                                                title="Revoke invite">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="access-empty">
                    <i class="bi bi-envelope-slash"></i>
                    <span>No external invites yet.</span>
                </div>
            @endif

            {{-- Invite custom user --}}
            <div class="access-footer">
                <div class="access-footer-label">Invite External User</div>
                <form method="POST"
                    action="{{ route('mcp.manager.dashboards.access.custom-user.grant', $dashboard->uuid) }}">
                    @csrf
                    <div class="form-group mb-2">
                        <input type="text" name="name"
                            class="mgr-input mgr-input-sm @error('name') is-invalid @enderror" placeholder="Full name"
                            value="{{ old('name') }}" required maxlength="191">
                        @error('name')
                            <div class="form-error">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-group mb-2">
                        <input type="email" name="email"
                            class="mgr-input mgr-input-sm @error('email') is-invalid @enderror"
                            placeholder="Email address" value="{{ old('email') }}" required maxlength="191">
                        @error('email')
                            <div class="form-error">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-group mb-2">
                        <input type="password" name="password"
                            class="mgr-input mgr-input-sm @error('password') is-invalid @enderror"
                            placeholder="Access password (min 6 chars)" minlength="6" required>
                        @error('password')
                            <div class="form-error">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-hint mb-3">
                        A private link is generated once.
                        @if (config('mcp-dashboard-studio.manager.custom_user_token_ttl_days'))
                            Expires in <strong>{{ config('mcp-dashboard-studio.manager.custom_user_token_ttl_days') }}
                                days</strong>.
                        @endif
                    </div>
                    <button type="submit" class="mgr-btn mgr-btn-primary w-100">
                        <i class="bi bi-send me-1"></i>Generate Invite Link
                    </button>
                </form>
            </div>
        </div>

    </div>
    <script>
        function copyInviteLink() {

            const url = document.getElementById('inviteUrl').innerText.trim();
            const button = document.querySelector('.copy-invite-btn');

            navigator.clipboard.writeText(url)
                .then(() => {

                    button.innerHTML = `
                <i class="bi bi-check-lg"></i>
                Copied
            `;

                    button.classList.remove('mgr-btn-secondary');
                    button.classList.add('mgr-btn-success');


                    setTimeout(() => {

                        button.innerHTML = `
                    <i class="bi bi-clipboard"></i>
                    Copy
                `;

                        button.classList.remove('mgr-btn-success');
                        button.classList.add('mgr-btn-secondary');

                    }, 2000);

                })
                .catch(() => {

                    alert('Unable to copy link');

                });

        }
    </script>

@endsection
