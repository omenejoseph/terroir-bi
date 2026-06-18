<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enological_products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('category'); // SO2, Yeast, Nutrient, Acid, Tannin, …
            $table->string('unit'); // g, kg, ml, l
            $table->decimal('current_stock', 12, 3)->default(0);
            $table->decimal('min_stock', 12, 3)->nullable();
            $table->bigInteger('cost_per_unit')->nullable(); // money: minor units
            $table->string('manufacturer')->nullable();
            $table->string('packaging_size')->nullable();
            // mg/L free SO2 added per 1 unit per 1 L of wine — powers the SO2 calculator.
            $table->decimal('so2_uplift_per_unit', 8, 4)->nullable();
            $table->foreignUlid('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enological_products');
    }
};
