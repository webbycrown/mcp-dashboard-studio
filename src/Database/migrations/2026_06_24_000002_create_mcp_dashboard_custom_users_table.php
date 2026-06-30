<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mcp_dashboard_custom_users
 *
 * Stores externally invited users who are NOT in the host app's users table.
 * Each custom user gets a SHA-256 hashed access_token.
 * They access private dashboards via: /dashboard-studio/{slug}?access_token=<raw_token>
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_dashboard_custom_users', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('dashboard_id');

            // Invited user identity
            $table->string('name');
            $table->string('email');

            // SHA-256 hash of the raw token shown once to the manager
            $table->string('access_token', 128)->index();

            // Optional expiry — null means never expires
            $table->timestamp('token_expires_at')->nullable();

            $table->timestamps();

            // One invite per email per dashboard
            $table->unique(['dashboard_id', 'email']);

            $table->foreign('dashboard_id')
                ->references('id')
                ->on('mcp_dashboard_definitions')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_dashboard_custom_users');
    }
};
