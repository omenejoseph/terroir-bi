<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emoji reactions on an order comment (Figma 376:1592 — the design shows
 * emoji counts under each comment; this is that table). One row per
 * (comment, person, emoji): a person may react with several different emoji
 * on the same comment, but only once each — the unique index is the toggle's
 * actual guarantee, not just an application-level rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_note_reactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('order_note_id')->constrained('order_notes')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('emoji', 8);
            $table->timestamps();

            $table->unique(['order_note_id', 'user_id', 'emoji']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_note_reactions');
    }
};
