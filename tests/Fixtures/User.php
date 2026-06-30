<?php

namespace Webbycrown\McpDashboardStudio\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;

/**
 * Minimal User model used only in tests.
 * Includes HasApiTokens (required by Passport) and an is_admin flag
 * for testing the admin consent gate.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table    = 'users';
    protected $fillable = ['name', 'email', 'password', 'is_admin'];
    protected $hidden   = ['password', 'remember_token'];
    protected $casts    = ['is_admin' => 'boolean'];
}
