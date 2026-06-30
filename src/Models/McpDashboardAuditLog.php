<?php

namespace Webbycrown\McpDashboardStudio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class McpDashboardAuditLog extends Model
{
    protected $table    = 'mcp_dashboard_audit_logs';
    public    $timestamps = false;

    protected $fillable = [
        'dashboard_id',
        'event',
        'actor_type',
        'actor_id',
        'actor_email',
        'metadata',
        'ip_address',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    // ── Events ────────────────────────────────────────────────────────────
    const EVENT_VIEW            = 'view';
    const EVENT_EDIT            = 'edit';
    const EVENT_DELETE          = 'delete';
    const EVENT_RESTORE         = 'restore';
    const EVENT_CLONE           = 'clone';
    const EVENT_EXPORT          = 'export';
    const EVENT_IMPORT          = 'import';
    const EVENT_STATUS_CHANGE   = 'status_change';
    const EVENT_ACCESS_GRANTED  = 'access_granted';
    const EVENT_ACCESS_REVOKED  = 'access_revoked';

    // ── Actor Types ───────────────────────────────────────────────────────
    const ACTOR_SYSTEM_USER  = 'system_user';
    const ACTOR_CUSTOM_USER  = 'custom_user';
    const ACTOR_GUEST        = 'guest';
    const ACTOR_MANAGER      = 'manager';

    // ── Relationships ─────────────────────────────────────────────────────
    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(McpDashboardDefinition::class, 'dashboard_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────
    public function getEventLabelAttribute(): string
    {
        return match ($this->event) {
            self::EVENT_VIEW           => 'Viewed',
            self::EVENT_EDIT           => 'Edited',
            self::EVENT_DELETE         => 'Deleted',
            self::EVENT_RESTORE        => 'Restored',
            self::EVENT_CLONE          => 'Cloned',
            self::EVENT_EXPORT         => 'Exported',
            self::EVENT_IMPORT         => 'Imported',
            self::EVENT_STATUS_CHANGE  => 'Status Changed',
            self::EVENT_ACCESS_GRANTED => 'Access Granted',
            self::EVENT_ACCESS_REVOKED => 'Access Revoked',
            default                    => ucfirst($this->event),
        };
    }

    public function getEventBadgeClassAttribute(): string
    {
        return match ($this->event) {
            self::EVENT_VIEW           => 'bg-secondary',
            self::EVENT_EDIT           => 'bg-primary',
            self::EVENT_DELETE         => 'bg-danger',
            self::EVENT_RESTORE        => 'bg-success',
            self::EVENT_CLONE          => 'bg-info',
            self::EVENT_EXPORT         => 'bg-secondary',
            self::EVENT_IMPORT         => 'bg-warning text-dark',
            self::EVENT_STATUS_CHANGE  => 'bg-primary',
            self::EVENT_ACCESS_GRANTED => 'bg-success',
            self::EVENT_ACCESS_REVOKED => 'bg-danger',
            default                    => 'bg-secondary',
        };
    }
}
