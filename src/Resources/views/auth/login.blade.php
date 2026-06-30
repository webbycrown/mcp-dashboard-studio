<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — MCP Dashboard Studio</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="/mcp-dashboard-studio/assets/css/style.css">
<script>(function(){var t=localStorage.getItem('dashboard-studio-theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<style>
  body {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
  }
  .card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--card-radius);
    padding: 2.5rem 2rem;
    width: 100%;
    max-width: 400px;
    box-shadow: var(--card-shadow);
    position: relative;
    overflow: hidden;
  }
  .card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: var(--gradient-1);
  }
  .logo { text-align: center; margin-bottom: 1.5rem; }
  .logo svg { width: 48px; height: 48px; }
  h1 {
    font-family: var(--font-heading);
    color: var(--text-primary);
    font-size: 1.5rem;
    font-weight: 800;
    letter-spacing: -0.03em;
    text-align: center;
    margin-bottom: 0.5rem;
  }
  p.sub {
    color: var(--text-secondary);
    text-align: center;
    font-size: 0.875rem;
    margin-bottom: 2rem;
  }
  label {
    display: block;
    color: var(--text-muted);
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    margin-bottom: 0.4rem;
  }
  input {
    width: 100%;
    padding: 0.7rem 0.95rem;
    background: var(--bg-subtle);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    color: var(--text-primary);
    font-family: var(--font-body);
    font-size: 0.95rem;
    transition: border-color 0.2s, box-shadow 0.2s;
    margin-bottom: 1.2rem;
  }
  input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-glow); }
  input::placeholder { color: var(--text-muted); }
  .btn {
    width: 100%;
    padding: 0.85rem;
    background: var(--gradient-1);
    border: none;
    border-radius: 10px;
    color: #fff;
    font-family: var(--font-body);
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    transition: opacity 0.2s, transform 0.15s, box-shadow 0.2s;
  }
  .btn:hover { opacity: 0.92; transform: translateY(-1px); box-shadow: 0 6px 20px var(--primary-glow); }
  .error {
    background: var(--danger-glow);
    border: 1px solid rgba(239,68,68,0.25);
    color: var(--danger);
    border-radius: 10px;
    padding: 0.75rem 1rem;
    font-size: 0.875rem;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }
  .badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--primary-bg);
    border: 1px solid rgba(99,102,241,0.25);
    color: var(--primary-light);
    border-radius: 9999px;
    padding: 4px 12px;
    font-size: 0.72rem;
    font-weight: 700;
    margin: 0 auto 1.5rem;
    display: flex;
    justify-content: center;
    width: fit-content;
  }
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
      <rect width="48" height="48" rx="12" fill="url(#g)"/>
      <path d="M14 24h20M24 14v20" stroke="#fff" stroke-width="3" stroke-linecap="round"/>
      <defs><linearGradient id="g" x1="0" y1="0" x2="48" y2="48" gradientUnits="userSpaceOnUse">
        <stop stop-color="#6366f1"/><stop offset="1" stop-color="#818cf8"/>
      </linearGradient></defs>
    </svg>
  </div>
  <h1>MCP Dashboard Studio</h1>
  <p class="sub">Sign in to authorize OAuth access</p>
  <span class="badge"><i class="bi bi-shield-lock-fill"></i> OAuth 2.1 + PKCE</span>

  @if(isset($errors) && $errors->any())
    <div class="error"><i class="bi bi-exclamation-triangle-fill"></i> {{ $errors->first() }}</div>
  @endif

  <form method="POST" action="{{ route('login') }}">
    @csrf
    <label for="email">Email</label>
    <input id="email" type="email" name="email" value="{{ old('email') }}"
           placeholder="you@example.com" required autofocus>

    <label for="password">Password</label>
    <input id="password" type="password" name="password"
           placeholder="••••••••" required>

    <button type="submit" class="btn">
      <i class="bi bi-box-arrow-in-right"></i> Sign In &amp; Authorize
    </button>
  </form>
</div>
</body>
</html>
