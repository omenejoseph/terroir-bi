<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bill-of-materials: the inputs consumed to produce one unit of an output item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('output_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignUlid('input_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->decimal('quantity', 12, 3); // input qty per 1 output unit
            $table->timestamps();

            $table->unique(['output_id', 'input_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_items');
    }
};
