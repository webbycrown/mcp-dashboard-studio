<?php

namespace Webbycrown\McpDashboardStudio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * McpDashboardCustomUser
 *
 * An external invited user who can access a private dashboard
 * via a tokenized URL: /dashboard-studio/{slug}?access_token=<raw_token>
 *
 * @property int         $id
 * @property string      $uuid
 * @property int         $dashboard_id
 * @property string      $name
 * @property string      $email
 * @property string      $access_token  (SHA-256 hash — never expose raw)
 * @property \Carbon\Carbon|null $token_expires_at
 */
class McpDashboardCustomUser extends Model
{
    protected $table = 'mcp_dashboard_custom_users';

    protected $fillable = [
        'uuid',
        'dashboard_id',
        'name',
        'email',
        'password',
        'access_token',
        'token_expires_at',
    ];

    protected $hidden = [
        'password',
        'access_token',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
    ];

    // ─── Booting ─────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // ─── Relationships ────────────────────────────────────────────────────

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(McpDashboardDefinition::class, 'dashboard_id');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /**
     * Check if this custom user's access token has expired.
     * A null expiry means the token never expires.
     */
    public function isTokenExpired(): bool
    {
        if ($this->token_expires_at === null) {
            return false;
        }

        return now()->gt($this->token_expires_at);
    }

    /**
     * Verify a plain-text password against the stored bcrypt hash.
     */
    public function verifyPassword(string $plain): bool
    {
        return \Illuminate\Support\Facades\Hash::check($plain, $this->password);
    }

    /**
     * Hash a raw token for storage.
     * Always store hashed; show raw ONCE to the manager at invite time.
     */
    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * Find a custom user by a raw (unhashed) token.
     */
    public static function findByRawToken(string $rawToken): ?static
    {
        return static::where('access_token', static::hashToken($rawToken))->first();
    }
}
