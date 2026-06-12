<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make notifications platform-agnostic: instead of a server-baked `link` path,
 * store a `data` bag of route params (e.g. {"order_id": "01..."}). Each client
 * (web now, native later) maps (type, data) to its own route — the API never
 * emits client paths.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->json('data')->nullable()->after('body');
            $table->dropColumn('link');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('link')->nullable()->after('body');
            $table->dropColumn('data');
        });
    }
};
