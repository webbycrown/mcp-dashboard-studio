<?php

namespace Webbycrown\McpDashboardStudio\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Webbycrown\McpDashboardStudio\Models\McpDashboardDefinition;

/**
 * DashboardAuthorizationService
 *
 * Centralized authorization service for dashboard access control.
 * Provides flexible authorization logic that can integrate with
 * different permission systems (default, Spatie, custom gates).
 */
class DashboardAuthorizationService
{
    /**
     * Check if user can view a specific dashboard.
     *
     * @param mixed $user The authenticated user
     * @param McpDashboardDefinition $dashboard The dashboard to check
     * @return bool
     */
    public function canViewDashboard($user, McpDashboardDefinition $dashboard): bool
    {
        try {
            // Admin override - can view all dashboards
            if ($this->isAdmin($user)) {
                Log::debug('[MCP Auth] Admin viewing dashboard', [
                    'user_id' => $user->id ?? 'guest',
                    'dashboard_id' => $dashboard->id,
                ]);
                return true;
            }

            // Dashboard creator can always view their own dashboard
            if ($this->isDashboardOwner($user, $dashboard)) {
                Log::debug('[MCP Auth] Creator viewing own dashboard', [
                    'user_id' => $user->id ?? 'guest',
                    'dashboard_id' => $dashboard->id,
                ]);
                return true;
            }

            // Check if user has explicit access via mcp_dashboard_access table
            if ($this->hasExplicitAccess($user, $dashboard)) {
                Log::debug('[MCP Auth] User with explicit access viewing dashboard', [
                    'user_id' => $user->id ?? 'guest',
                    'dashboard_id' => $dashboard->id,
                ]);
                return true;
            }

            // Public dashboards can be viewed by anyone
            if ($dashboard->isPublic()) {
                Log::debug('[MCP Auth] Public dashboard viewed', [
                    'user_id' => $user->id ?? 'guest',
                    'dashboard_id' => $dashboard->id,
                ]);
                return true;
            }

            Log::debug('[MCP Auth] Access denied for dashboard view', [
                'user_id' => $user->id ?? 'guest',
                'dashboard_id' => $dashboard->id,
                'reason' => 'Not owner, not admin, no explicit access, not public',
            ]);

            return false;

        } catch (\Throwable $e) {
            Log::error('[MCP Auth] Error checking view permission', [
                'user_id' => $user->id ?? 'guest',
                'dashboard_id' => $dashboard->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            // Fail open - don't break the application due to auth errors
            return false;
        }
    }

    /**
     * Check if user can edit a specific dashboard.
     *
     * @param mixed $user The authenticated user
     * @param McpDashboardDefinition $dashboard The dashboard to check
     * @return bool
     */
    public function canEditDashboard($user, McpDashboardDefinition $dashboard): bool
    {
        try {
            // Admin override - can edit all dashboards
            if ($this->isAdmin($user)) {
                Log::debug('[MCP Auth] Admin editing dashboard', [
                    'user_id' => $user->id ?? 'guest',
                    'dashboard_id' => $dashboard->id,
                ]);
                return true;
            }

            // Dashboard creator can edit their own dashboard
            if ($this->isDashboardOwner($user, $dashboard)) {
                Log::debug('[MCP Auth] Creator editing own dashboard', [
                    'user_id' => $user->id ?? 'guest',
                    'dashboard_id' => $dashboard->id,
                ]);
                return true;
            }

            // Check if user has explicit edit access via mcp_dashboard_access table
            if ($this->hasExplicitAccess($user, $dashboard)) {
                Log::debug('[MCP Auth] User with explicit access editing dashboard', [
                    'user_id' => $user->id ?? 'guest',
                    'dashboard_id' => $dashboard->id,
                ]);
                return true;
            }

            Log::debug('[MCP Auth] Access denied for dashboard edit', [
                'user_id' => $user->id ?? 'guest',
                'dashboard_id' => $dashboard->id,
                'reason' => 'Not owner, not admin, no explicit access',
            ]);

            return false;

        } catch (\Throwable $e) {
            Log::error('[MCP Auth] Error checking edit permission', [
                'user_id' => $user->id ?? 'guest',
                'dashboard_id' => $dashboard->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return false;
        }
    }

    /**
     * Check if user can delete a specific dashboard.
     *
     * @param mixed $user The authenticated user
     * @param McpDashboardDefinition $dashboard The dashboard to check
     * @return bool
     */
    public function canDeleteDashboard($user, McpDashboardDefinition $dashboard): bool
    {
        try {
            // Admin override - can delete all dashboards
            if ($this->isAdmin($user)) {
                Log::debug('[MCP Auth] Admin deleting dashboard', [
                    'user_id' => $user->id ?? 'guest',
                    'dashboard_id' => $dashboard->id,
                ]);
                return true;
            }

            // Dashboard creator can delete their own dashboard
            if ($this->isDashboardOwner($user, $dashboard)) {
                Log::debug('[MCP Auth] Creator deleting own dashboard', [
                    'user_id' => $user->id ?? 'guest',
                    'dashboard_id' => $dashboard->id,
                ]);
                return true;
            }

            Log::debug('[MCP Auth] Access denied for dashboard delete', [
                'user_id' => $user->id ?? 'guest',
                'dashboard_id' => $dashboard->id,
                'reason' => 'Not owner, not admin',
            ]);

            return false;

        } catch (\Throwable $e) {
            Log::error('[MCP Auth] Error checking delete permission', [
                'user_id' => $user->id ?? 'guest',
                'dashboard_id' => $dashboard->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return false;
        }
    }

    /**
     * Check if user can manage access (grant/revoke) for a dashboard.
     *
     * @param mixed $user The authenticated user
     * @param McpDashboardDefinition $dashboard The dashboard to check
     * @return bool
     */
    public function canManageAccess($user, McpDashboardDefinition $dashboard): bool
    {
        try {
            // Admin override - can manage access for all dashboards
            if ($this->isAdmin($user)) {
                Log::debug('[MCP Auth] Admin managing dashboard access', [
                    'user_id' => $user->id ?? 'guest',
                    'dashboard_id' => $dashboard->id,
                ]);
                return true;
            }

            // Dashboard creator can manage access for their own dashboard
            if ($this->isDashboardOwner($user, $dashboard)) {
                Log::debug('[MCP Auth] Creator managing own dashboard access', [
                    'user_id' => $user->id ?? 'guest',
                    'dashboard_id' => $dashboard->id,
                ]);
                return true;
            }

            Log::debug('[MCP Auth] Access denied for dashboard access management', [
                'user_id' => $user->id ?? 'guest',
                'dashboard_id' => $dashboard->id,
                'reason' => 'Not owner, not admin',
            ]);

            return false;

        } catch (\Throwable $e) {
            Log::error('[MCP Auth] Error checking access management permission', [
                'user_id' => $user->id ?? 'guest',
                'dashboard_id' => $dashboard->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return false;
        }
    }

    /**
     * Check if user can view all dashboards (admin capability).
     *
     * @param mixed $user The authenticated user
     * @return bool
     */
    public function canViewAllDashboards($user): bool
    {
        try {
            return $this->isAdmin($user);
        } catch (\Throwable $e) {
            Log::error('[MCP Auth] Error checking view all permission', [
                'user_id' => $user->id ?? 'guest',
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check if user is the dashboard creator.
     *
     * @param mixed $user The authenticated user
     * @param McpDashboardDefinition $dashboard The dashboard to check
     * @return bool
     */
    public function isDashboardOwner($user, McpDashboardDefinition $dashboard): bool
    {
        try {
            if (!$user || !isset($user->id)) {
                return false;
            }

            return $dashboard->created_by === $user->id;
        } catch (\Throwable $e) {
            Log::error('[MCP Auth] Error checking dashboard ownership', [
                'user_id' => $user->id ?? 'guest',
                'dashboard_id' => $dashboard->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check if user has explicit access via mcp_dashboard_access table.
     *
     * @param mixed $user The authenticated user
     * @param McpDashboardDefinition $dashboard The dashboard to check
     * @return bool
     */
    protected function hasExplicitAccess($user, McpDashboardDefinition $dashboard): bool
    {
        try {
            if (!$user || !isset($user->id)) {
                return false;
            }

            return $dashboard->accessList()
                ->where('user_id', $user->id)
                ->exists();
        } catch (\Throwable $e) {
            Log::error('[MCP Auth] Error checking explicit access', [
                'user_id' => $user->id ?? 'guest',
                'dashboard_id' => $dashboard->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check if user is an admin based on configuration.
     *
     * Supports multiple admin detection methods:
     * - is_admin attribute (default)
     * - Laravel Gates
     * - Spatie Permissions
     * - Custom callback
     *
     * @param mixed $user The authenticated user
     * @return bool
     */
    protected function isAdmin($user): bool
    {
        try {
            if (!$user) {
                return false;
            }

            $adminCheck = config('mcp-dashboard-studio.authorization.admin_check', 'is_admin');

            // Method 1: Check is_admin attribute (default)
            if ($adminCheck === 'is_admin') {
                if (property_exists($user, 'is_admin') || isset($user->is_admin)) {
                    return (bool) $user->is_admin;
                }

                // If is_admin doesn't exist, check config for fallback
                if (config('mcp-dashboard-studio.manager.require_admin', false)) {
                    Log::warning('[MCP Auth] require_admin=true but User model has no is_admin attribute', [
                        'user_id' => $user->id ?? 'guest',
                    ]);
                }

                return false;
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
                Log::warning('[MCP Auth] Spatie Permission role check requested but user model has no hasRole method', [
                    'user_id' => $user->id ?? 'guest',
                    'role' => $roleName,
                ]);
                return false;
            }

            // Method 4: Custom callback
            if (is_callable($adminCheck)) {
                return $adminCheck($user);
            }

            Log::warning('[MCP Auth] Unknown admin check method configured', [
                'method' => $adminCheck,
                'user_id' => $user->id ?? 'guest',
            ]);

            return false;

        } catch (\Throwable $e) {
            Log::error('[MCP Auth] Error checking admin status', [
                'user_id' => $user->id ?? 'guest',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            // Fail open - don't break the application
            return false;
        }
    }

    /**
     * Get dashboards that the user can view.
     *
     * @param mixed $user The authenticated user
     * @param bool $includeTrashed Whether to include trashed dashboards
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getAccessibleDashboards($user, bool $includeTrashed = false)
    {
        try {
            $query = McpDashboardDefinition::query();

            if ($includeTrashed) {
                $query->withTrashed();
            }

            // Admin can see all dashboards
            if ($this->isAdmin($user)) {
                Log::debug('[MCP Auth] Admin viewing all dashboards', [
                    'user_id' => $user->id ?? 'guest',
                ]);
                return $query;
            }

            // Regular users can see:
            // 1. Their own dashboards
            // 2. Public dashboards
            // 3. Dashboards they have explicit access to
            $query->where(function ($q) use ($user) {
                $q->where('created_by', $user->id)
                  ->orWhere('status', McpDashboardDefinition::STATUS_PUBLIC)
                  ->orWhereHas('accessList', function ($accessQuery) use ($user) {
                      $accessQuery->where('user_id', $user->id);
                  });
            });

            Log::debug('[MCP Auth] User viewing accessible dashboards', [
                'user_id' => $user->id ?? 'guest',
            ]);

            return $query;

        } catch (\Throwable $e) {
            Log::error('[MCP Auth] Error getting accessible dashboards', [
                'user_id' => $user->id ?? 'guest',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            // Return empty query on error
            return McpDashboardDefinition::whereRaw('1 = 0');
        }
    }
}