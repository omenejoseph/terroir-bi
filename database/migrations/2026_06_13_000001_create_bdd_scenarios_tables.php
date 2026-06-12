<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-authored BDD scenarios (central, not tenant-scoped). The Gherkin text is
 * the source of truth; `compiled_plan` is the AI-compiled, deterministic
 * execution plan replayed by the runner. When the compiler needs an operation
 * that hasn't been granted, the scenario parks in NEEDS_ACCESS with the
 * requested operations listed for one-click granting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bdd_scenarios', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('gherkin');
            $table->string('status')->default('DRAFT'); // App\Enums\BddScenarioStatus
            $table->json('compiled_plan')->nullable();
            $table->json('requested_operations')->nullable();
            $table->text('compile_error')->nullable();
            $table->string('compile_model')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('last_run_status')->nullable(); // App\Enums\BddRunStatus
            $table->timestamp('last_run_at')->nullable();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'is_active']);
        });

        Schema::create('bdd_scenario_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('bdd_scenario_id')->constrained('bdd_scenarios')->cascadeOnDelete();
            $table->string('status'); // App\Enums\BddRunStatus
            $table->json('step_results')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->foreignUlid('triggered_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['bdd_scenario_id', 'created_at']);
        });

        // The explicit allowlist: which operations (action classes) scenarios may
        // call. Built-in seeds/probes are always available; everything else is
        // fail-closed until granted here.
        Schema::create('bdd_operation_grants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('operation_key')->unique();
            $table->foreignUlid('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bdd_operation_grants');
        Schema::dropIfExists('bdd_scenario_runs');
        Schema::dropIfExists('bdd_scenarios');
    }
};
