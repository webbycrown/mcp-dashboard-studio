<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0f1117">
    <title>@yield('title', 'MCP Dashboard Studio')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    {{-- Static CSS Asset — relative path auto-inherits page scheme (http/https) --}}
    <link rel="stylesheet" href="/mcp-dashboard-studio/assets/css/style.css">
    @yield('styles')

    {{-- Prevent FOUC: apply saved theme before paint --}}
    <script>
        (function() {
            var saved = localStorage.getItem('dashboard-studio-theme');
            if (saved) document.documentElement.setAttribute('data-theme', saved);
        })();

        window.mcpTablePagination = {{ config('mcp-dashboard-studio.manager.per_page', 10) }};

    </script>
</head>
<body>
    <div class="dashboard-wrapper">
        <header class="top-nav">
            <div class="nav-brand">
                <span class="brand-accent">Dashboard </span>Studio
            </div>
            <div class="nav-actions">
                <span class="badge badge-active">Live Connection</span>
                <button class="theme-toggle" id="theme-toggle" title="Toggle Light/Dark Mode" aria-label="Toggle theme">
                    🌙
                </button>
            </div>
        </header>

        <main class="dashboard-main-content">
            @yield('content')
        </main>
    </div>

    {{-- Static JS Asset — relative path auto-inherits page scheme (http/https) --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <script src="/mcp-dashboard-studio/assets/js/app.js"></script>
    @yield('scripts')
</body>
</html>
