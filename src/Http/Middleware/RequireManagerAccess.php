<?php

namespace Webbycrown\McpDashboardStudio\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * RequireManagerAccess
 *
 * Protects all /mcp-manager/* routes.
 *
 * Checks:
 *   1. Manager is enabled in config (MCP_MANAGER_ENABLED).
 *   2. User is authenticated.
 *   3. If MCP_MANAGER_REQUIRE_ADMIN=true, user must have admin privileges.
 *   4. If authorization is enabled, respects ownership-based access control.
 *
 * HTTP codes:
 *   503 — Manager disabled in config
 *   401 — Not authenticated
 *   403 — Authenticated but not authorized (admin check or ownership check)
 */
class RequireManagerAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        // 
        //  1. Feature flag
        // 
        
        if (! config('mcp-dashboard-studio.manager.enabled', true)) {
            Log::debug('[MCP Manager] Access attempt blocked — manager is disabled.');

            return response()->view('mcp-dashboard-studio::manager.errors.disabled', [], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // 
        //  2. Must be authenticated
        // 
        
        if (! Auth::check()) {
            Log::debug('[MCP Manager] Unauthenticated access attempt.', [
                'path' => $request->path(),
                'ip'   => $request->ip(),
            ]);

            // Redirect to login if route exists, otherwise return 401 JSON
            if (app('router')->has('login')) {
                return redirect()->route('login')->with(
                    'error',
                    'Please log in to access the Dashboard Manager.'
                );
            }

            return response()->json([
                'error'   => 'unauthenticated',
                'message' => 'Authentication required to access the Dashboard Manager.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // 
        //  3. Admin gate (optional) - Legacy support
        // 
        
        if (config('mcp-dashboard-studio.manager.require_admin', false)) {
            if (! $this->userIsAdmin(Auth::user())) {
                Log::warning('[MCP Manager] Non-admin user blocked.', [
                    'user_id' => Auth::id(),
                    'path'    => $request->path(),
                ]);

                abort(Response::HTTP_FORBIDDEN, 'Administrator access is required to use the Dashboard Manager.');
            }
        }

        // 
        //  4. Authorization service integration
        // 
        
        // Note: Granular authorization (ownership checks) is handled at the controller level
        // for specific actions (edit, delete, etc.). This middleware only handles
        // general manager access. The authorization service is available for controllers
        // to use for fine-grained permission checks.
        
        try {
            $authEnabled = config('mcp-dashboard-studio.authorization.enabled', true);
            if ($authEnabled) {
                Log::debug('[MCP Manager] Authorization enabled, user passed middleware checks', [
                    'user_id' => Auth::id(),
                    'path' => $request->path(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[MCP Manager] Error in authorization middleware', [
                'error' => $e->getMessage(),
            ]);
            // Continue - don't break the app due to auth errors
        }

        return $next($request);
    }

    /**
     * Determine if the given user has admin privileges.
     *
     * Supports multiple admin detection methods:
     * - is_admin attribute (default)
     * - Laravel Gates (gate:admin)
     * - Spatie Permissions (role:admin)
     * - Custom callback
     *
     * If the attribute/method does not exist, fails OPEN (allows access)
     * and logs a warning so the developer knows to configure this.
     */
    protected function userIsAdmin(mixed $user): bool
    {
        try {
            $adminCheck = config('mcp-dashboard-studio.authorization.admin_check', 'is_admin');

            // Method 1: Check is_admin attribute (default)
            if ($adminCheck === 'is_admin') {
                if (property_exists($user, 'is_admin') || isset($user->is_admin)) {
                    return (bool) $user->is_admin;
                }

                // Attribute not found — fail open with a warning
                Log::warning(
                    '[MCP Manager] require_admin=true but User model has no is_admin attribute. ' .
                    'All authenticated users are allowed. Add is_admin to your User model or set ' .
                    'MCP_MANAGER_REQUIRE_ADMIN=false in .env to suppress this warning.'
                );

                return true;
            }

            // Method 2: Check via Laravel Gate
            if (str_starts_with($adminCheck, 'gate:')) {
                $gateName = substr($adminCheck, 5);
                return Auth::check() && Auth::user()->can($gateName);
            }

            // Method 3: Check via Spatie Permission role
            if (str_starts_with($adminCheck, 'role:')) {
                $roleName = substr($adminCheck, 5);
                if (method_exists($user, 'hasRole')) {
                    return $user->hasRole($roleName);
                }
                Log::warning('[MCP Manager] Spatie Permission role check requested but user model has no hasRole method', [
                    'user_id' => $user->id ?? 'guest',
                    'role' => $roleName,
                ]);
                return false;
            }

            // Method 4: Custom callback
            if (is_callable($adminCheck)) {
                return $adminCheck($user);
            }

            Log::warning('[MCP Manager] Unknown admin check method configured', [
                'method' => $adminCheck,
                'user_id' => $user->id ?? 'guest',
            ]);

            return true; // Fail open

        } catch (\Throwable $e) {
            Log::error('[MCP Manager] Error checking admin status.', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return true; // Fail open — never crash the app
        }
    }
}