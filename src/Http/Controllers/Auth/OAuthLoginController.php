<?php

namespace Webbycrown\McpDashboardStudio\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * OAuth Login Controller (Package-owned)
 *
 * Provides a lightweight login flow required by Passport's /oauth/authorize
 * endpoint when the user is not yet authenticated.
 *
 * Only registered if `mcp-dashboard-studio.oauth.login_routes = true`
 * AND no `login` named route already exists in the host app.
 *
 * Host apps with their own auth system should set login_routes = false
 * and handle authentication themselves.
 */
class OAuthLoginController extends Controller
{
    public function showLogin(Request $request)
    {
        return view('mcp-dashboard-studio::auth.login');
    }

    public function login(Request $request)
    {
        try {
            $credentials = $request->validate([
                'email' => ['required', 'email'],
                'password' => ['required', 'string'],
            ]);

            if (Auth::attempt($credentials, true)) {
                $request->session()->regenerate();

                // ── Security: Admin-Only Consent Gate ────────────────────
                // If MCP_REQUIRE_ADMIN_CONSENT=true, reject non-admin users
                // before they can reach the OAuth consent screen.
                if (config('mcp-dashboard-studio.oauth.require_admin_for_consent', false)) {
                    if (! $this->userIsAdmin(Auth::user())) {
                        Auth::logout();
                        $request->session()->invalidate();
                        $request->session()->regenerateToken();

                        Log::warning('[MCP] Non-admin user blocked from OAuth consent', [
                            'email' => $request->email,
                        ]);

                        return back()
                            ->withInput($request->only('email'))
                            ->withErrors(['email' => 'Only administrators are allowed to authorize MCP access.']);
                    }
                }
                // ─────────────────────────────────────────────────────────

                // If Passport redirected the user here from a full OAuth authorize URL,
                // it stores it in url.intended. Use that directly — it contains all the
                // required params (client_id, PKCE, state, scope, redirect_uri).
                // If the user visited /login directly (no OAuth flow), fall back to home.
                $intended = $request->session()->pull('url.intended');

                if ($intended && str_contains($intended, 'client_id')) {
                    return redirect($intended);
                }

                // No OAuth flow in progress — redirect home instead of broken /oauth/authorize
                return redirect('/');
            }

            Log::debug('[MCP] Login failed for email: '.$request->email);

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'These credentials do not match our records.']);

        } catch (\Throwable $e) {
            Log::error('[MCP] Login error', ['error' => $e->getMessage()]);

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'An error occurred. Please try again.']);
        }
    }

    public function logout(Request $request)
    {
        try {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        } catch (\Throwable $e) {
            Log::error('[MCP] Logout error', ['error' => $e->getMessage()]);
        }

        return redirect('/');
    }

    /**
     * Determine if the given user has admin access.
     *
     * Checks the `is_admin` boolean attribute by default.
     * If the User model does not have this attribute, fails open (returns true)
     * and logs a configuration warning so developers know to customize this.
     *
     * To use a different admin check, publish the login view/controller
     * and override this logic.
     */
    protected function userIsAdmin($user): bool
    {
        try {
            if (property_exists($user, 'is_admin') || isset($user->is_admin)) {
                return (bool) $user->is_admin;
            }

            // Attribute not found on model — fail open with warning
            Log::warning(
                '[MCP] require_admin_for_consent is enabled but User model has no is_admin attribute. '.
                'All authenticated users will be allowed through. '.
                'Add is_admin to your User model or publish the MCP login controller to customize.'
            );

            return true;

        } catch (\Throwable $e) {
            Log::error('[MCP] Error checking admin status', ['error' => $e->getMessage()]);

            return true; // fail open — never crash the app
        }
    }
}
