<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mcp_dashboard_definitions', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            // Human readable name
            $table->string('name');

            // URL slug
            $table->string('slug')->unique();

            // Original user prompt
            $table->text('prompt');

            // Optional description
            $table->text('description')->nullable();

            // AI generated JSON
            $table->json('layout_json');

            // Current state
            $table->enum('status', [
                'private',
                'public'
            ])->default('private');

            // Future compatibility
            $table->unsignedInteger('version')->default(1);

            // Optional creator
            $table->unsignedBigInteger('created_by')->nullable();

            // Optional cache hash
            $table->string('hash')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_dashboard_definitions');
    }
};
