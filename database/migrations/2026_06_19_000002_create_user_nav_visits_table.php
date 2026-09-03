<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a member last visited each nav page, feeding Manage Shortcuts' "Recent"
 * list (Figma `143:4179`). One row per (tenant, user, nav_key); RecordNavVisit
 * upserts `visited_at` rather than appending a history log, since the UI only
 * ever needs the most recent visit per page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_nav_visits', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('nav_key');
            $table->timestamp('visited_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'nav_key']);
            $table->index(['tenant_id', 'user_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_nav_visits');
    }
};
