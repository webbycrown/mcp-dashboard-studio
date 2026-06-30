<?php

namespace Webbycrown\McpDashboardStudio\Services;

use Webbycrown\McpDashboardStudio\Models\McpDashboardAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * AuditLogger
 *
 * Centralised service for writing dashboard audit events.
 * All manager controllers call this to produce a consistent audit trail.
 */
class AuditLogger
{
    /**
     * Log an event from a web request context.
     * Automatically resolves the current user and IP.
     */
    public static function fromRequest(
        Request $request,
        int     $dashboardId,
        string  $event,
        array   $metadata = []
    ): void {
        $user = Auth::user();

        $actorType  = $user ? McpDashboardAuditLog::ACTOR_SYSTEM_USER : McpDashboardAuditLog::ACTOR_GUEST;
        $actorId    = $user?->id;
        $actorEmail = $user?->email;

        self::write($dashboardId, $event, $actorType, $actorId, $actorEmail, $metadata, $request->ip());
    }

    /**
     * Log an event for a custom (invited) user.
     */
    public static function fromCustomUser(
        Request $request,
        int     $dashboardId,
        string  $event,
        int     $customUserId,
        string  $customUserEmail,
        array   $metadata = []
    ): void {
        self::write(
            $dashboardId,
            $event,
            McpDashboardAuditLog::ACTOR_CUSTOM_USER,
            $customUserId,
            $customUserEmail,
            $metadata,
            $request->ip()
        );
    }

    /**
     * Core write method — silently swallows exceptions to never break the main request.
     */
    public static function write(
        int     $dashboardId,
        string  $event,
        ?string $actorType  = null,
        ?int    $actorId    = null,
        ?string $actorEmail = null,
        array   $metadata   = [],
        ?string $ip         = null
    ): void {
        try {
            McpDashboardAuditLog::create([
                'dashboard_id' => $dashboardId,
                'event'        => $event,
                'actor_type'   => $actorType,
                'actor_id'     => $actorId,
                'actor_email'  => $actorEmail,
                'metadata'     => $metadata ?: null,
                'ip_address'   => $ip,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[MCP Audit] Failed to write audit log entry.', [
                'dashboard_id' => $dashboardId,
                'event'        => $event,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
