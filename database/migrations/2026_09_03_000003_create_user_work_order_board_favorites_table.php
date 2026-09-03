<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A member's one favourite board (Figma 267:1781's favourite star) — the
 * header's "Favorited" button jumps straight to whichever board is pointed
 * to here. The composite primary key on (tenant_id, user_id) is what
 * actually guarantees "only one favourite": setting a new one can only ever
 * replace the single existing row, atomically, not an application-level
 * check. `user_id` alongside `tenant_id` for the same reason user_shortcuts
 * carries both — a user can belong to several tenants, so a favourite in one
 * must not follow them into another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_work_order_board_favorites', function (Blueprint $table) {
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('board_id')->constrained('work_order_boards')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_work_order_board_favorites');
    }
};
