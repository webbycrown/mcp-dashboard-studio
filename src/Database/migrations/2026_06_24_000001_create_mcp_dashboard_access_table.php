<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mcp_dashboard_access
 *
 * Links a private dashboard to one or more system users (from the host app's users table).
 * When a dashboard is status='private', only users listed here may view it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_dashboard_access', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('dashboard_id');
            $table->unsignedBigInteger('user_id');

            $table->timestamps();

            // One entry per user per dashboard
            $table->unique(['dashboard_id', 'user_id']);

            $table->foreign('dashboard_id')
                ->references('id')
                ->on('mcp_dashboard_definitions')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_dashboard_access');
    }
};
