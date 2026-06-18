<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('harvest_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('harvest_plan_id')->constrained('harvest_plans')->cascadeOnDelete();
            $table->foreignUlid('parcel_id')->nullable()->constrained('vineyard_parcels')->nullOnDelete();
            $table->foreignUlid('contract_id')->nullable()->constrained('grape_contracts')->nullOnDelete();
            $table->foreignUlid('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignUlid('planned_vessel_id')->nullable()->constrained('vessels')->nullOnDelete();
            $table->foreignUlid('wine_lot_id')->nullable()->unique()->constrained('wine_lots')->nullOnDelete();
            $table->string('status')->default('PLANNED'); // App\Enums\HarvestEntryStatus
            $table->string('source')->default('OWN'); // App\Enums\HarvestSource
            $table->string('grape_variety')->nullable();
            $table->timestamp('planned_date')->nullable();
            $table->decimal('estimated_yield_kg', 12, 3)->nullable();
            $table->timestamp('actual_date')->nullable();
            $table->decimal('actual_yield_kg', 12, 3)->nullable();
            $table->decimal('actual_volume_liters', 12, 3)->nullable();
            $table->bigInteger('grape_price_per_kg')->nullable(); // money: minor units
            $table->decimal('brix', 5, 2)->nullable();
            $table->decimal('ph', 4, 2)->nullable();
            $table->decimal('titrable_acidity', 5, 2)->nullable();
            $table->decimal('temperature', 5, 2)->nullable();
            $table->integer('condition_score')->nullable();
            $table->string('condition_notes')->nullable();
            $table->string('photo_url')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'harvest_plan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('harvest_entries');
    }
};
