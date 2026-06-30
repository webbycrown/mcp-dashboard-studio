<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Access — {{ $dashboard->name }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/mcp-dashboard-studio/assets/css/style.css">
    <link rel="stylesheet" href="/mcp-dashboard-studio/assets/css/manager.css">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 1.5rem;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
        }

        .login-brand {
            text-align: center;
            margin-bottom: 2rem;
        }

        .login-brand .lock-icon {
            font-size: 2.5rem;
            margin-bottom: 0.75rem;
        }

        .login-brand h1 {
            font-family: var(--font-heading);
            font-size: 1.4rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            margin-bottom: 0.35rem;
        }

        .login-brand p {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .login-brand .dashboard-name {
            color: var(--primary-light);
            font-weight: 600;
        }

        .login-body {
            padding: 1.75rem;
        }

        .email-display {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            padding: 0.65rem 0.9rem;
            background: var(--bg-elevated);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.9rem;
            color: var(--text-primary);
            margin-bottom: 1.25rem;
        }

        .email-display .icon {
            font-size: 1rem;
            opacity: 0.7;
        }

        .email-display .email-text {
            flex: 1;
            font-weight: 500;
        }

        .email-display .verified-badge {
            font-size: 0.68rem;
            font-weight: 600;
            background: var(--success-glow);
            color: var(--success);
            padding: 0.15rem 0.55rem;
            border-radius: 9999px;
            border: 1px solid rgba(16,185,129,0.25);
        }

        .login-footer {
            text-align: center;
            padding: 1rem 1.75rem 1.5rem;
            border-top: 1px solid var(--border-color);
            font-size: 0.78rem;
            color: var(--text-muted);
        }

        .password-wrapper {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1rem;
            color: var(--text-muted);
            padding: 0.25rem;
            transition: color 0.15s;
        }

        .password-toggle:hover {
            color: var(--text-primary);
        }
    </style>
    <script>(function(){var t=localStorage.getItem('dashboard-studio-theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body>
    <div class="dashboard-card login-card">

        <div class="login-brand" style="padding: 1.75rem 1.75rem 0;">
            <div class="lock-icon">🔒</div>
            <h1>Private Dashboard Access</h1>
            <p>
                You've been invited to view<br>
                <span class="dashboard-name">{{ $dashboard->name }}</span>
            </p>
        </div>

        <div class="login-body">

            {{-- Error flash --}}
            @if(session('error'))
                <div class="mgr-alert mgr-alert-error" style="margin-bottom:1.25rem;">
                    ⚠ {{ session('error') }}
                </div>
            @endif

            {{-- Who is this for --}}
            <div class="email-display">
                <span class="icon">👤</span>
                <span class="email-text">{{ $customUser->email }}</span>
                <span class="verified-badge">Invited</span>
            </div>

            <form method="POST"
                  action="{{ route('dashboard-studio.custom-login.verify', $dashboard->slug) }}"
                  id="custom-login-form">
                @csrf

                {{-- Hidden: preserve the access token through the form submission --}}
                <input type="hidden" name="access_token" value="{{ $access_token }}">

                {{-- Password --}}
                <div class="form-group" style="margin-bottom:1.25rem;">
                    <label class="form-label" for="cu-password">Password</label>
                    <div class="password-wrapper">
                        <input type="password"
                               id="cu-password"
                               name="password"
                               class="mgr-input"
                               placeholder="Enter your access password"
                               autocomplete="current-password"
                               required
                               autofocus>
                        <button type="button"
                                class="password-toggle"
                                onclick="togglePassword()"
                                title="Show/hide password"
                                aria-label="Toggle password visibility">
                            👁
                        </button>
                    </div>
                </div>

                <button type="submit" class="filter-btn" style="width:100%; justify-content:center; font-size:0.9rem; padding:0.75rem;">
                    Verify & Open Dashboard →
                </button>
            </form>
        </div>

        <div class="login-footer">
            @if($customUser->token_expires_at)
                Access link expires {{ $customUser->token_expires_at->format('M j, Y') }}.
            @else
                This access link does not expire.
            @endif
            &nbsp;·&nbsp;
            Not you? <a href="/" style="color:var(--primary-light); text-decoration:none;">Go back</a>
        </div>
    </div>

    <script>
        function togglePassword() {
            var input = document.getElementById('cu-password');
            input.type = input.type === 'password' ? 'text' : 'password';
        }
    </script>
</body>
</html>
