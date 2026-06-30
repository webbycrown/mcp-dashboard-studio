<?php

namespace Webbycrown\McpDashboardStudio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * McpDashboardAccess
 *
 * Pivot — links a private dashboard to a system user (from host app's users table).
 *
 * @property int $id
 * @property int $dashboard_id
 * @property int $user_id
 */
class McpDashboardAccess extends Model
{
    protected $table = 'mcp_dashboard_access';

    protected $fillable = [
        'dashboard_id',
        'user_id',
    ];

    // ─── Relationships ───────────────────────────────────────────────────

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(McpDashboardDefinition::class, 'dashboard_id');
    }

    /**
     * Resolves to the host app's User model via the auth provider config.
     */
    public function user(): BelongsTo
    {
        /** @var class-string<\Illuminate\Database\Eloquent\Model> $userModel */
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        return $this->belongsTo($userModel, 'user_id');
    }
}
