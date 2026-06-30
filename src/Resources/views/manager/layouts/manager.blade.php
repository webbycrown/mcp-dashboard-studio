<!DOCTYPE html>
<html lang="en" data-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Manager') — MCP Dashboard Studio</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap"
        rel="stylesheet">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <script src="https://cdn.datatables.net/2.0.8/js/dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/2.0.8/js/dataTables.bootstrap5.min.js"></script>
    {{-- Bootstrap Icons --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    {{-- Published package assets --}}
    <link rel="stylesheet" href="/mcp-dashboard-studio/assets/css/style.css">
    <link rel="stylesheet" href="/mcp-dashboard-studio/assets/css/manager.css">
    <link rel="stylesheet" href="/mcp-dashboard-studio/assets/css/alerts.css">
    <link rel="stylesheet" href="/mcp-dashboard-studio/assets/css/bulk-actions.css">

    @stack('styles')

    <script>
        // Apply saved theme before render to avoid flash
        (function() {
            var t = localStorage.getItem('dashboard-studio-theme');
            if (t) document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</head>

<body>

    <div class="dashboard-wrapper">
        {{-- ── Top Navbar ───────────────────────────────────────────────────────── --}}
        <nav class="top-nav">
            <a href="{{ route('mcp.manager.dashboards.index') }}" class="nav-brand">
                <span class="brand-accent">Dashboard Studio</span> Manager
            </a>

            <div class="nav-actions">
                <a href="{{ route('mcp.manager.dashboards.index') }}" class="nav-link badge-active">
                    <i class="bi bi-bar-chart-fill"></i> Dashboards
                </a>
                <a href="{{ route('mcp.logout') }}" class="nav-link badge-red" style="border:1px solid var(--danger)">
                    <i class="bi-box-arrow-right"></i> Logout
                </a>
                <button id="mgr-theme-toggle" class="theme-toggle" title="Toggle dark/light mode">
                    <i class="bi bi-moon-fill"></i>
                </button>
            </div>
        </nav>

        {{-- ── Main Content ─────────────────────────────────────────────────────── --}}
        <div class="dashboard-main-content">

            {{-- Flash alerts --}}
            @if (session('success'))
                <script>
                    document.addEventListener('DOMContentLoaded', () => {

                        if (window.mgrBulkActions) {
                            window.mgrBulkActions.showAlert(
                                'success',
                                @json(session('success'))
                            );
                        }

                    });
                </script>
            @endif
            @if (session('error'))
                <div class="alert alert-error">
                    <i class="bi bi-exclamation-triangle-fill"></i> {{ session('error') }}
                </div>
            @endif
            @if (session('warning'))
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-circle-fill"></i> {{ session('warning') }}
                </div>
            @endif

            @yield('content')
        </div>
    </div>

    {{-- Published app.js (handles theme toggle + chart init) --}}
    <script src="/mcp-dashboard-studio/assets/js/app.js"></script>

    <script>
        // Manager-specific theme toggle wired to the navbar button
        document.getElementById('mgr-theme-toggle')?.addEventListener('click', function() {
            var html = document.documentElement;
            var current = html.getAttribute('data-theme') || 'dark';
            var next = current === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', next);
            localStorage.setItem('dashboard-studio-theme', next);

            var icon = this.querySelector('i');
            if (icon) {
                icon.className = next === 'dark' ? 'bi bi-moon-fill' : 'bi bi-sun-fill';
            }
        });
        // Sync icon on load
        (function() {
            var t = localStorage.getItem('dashboard-studio-theme');
            var btn = document.getElementById('mgr-theme-toggle');
            if (btn && t === 'light') {
                var icon = btn.querySelector('i');
                if (icon) icon.className = 'bi bi-sun-fill';
            }
        })();

        window.mcpTablePagination = {{ config('mcp-dashboard-studio.manager.per_page', 10) }};
    </script>

    <script src="/mcp-dashboard-studio/assets/js/dashboard-destroy.js"></script>
    @stack('scripts')
</body>

</html>
