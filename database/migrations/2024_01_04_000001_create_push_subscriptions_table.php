<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web push subscriptions — one row per browser/device that opted in. Keyed to the
 * GLOBAL user (not tenant-scoped): a device belongs to a person, and a user may
 * switch active tenant without re-subscribing. The `endpoint` (the push service
 * URL) is the device identity, so it is unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            // 512 keeps the unique index within MySQL's InnoDB key limit while
            // comfortably fitting real push endpoints (FCM/Mozilla/Apple < 300).
            $table->string('endpoint', 512)->unique();
            $table->string('p256dh');
            $table->string('auth');
            $table->string('ua')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
