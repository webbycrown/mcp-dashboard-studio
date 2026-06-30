<!DOCTYPE html>
<html lang="en" data-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Restricted — MCP Dashboard Studio</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="/mcp-dashboard-studio/assets/css/style.css">
    <link rel="stylesheet" href="/mcp-dashboard-studio/assets/css/manager.css">
    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.13.1/font/bootstrap-icons.min.css"
        integrity="sha512-t7Few9xlddEmgd3oKZQahkNI4dS6l80+eGEzFQiqtyVYdvcSG2D3Iub77R20BdotfRPA9caaRkg1tyaJiPmO0g=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .error-wrap {
            max-width: 440px;
            width: 100%;
            text-align: center;
        }

        .error-icon {
            font-size: 3rem;
            margin-bottom: 1.25rem;
        }

        .error-code {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--amber);
            margin-bottom: 0.5rem;
        }

        .error-title {
            font-family: var(--font-heading);
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 0.75rem;
            letter-spacing: -0.03em;
        }

        .error-msg {
            font-size: 0.9rem;
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 1.75rem;
        }
    </style>
</head>

<body>
    <div class="dashboard-card error-wrap">
        <div class="error-icon"><i class="bi bi-lock-fill"></i></div>
        <div class="error-code">HTTP 401 — Unauthorized</div>
        <h1 class="error-title">Private Dashboard</h1>

        <p class="error-msg">
            {{ $message ?? 'This dashboard is private. Login or use a valid access link to continue.' }}</p>
        @if (app('router')->has('login'))
            <a href="{{ route('login') }}" class="filter-btn" style="text-decoration:none;">Sign In</a>
        @else
            <a href="{{ route('mcp.manager.dashboards.index') }}" class="filter-btn"
                style="text-decoration:none;">Return To
                Dashboard</a>
        @endif

    </div>
    <script>
        (function() {
            var t = localStorage.getItem('dashboard-studio-theme');
            if (t) document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</body>

</html>
