<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BDD scenarios now run LIVE (an AI agent calls tools every run — no saved
 * compiled plan), so each run stores its full tool transcript for auditing the
 * AI's judgements. The old compile columns on bdd_scenarios stay dormant for
 * reversibility and are dropped in a later cleanup migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bdd_scenario_runs', function (Blueprint $table) {
            $table->json('transcript')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('bdd_scenario_runs', function (Blueprint $table) {
            $table->dropColumn('transcript');
        });
    }
};
