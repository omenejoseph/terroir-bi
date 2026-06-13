<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BDD runs now execute asynchronously in a queued job; each run keeps the
 * human-readable progress log that streamed to the UI while it ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bdd_scenario_runs', function (Blueprint $table) {
            $table->json('logs')->nullable()->after('transcript');
        });
    }

    public function down(): void
    {
        Schema::table('bdd_scenario_runs', function (Blueprint $table) {
            $table->dropColumn('logs');
        });
    }
};
