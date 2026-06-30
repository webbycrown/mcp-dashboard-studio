<?php

namespace Webbycrown\McpDashboardStudio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * McpDashboardDefinition
 *
 * Represents a generated and persisted analytics dashboard.
 *
 * @property int    $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string $prompt
 * @property string|null $description
 * @property array  $layout_json
 * @property string $status   'public' | 'private'
 * @property int    $version
 * @property int|null $created_by
 * @property string|null $hash
 */
class McpDashboardDefinition extends Model
{
    use SoftDeletes;

    protected $table = 'mcp_dashboard_definitions';

    /** Valid status values (matches the migration enum). */
    public const STATUS_PUBLIC  = 'public';
    public const STATUS_PRIVATE = 'private';

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'prompt',
        'description',
        'layout_json',
        'status',
        'version',
        'created_by',
        'hash',
    ];

    protected $hidden = [
        'layout_json',
    ];

    protected $casts = [
        'layout_json' => 'array',
        'version'     => 'integer',
        'created_by'  => 'integer',
    ];

    // 
    //  Boot
    // 
    
    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // 
    //  Relationships
    // 
    
    /**
     * System users (from host app's users table) who may access this dashboard.
     * Only relevant when status = 'private'.
     */
    public function accessList(): HasMany
    {
        return $this->hasMany(McpDashboardAccess::class, 'dashboard_id');
    }

    /**
     * External / invited users who may access this dashboard via tokenized URL.
     * Only relevant when status = 'private'.
     */
    public function customUsers(): HasMany
    {
        return $this->hasMany(McpDashboardCustomUser::class, 'dashboard_id');
    }

    // 
    //  Status Helpers
    // 
    
    public function isPublic(): bool
    {
        return $this->status === self::STATUS_PUBLIC;
    }

    public function isPrivate(): bool
    {
        return $this->status === self::STATUS_PRIVATE;
    }

    // 
    //  Authorization Helpers
    // 

    /**
     * Check if the given user is the creator of this dashboard.
     *
     * @param mixed $user The user to check
     * @return bool
     */
    public function isOwnedBy($user): bool
    {
        try {
            if (!$user || !isset($user->id)) {
                return false;
            }

            return $this->created_by === $user->id;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[MCP Dashboard] Error checking ownership', [
                'dashboard_id' => $this->id,
                'user_id' => $user->id ?? 'guest',
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check if the currently authenticated user can view this dashboard.
     *
     * @return bool
     */
    public function canBeViewedByCurrentUser(): bool
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return false;
            }

            return app(\Webbycrown\McpDashboardStudio\Services\DashboardAuthorizationService::class)
                ->canViewDashboard($user, $this);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[MCP Dashboard] Error checking view permission', [
                'dashboard_id' => $this->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check if the currently authenticated user can edit this dashboard.
     *
     * @return bool
     */
    public function canBeEditedByCurrentUser(): bool
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return false;
            }

            return app(\Webbycrown\McpDashboardStudio\Services\DashboardAuthorizationService::class)
                ->canEditDashboard($user, $this);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[MCP Dashboard] Error checking edit permission', [
                'dashboard_id' => $this->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check if the currently authenticated user can delete this dashboard.
     *
     * @return bool
     */
    public function canBeDeletedByCurrentUser(): bool
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return false;
            }

            return app(\Webbycrown\McpDashboardStudio\Services\DashboardAuthorizationService::class)
                ->canDeleteDashboard($user, $this);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[MCP Dashboard] Error checking delete permission', [
                'dashboard_id' => $this->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check if the currently authenticated user can manage access for this dashboard.
     *
     * @return bool
     */
    public function canAccessBeManagedByCurrentUser(): bool
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return false;
            }

            return app(\Webbycrown\McpDashboardStudio\Services\DashboardAuthorizationService::class)
                ->canManageAccess($user, $this);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[MCP Dashboard] Error checking access management permission', [
                'dashboard_id' => $this->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}