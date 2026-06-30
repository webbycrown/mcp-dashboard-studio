<?php

namespace Webbycrown\McpDashboardStudio\Http\Middleware;

use Webbycrown\McpDashboardStudio\Models\McpDashboardCustomUser;
use Webbycrown\McpDashboardStudio\Models\McpDashboardDefinition;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckDashboardAccess
 *
 * Applied to GET /dashboard-studio/{slug}.
 *
 * Decision tree (in priority order):
 *   1. Dashboard not found                                    → 404
 *   2. status = 'public'                                      → pass through
 *   3. status = 'private':
 *      a. Session has mcp_custom_access.{id}                 → custom user already logged in → pass
 *      b. Auth::user() in mcp_dashboard_access pivot         → pass
 *      c. Auth::user() NOT in access list                    → 403
 *      d. ?access_token= present, token valid & not expired  → redirect to password form
 *      e. ?access_token= present but invalid/expired         → 401
 *      f. Nothing matches                                     → 401
 */
class CheckDashboardAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('slug');

        // ── 1. Find the dashboard ─────────────────────────────────────────
        try {
            /** @var McpDashboardDefinition|null $dashboard */
            $dashboard = McpDashboardDefinition::where('slug', $slug)->first();
        } catch (\Throwable $e) {
            Log::error('[MCP Access] DB error while looking up dashboard.', [
                'slug'  => $slug,
                'error' => $e->getMessage(),
            ]);
            abort(Response::HTTP_INTERNAL_SERVER_ERROR, 'Unable to load dashboard.');
        }

        if (! $dashboard) {
            abort(Response::HTTP_NOT_FOUND, 'Dashboard not found.');
        }

        // ── 2. Public dashboards — always allow ───────────────────────────
        if ($dashboard->isPublic()) {
            return $next($request);
        }

        // ── 3a. Session: custom user already authenticated for this dashboard ──
        $sessionKey    = "mcp_custom_access.{$dashboard->id}";
        $sessionUserId = session($sessionKey);

        if ($sessionUserId) {
            $valid = McpDashboardCustomUser::where('id', $sessionUserId)
                ->where('dashboard_id', $dashboard->id)
                ->exists();

            if ($valid) {
                Log::debug('[MCP Access] Custom user session valid.', [
                    'slug'           => $slug,
                    'custom_user_id' => $sessionUserId,
                ]);
                return $next($request);
            }

            // Session key stale (user revoked) — clear it
            $request->session()->forget($sessionKey);
        }

        // ── 3b/c. Authenticated system user ──────────────────────────────
        if (Auth::check()) {
            return $this->validateSystemUserAccess($request, $next, $dashboard);
        }

        // ── 3d/e. Access token in query string ────────────────────────────
        $rawToken = $request->query('access_token');

        if ($rawToken) {
            return $this->handleTokenAccess($request, $dashboard, $rawToken);
        }

        // ── 3f. Nothing matched ───────────────────────────────────────────
        Log::debug('[MCP Access] Unauthenticated attempt on private dashboard.', [
            'slug' => $slug,
            'ip'   => $request->ip(),
        ]);

        return $this->deny401($dashboard, 'Login or use a valid access link to view this dashboard.');
    }

    // ─── Token → Redirect to Password Form ───────────────────────────────

    private function handleTokenAccess(
        Request $request,
        McpDashboardDefinition $dashboard,
        string $rawToken
    ): Response {
        try {
            $customUser = McpDashboardCustomUser::where('dashboard_id', $dashboard->id)
                ->where('access_token', McpDashboardCustomUser::hashToken($rawToken))
                ->first();
        } catch (\Throwable $e) {
            Log::error('[MCP Access] DB error validating custom user token.', [
                'dashboard_id' => $dashboard->id,
                'error'        => $e->getMessage(),
            ]);
            abort(Response::HTTP_INTERNAL_SERVER_ERROR, 'Unable to validate access token.');
        }

        if (! $customUser) {
            Log::debug('[MCP Access] Invalid custom user token.', [
                'slug' => $dashboard->slug,
                'ip'   => $request->ip(),
            ]);
            return $this->deny401($dashboard, 'The access link is invalid. Please request a new invite.');
        }

        if ($customUser->isTokenExpired()) {
            Log::debug('[MCP Access] Expired custom user token.', [
                'slug'       => $dashboard->slug,
                'email'      => $customUser->email,
                'expired_at' => $customUser->token_expires_at,
            ]);
            return $this->deny401($dashboard, 'Your access link has expired. Please request a new invite.');
        }

        // ✅ Token valid → redirect to password form (NOT passed through directly)
        Log::debug('[MCP Access] Valid token — redirecting to password form.', [
            'slug'  => $dashboard->slug,
            'email' => $customUser->email,
        ]);

        return redirect()->to(
            route('dashboard-studio.custom-login', ['slug' => $dashboard->slug])
            . '?access_token=' . urlencode($rawToken)
        );
    }

    // ─── System User ──────────────────────────────────────────────────────

    private function validateSystemUserAccess(
        Request $request,
        Closure $next,
        McpDashboardDefinition $dashboard
    ): Response {
        $userId = Auth::id();

        try {
            $hasAccess = $dashboard->accessList()
                ->where('user_id', $userId)
                ->exists();
        } catch (\Throwable $e) {
            Log::error('[MCP Access] DB error checking system user access.', [
                'dashboard_id' => $dashboard->id,
                'user_id'      => $userId,
                'error'        => $e->getMessage(),
            ]);
            abort(Response::HTTP_INTERNAL_SERVER_ERROR, 'Unable to verify access.');
        }

        if (! $hasAccess) {
            Log::debug('[MCP Access] Authenticated user not in access list.', [
                'slug'    => $dashboard->slug,
                'user_id' => $userId,
            ]);

            return response()->view('mcp-dashboard-studio::manager.errors.forbidden', [
                'dashboard' => $dashboard,
                'message'   => 'You do not have permission to view this private dashboard.',
            ], Response::HTTP_FORBIDDEN);
        }

        Log::debug('[MCP Access] System user access granted.', [
            'slug'    => $dashboard->slug,
            'user_id' => $userId,
        ]);

        return $next($request);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function deny401(McpDashboardDefinition $dashboard, string $reason): Response
    {
        return response()->view('mcp-dashboard-studio::manager.errors.private', [
            'dashboard' => $dashboard,
            'message'   => $reason,
        ], Response::HTTP_UNAUTHORIZED);
    }
}
