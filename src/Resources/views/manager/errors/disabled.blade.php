<!DOCTYPE html>
<html lang="en" data-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Disabled — MCP Dashboard Studio</title>
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
            color: var(--text-muted);
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
        }
    </style>
</head>

<body>
    <div class="dashboard-card error-wrap">
        <div class="error-icon"><i class="bi bi-gear"></i>
        </div>
        <div class="error-code">HTTP 503 — Service Unavailable</div>
        <h1 class="error-title">Manager Disabled</h1>
        <p class="error-msg">
            The Dashboard Manager is currently disabled.<br>
            Set <span class="mgr-code">MCP_MANAGER_ENABLED=true</span> in your
            <span class="mgr-code">.env</span> to enable it.
        </p>
    </div>
    <script>
        (function() {
            var t = localStorage.getItem('dashboard-studio-theme');
            if (t) document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</body>

</html>
