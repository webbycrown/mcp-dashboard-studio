<!DOCTYPE html>
<html lang="en" data-theme="dark">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Authorize — MCP Dashboard Studio</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/mcp-dashboard-studio/assets/css/style.css">
  <script>
    (function() {
      var t = localStorage.getItem('dashboard-studio-theme');
      if (t) document.documentElement.setAttribute('data-theme', t);
    })();
  </script>
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
      max-width: 440px;
      box-shadow: var(--card-shadow);
      position: relative;
      overflow: hidden;
    }

    .card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: var(--gradient-1);
    }

    .app-icon {
      width: 56px;
      height: 56px;
      border-radius: 14px;
      background: var(--gradient-1);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
      margin: 0 auto 1rem;
      color: #fff;
    }

    h1 {
      font-family: var(--font-heading);
      color: var(--text-primary);
      font-size: 1.3rem;
      font-weight: 800;
      letter-spacing: -0.03em;
      text-align: center;
      margin-bottom: .4rem;
    }

    p.sub {
      color: var(--text-secondary);
      text-align: center;
      font-size: .875rem;
      margin-bottom: 1.5rem;
    }

    .client-info {
      background: var(--bg-elevated);
      border: 1px solid var(--border-color);
      border-radius: 12px;
      padding: 1rem;
      margin-bottom: 1.5rem;
    }

    .client-info .name {
      color: var(--primary-light);
      font-weight: 700;
      font-size: 1rem;
    }

    .client-info .url {
      color: var(--text-muted);
      font-size: .75rem;
      margin-top: .25rem;
      word-break: break-all;
    }

    .scopes h3 {
      color: var(--text-muted);
      font-size: .72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .07em;
      margin-bottom: .75rem;
    }

    .scope-item {
      display: flex;
      align-items: flex-start;
      gap: .75rem;
      padding: .6rem 0;
      border-bottom: 1px solid var(--border-light);
    }

    .scope-item:last-child {
      border-bottom: none;
    }

    .scope-icon {
      font-size: 1.1rem;
      flex-shrink: 0;
      margin-top: 1px;
      color: var(--success);
    }

    .scope-text .label {
      color: var(--text-primary);
      font-size: .9rem;
      font-weight: 600;
    }

    .scope-text .desc {
      color: var(--text-muted);
      font-size: .8rem;
      margin-top: .15rem;
    }

    .actions {
      justify-content: space-between;
      display: flex;
      gap: 1rem;
      margin-top: 1.5rem;
    }

    .btn {
      flex: 1;
      padding: .8rem;
      border: none;
      border-radius: 10px;
      font-family: var(--font-body);
      font-size: .95rem;
      font-weight: 700;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: .4rem;
      transition: opacity .2s, transform .15s, box-shadow .2s;
    }

    .btn-approve {
      background: var(--gradient-1);
      color: #fff;
    }

    .btn-approve:hover {
      opacity: .92;
      transform: translateY(-1px);
      box-shadow: 0 6px 20px var(--primary-glow);
    }

    .btn-deny {
      background: var(--danger-glow);
      border: 1px solid rgba(239, 68, 68, .3);
      color: var(--danger);
    }

    .btn-deny:hover {
      background: rgba(239, 68, 68, .2);
      transform: translateY(-1px);
    }
  </style>
</head>

<body>
  <div class="card">
    <div class="app-icon"><i class="bi bi-shield-check"></i></div>
    <h1>Authorization Request</h1>
    <p class="sub"><strong style="color:var(--text-primary)">{{ $client->name }}</strong> wants to access your MCP Dashboard</p>

    <div class="client-info">
      <div class="name">{{ $client->name }}</div>
      <div class="url">{{ $request->input('redirect_uri') }}</div>
    </div>

    <div class="scopes">
      <h3>This will allow:</h3>
      @foreach($scopes as $scope)
      <div class="scope-item">
        <span class="scope-icon"><i class="bi bi-check-circle-fill"></i></span>
        <div class="scope-text">
          <div class="label">{{ $scope->id }}</div>
          <div class="desc">{{ $scope->description }}</div>
        </div>
      </div>
      @endforeach
    </div>

    <div class="actions">
      <form method="POST" action="{{ route('passport.authorizations.deny') }}">
        @csrf @method('DELETE')
        <input type="hidden" name="state" value="{{ $request->state }}">
        <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
        <input type="hidden" name="auth_token" value="{{ $authToken }}">
        <button type="submit" class="btn btn-deny">
          <i class="bi bi-x-circle"></i> Deny
        </button>
      </form>

      <form method="POST" action="{{ route('passport.authorizations.approve') }}">
        @csrf
        <input type="hidden" name="state" value="{{ $request->state }}">
        <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
        <input type="hidden" name="auth_token" value="{{ $authToken }}">
        <button type="submit" class="btn btn-approve">
          <i class="bi bi-check-circle"></i> Authorize
        </button>
      </form>
    </div>
  </div>
</body>

</html>