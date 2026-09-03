<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A member's pinned nav-item keys (Manage Shortcuts, Figma `143:4179`), in
 * pin order. `nav_key` is validated against App\Support\NavCatalog::ALL_KEYS
 * before it ever reaches here — display metadata (label/icon/href) lives only
 * in the frontend's NAV_CATEGORIES, so this table stores nothing that would
 * duplicate it.
 *
 * `user_id` alongside `tenant_id`: User is a global identity that can belong
 * to several tenants, so a pin is scoped to both — pinning Inventory in one
 * winery must not pin it in another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_shortcuts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('nav_key');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'nav_key']);
            $table->index(['tenant_id', 'user_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_shortcuts');
    }
};
