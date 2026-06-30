<?php

namespace Webbycrown\McpDashboardStudio\Http\Controllers;

use Webbycrown\McpDashboardStudio\Models\McpDashboardCustomUser;
use Webbycrown\McpDashboardStudio\Models\McpDashboardDefinition;
use Webbycrown\McpDashboardStudio\Support\RoutePaths;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * CustomUserLoginController
 *
 * Handles the password gate for custom (externally invited) users
 * who access a private dashboard via a tokenized URL.
 *
 * Flow:
 *   1. User opens:  /dashboard-studio/{slug}?access_token=XXX
 *   2. CheckDashboardAccess validates the token and redirects here.
 *   3. This controller shows a login form (email pre-filled, password required).
 *   4. On success → store session key, redirect back to the dashboard URL.
 *   5. Subsequent visits check the session — no password re-entry needed.
 *
 * Routes (defined in web.php, inside `web` middleware group):
 *   GET  /dashboard-studio/{slug}/custom-login
 *   POST /dashboard-studio/{slug}/custom-login
 */
class CustomUserLoginController extends Controller
{
    /**
     * GET /dashboard-studio/{slug}/custom-login?access_token=XXX
     *
     * Show the password entry form for the custom user.
     * The email is pre-filled (read-only) from the token lookup.
     */
    public function showForm(Request $request, string $slug)
    {
        $dashboard = $this->findDashboard($slug);
        $rawToken  = $request->query('access_token', '');

        $customUser = $this->findCustomUser($dashboard->id, $rawToken);

        if (! $customUser) {
            return response()->view('mcp-dashboard-studio::manager.errors.private', [
                'dashboard' => $dashboard,
                'message'   => 'The access link is invalid. Please request a new invite.',
            ], 401);
        }

        if ($customUser->isTokenExpired()) {
            return response()->view('mcp-dashboard-studio::manager.errors.private', [
                'dashboard' => $dashboard,
                'message'   => 'Your access link has expired. Please request a new invite.',
            ], 401);
        }

        return view('mcp-dashboard-studio::manager.custom-user-login', [
            'dashboard'    => $dashboard,
            'customUser'   => $customUser,
            'access_token' => $rawToken,
        ]);
    }

    /**
     * POST /dashboard-studio/{slug}/custom-login
     *
     * Verify the submitted password. On success, store the session
     * auth key and redirect to the live dashboard URL.
     */
    public function verify(Request $request, string $slug): RedirectResponse
    {
        $request->validate([
            'access_token' => ['required', 'string'],
            'password'     => ['required', 'string'],
        ]);

        $dashboard  = $this->findDashboard($slug);
        $rawToken   = $request->input('access_token');

        $customUser = $this->findCustomUser($dashboard->id, $rawToken);

        if (! $customUser || $customUser->isTokenExpired()) {
            Log::debug('[MCP CustomLogin] Invalid or expired token on password verify.', [
                'slug' => $slug,
                'ip'   => $request->ip(),
            ]);

            return back()
                ->withInput(['access_token' => $rawToken])
                ->with('error', 'Your access link is invalid or has expired.');
        }

        // Verify the submitted password against the stored bcrypt hash
        if (! $customUser->verifyPassword($request->input('password'))) {
            Log::debug('[MCP CustomLogin] Wrong password.', [
                'slug'  => $slug,
                'email' => $customUser->email,
                'ip'    => $request->ip(),
            ]);

            return back()
                ->withInput(['access_token' => $rawToken])
                ->with('error', 'Incorrect password. Please try again.');
        }

        // ✅ Password correct — record this in the session for this dashboard
        // Key format: mcp_custom_access.{dashboard_id} = custom_user_id
        session(["mcp_custom_access.{$dashboard->id}" => $customUser->id]);

        Log::info('[MCP CustomLogin] Custom user authenticated.', [
            'slug'           => $slug,
            'email'          => $customUser->email,
            'custom_user_id' => $customUser->id,
        ]);

        return redirect()->to(RoutePaths::dashboardShowUrl($slug));
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function findDashboard(string $slug): McpDashboardDefinition
    {
        $dashboard = McpDashboardDefinition::where('slug', $slug)->first();

        if (! $dashboard) {
            abort(404, 'Dashboard not found.');
        }

        return $dashboard;
    }

    private function findCustomUser(int $dashboardId, string $rawToken): ?McpDashboardCustomUser
    {
        if (empty($rawToken)) {
            return null;
        }

        return McpDashboardCustomUser::where('dashboard_id', $dashboardId)
            ->where('access_token', McpDashboardCustomUser::hashToken($rawToken))
            ->first();
    }
}
