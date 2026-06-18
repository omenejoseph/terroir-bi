<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cellar_analyses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('wine_lot_id')->constrained('wine_lots')->cascadeOnDelete();
            // Null = lot-level reading; set = a specific vessel's reading.
            $table->foreignUlid('vessel_id')->nullable()->constrained('vessels')->nullOnDelete();
            $table->foreignUlid('created_by_id')->constrained('users');
            $table->timestamp('date')->useCurrent();
            $table->decimal('ph', 4, 2)->nullable();
            $table->decimal('total_acidity', 5, 2)->nullable();
            $table->decimal('volatile_acidity', 5, 3)->nullable();
            $table->decimal('alcohol', 5, 2)->nullable();
            $table->decimal('residual_sugar', 6, 2)->nullable();
            $table->decimal('free_so2', 6, 2)->nullable();
            $table->decimal('total_so2', 6, 2)->nullable();
            $table->decimal('brix', 5, 2)->nullable();
            $table->decimal('temperature', 5, 2)->nullable();
            $table->decimal('density', 6, 4)->nullable();
            $table->decimal('malic', 5, 2)->nullable();
            $table->decimal('lactic', 5, 2)->nullable();
            $table->decimal('tpi', 6, 2)->nullable();
            $table->decimal('glucose_fructose', 6, 2)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'wine_lot_id', 'date']);
            $table->index(['tenant_id', 'vessel_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cellar_analyses');
    }
};
