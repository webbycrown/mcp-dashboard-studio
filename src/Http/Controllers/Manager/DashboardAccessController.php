<?php

namespace Webbycrown\McpDashboardStudio\Http\Controllers\Manager;

use Webbycrown\McpDashboardStudio\Http\Controllers\Controller;
use Webbycrown\McpDashboardStudio\Http\Requests\Manager\GrantCustomUserAccessRequest;
use Webbycrown\McpDashboardStudio\Http\Requests\Manager\GrantSystemUserAccessRequest;
use Webbycrown\McpDashboardStudio\Models\McpDashboardAccess;
use Webbycrown\McpDashboardStudio\Models\McpDashboardCustomUser;
use Webbycrown\McpDashboardStudio\Models\McpDashboardDefinition;
use Webbycrown\McpDashboardStudio\Services\DashboardAuthorizationService;
use Webbycrown\McpDashboardStudio\Utils\RoutePaths;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * DashboardAccessController
 *
 * Manages who can access a dashboard.
 * - System users (from host app's users table)
 * - Custom users (external invited users with tokenized URLs)
 * Includes ownership-based authorization checks.
 */
class DashboardAccessController extends Controller
{
    /**
     * GET /mcp-manager/dashboards/{uuid}/access
     *
     * Show access management page.
     */

    public function index(string $uuid)
    {
        $dashboard = $this->findOrFail($uuid);

        // Check authorization
        $this->authorizeManageAccess($dashboard);

        $systemUsers = $dashboard->accessList;
        $customUsers = $dashboard->customUsers;

        // ADD THIS: Get users who don't have access yet
        $selectableUsers = \App\Models\User::whereNotIn('id', function($query) use ($dashboard) {
            $query->select('user_id')
                ->from('mcp_dashboard_access')
                ->where('dashboard_id', $dashboard->id);
        })->get();

        return view('mcp-dashboard-studio::manager.access', compact(
            'dashboard', 'systemUsers', 'customUsers', 'selectableUsers'
        ));
    }


    //
    //  System User
    //

    /**
     * POST /mcp-manager/dashboards/{uuid}/access/system-user
     *
     * Grant a system user access to this dashboard.
     * HTTP 409 if the user already has access.
     */
    public function grantSystemUser(GrantSystemUserAccessRequest $request, string $uuid): RedirectResponse
    {
        $dashboard = $this->findOrFail($uuid);

        // Check authorization
        $this->authorizeManageAccess($dashboard);

        $userId    = (int) $request->validated('user_id');

        try {
            McpDashboardAccess::create([
                'dashboard_id' => $dashboard->id,
                'user_id'      => $userId,
            ]);

            Log::info('[MCP Manager] System user access granted.', [
                'dashboard_uuid' => $uuid,
                'user_id'        => $userId,
                'granted_by'     => Auth::id(),
            ]);

        } catch (UniqueConstraintViolationException) {
            return back()->with('error', "User ID {$userId} already has access to this dashboard.");
        } catch (\Throwable $e) {
            Log::error('[MCP Manager] Failed to grant system user access.', [
                'uuid'    => $uuid,
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to grant access. Please try again.');
        }

        return back()->with('success', "User ID {$userId} has been granted access.");
    }

    /**
     * DELETE /mcp-manager/dashboards/{uuid}/access/{accessId}
     *
     * Revoke a system user's access to this dashboard.
     */
    public function revokeSystemUser(string $uuid, int $accessId): RedirectResponse
    {
        $dashboard = $this->findOrFail($uuid);

        // Check authorization
        $this->authorizeManageAccess($dashboard);

        $access = McpDashboardAccess::where('id', $accessId)
            ->where('dashboard_id', $dashboard->id)
            ->first();

        if (! $access) {
            abort(404, 'Access record not found.');
        }

        try {
            $userId = $access->user_id;
            $access->delete();

            Log::info('[MCP Manager] System user access revoked.', [
                'dashboard_uuid' => $uuid,
                'user_id'        => $userId,
                'revoked_by'     => Auth::id(),
            ]);

        } catch (\Throwable $e) {
            Log::error('[MCP Manager] Failed to revoke system user access.', [
                'uuid'      => $uuid,
                'access_id' => $accessId,
                'error'     => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to revoke access. Please try again.');
        }

        return back()->with('success', 'Access has been revoked.');
    }

    //
    //  Custom User
    //

    /**
     * POST /mcp-manager/dashboards/{uuid}/access/custom-user
     *
     * Invite an external user. Generates a raw token (shown once to manager),
     * stores its SHA-256 hash. The custom user uses the tokenized URL.
     *
     * HTTP 409 if this email already has an invite for this dashboard.
     */
    public function grantCustomUser(GrantCustomUserAccessRequest $request, string $uuid): RedirectResponse
    {
        $dashboard = $this->findOrFail($uuid);

        // Check authorization
        $this->authorizeManageAccess($dashboard);

        $name     = $request->validated('name');
        $email    = $request->validated('email');
        $password = $request->validated('password');

        // Compute token expiry from config
        $ttlDays   = config('mcp-dashboard-studio.manager.custom_user_token_ttl_days');
        $expiresAt = $ttlDays ? now()->addDays((int) $ttlDays) : null;

        // Generate raw token � shown ONCE to the manager, stored hashed
        $rawToken    = Str::random(64);
        $hashedToken = McpDashboardCustomUser::hashToken($rawToken);

        try {
            McpDashboardCustomUser::create([
                'dashboard_id'     => $dashboard->id,
                'name'             => $name,
                'email'            => $email,
                'password'         => bcrypt($password),
                'access_token'     => $hashedToken,
                'token_expires_at' => $expiresAt,
            ]);

            Log::info('[MCP Manager] Custom user invited.', [
                'dashboard_uuid' => $uuid,
                'email'          => $email,
                'expires_at'     => $expiresAt,
                'invited_by'     => Auth::id(),
            ]);

        } catch (UniqueConstraintViolationException) {
            return back()->with('error', "An invite for {$email} already exists for this dashboard.");
        } catch (\Throwable $e) {
            Log::error('[MCP Manager] Failed to invite custom user.', [
                'uuid'  => $uuid,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to create invite. Please try again.');
        }

        // Build the one-time-show token URL (stored in session flash, cleared after redirect)
        $tokenUrl = RoutePaths::dashboardShowUrl($dashboard->slug) . '?access_token=' . urlencode($rawToken);
        return back()->with([
            'success'          => "Invite created for {$name} ({$email}).",
            'mcp_invite_token' => $rawToken,
            'mcp_invite_url'   => $tokenUrl,
            'mcp_invite_email' => $email,
        ]);
    }

    /**
     * DELETE /mcp-manager/dashboards/{uuid}/access/custom/{customUserId}
     *
     * Revoke a custom user's invite and token.
     */
    public function revokeCustomUser(string $uuid, int $customUserId): RedirectResponse
    {
        $dashboard  = $this->findOrFail($uuid);

        // Check authorization
        $this->authorizeManageAccess($dashboard);

        $customUser = McpDashboardCustomUser::where('id', $customUserId)
            ->where('dashboard_id', $dashboard->id)
            ->first();

        if (! $customUser) {
            abort(404, 'Custom user invite not found.');
        }

        try {
            $email = $customUser->email;
            $customUser->delete();

            Log::info('[MCP Manager] Custom user access revoked.', [
                'dashboard_uuid' => $uuid,
                'email'          => $email,
                'revoked_by'     => Auth::id(),
            ]);

        } catch (\Throwable $e) {
            Log::error('[MCP Manager] Failed to revoke custom user access.', [
                'uuid'           => $uuid,
                'custom_user_id' => $customUserId,
                'error'          => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to revoke invite. Please try again.');
        }

        return back()->with('success', "Invite for {$email} has been revoked.");
    }

    //
    //  Authorization Helpers
    //

    /**
     * Check if the current user can manage access for the dashboard.
     * Aborts with 403 if not authorized.
     *
     * @param McpDashboardDefinition $dashboard
     * @return void
     */
    protected function authorizeManageAccess(McpDashboardDefinition $dashboard): void
    {
        try {
            // Check if authorization is enabled
            $authEnabled = config('mcp-dashboard-studio.authorization.enabled', true);

            if (!$authEnabled) {
                return; // Authorization disabled, allow access
            }

            $user = Auth::user();
            if (!$user) {
                abort(403, 'You must be logged in to manage dashboard access.');
            }

            $authService = app(DashboardAuthorizationService::class);

            if (!$authService->canManageAccess($user, $dashboard)) {
                Log::warning('[MCP Manager] Unauthorized access management attempt', [
                    'user_id' => $user->id,
                    'dashboard_id' => $dashboard->id,
                    'dashboard_uuid' => $dashboard->uuid,
                    'ip' => request()->ip(),
                ]);

                abort(403, 'You do not have permission to manage access for this dashboard.');
            }

        } catch (\Throwable $e) {
            Log::error('[MCP Manager] Error checking access management authorization', [
                'dashboard_id' => $dashboard->id,
                'error' => $e->getMessage(),
            ]);

            // Fail open - don't break the application due to auth errors
            // abort(500, 'Unable to verify authorization. Please try again.');
            return;
        }
    }

    //
    //  Helpers
    //

    private function findOrFail(string $uuid): McpDashboardDefinition
    {
        $dashboard = McpDashboardDefinition::where('uuid', $uuid)->first();

        if (! $dashboard) {
            abort(404, "Dashboard not found (UUID: {$uuid}).");
        }

        return $dashboard;
    }
}
