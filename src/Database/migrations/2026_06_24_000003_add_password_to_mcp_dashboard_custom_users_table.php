<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add password column to mcp_dashboard_custom_users.
 *
 * Custom users now authenticate with email + password via the
 * /dashboard-studio/{slug}/custom-login page before viewing a private dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_dashboard_custom_users', function (Blueprint $table) {
            // Bcrypt-hashed password; stored after access_token
            $table->string('password')->after('access_token');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_dashboard_custom_users', function (Blueprint $table) {
            $table->dropColumn('password');
        });
    }
};
