<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            // utf8mb4_unicode_ci (the connection's default collation on
            // MySQL) folds some distinct emoji to equal collation weights,
            // which the unique index below would then read as a duplicate
            // reaction even though the stored bytes differ. Overridden on
            // just this column — not the table, and not the connection
            // default, which would make every foreignUlid() column above
            // incompatible with the (differently-collated) tables they
            // reference. MySQL-only: sqlite doesn't have (or recognise the
            // name of) this collation at all, and doesn't have the bug either.
            $emoji = $table->string('emoji', 8);
            if (DB::connection()->getDriverName() === 'mysql') {
                $emoji->collation('utf8mb4_0900_ai_ci');
            }
            $table->timestamps();

            $table->unique(['order_note_id', 'user_id', 'emoji']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_note_reactions');
    }
};
