<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bottle_analyses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->date('analyzed_on');
            // Enology measurements — all optional. decimal(8,3) comfortably holds
            // pH, acidity (g/L), alcohol (%), sugar, SO₂ (mg/L), temperature,
            // density (e.g. 0.998), and TPI.
            $table->decimal('ph', 8, 3)->nullable();
            $table->decimal('total_acidity', 8, 3)->nullable();
            $table->decimal('volatile_acidity', 8, 3)->nullable();
            $table->decimal('alcohol', 8, 3)->nullable();
            $table->decimal('residual_sugar', 8, 3)->nullable();
            $table->decimal('free_so2', 8, 3)->nullable();
            $table->decimal('total_so2', 8, 3)->nullable();
            $table->decimal('temperature', 8, 3)->nullable();
            $table->decimal('density', 8, 3)->nullable();
            $table->decimal('tpi', 8, 3)->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'inventory_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bottle_analyses');
    }
};
