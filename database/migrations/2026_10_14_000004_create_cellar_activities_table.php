<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planned cellar work: an addition, analysis, transfer, process, topping or
 * vineyard task scheduled for a day, executed (or skipped) from Cellar › Today.
 * `payload` holds the type-specific plan (see App\Services\Cellar\ActivityPayload)
 * and, once done, what was actually applied under `applied`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cellar_activities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('type'); // App\Enums\CellarActivityType
            $table->string('status')->default('PLANNED'); // App\Enums\CellarActivityStatus
            $table->json('payload');
            $table->date('planned_for');
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignUlid('wine_lot_id')->nullable()->constrained('wine_lots')->cascadeOnDelete();
            $table->foreignUlid('vessel_id')->nullable()->constrained('vessels')->cascadeOnDelete();
            $table->foreignUlid('round_id')->nullable()->constrained('cellar_activity_rounds')->nullOnDelete();
            $table->foreignUlid('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->string('skip_reason')->nullable();
            $table->boolean('deviated')->default(false);
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->foreignUlid('planned_by_id')->constrained('users');
            $table->foreignUlid('executed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'planned_for']);
            $table->index(['tenant_id', 'round_id']);
            $table->index(['tenant_id', 'wine_lot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cellar_activities');
    }
};
