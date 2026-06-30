<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_dashboard_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dashboard_id');
            $table->string('event', 64);   // view, edit, delete, restore, clone, export, import, status_change, access_granted, access_revoked
            $table->string('actor_type', 32)->nullable();  // system_user | custom_user | guest
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_email', 191)->nullable();
            $table->json('metadata')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('dashboard_id');
            $table->index('event');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_dashboard_audit_logs');
    }
};
