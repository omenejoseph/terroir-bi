<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('sku');
            $table->text('description')->nullable();
            $table->string('category'); // App\Enums\InventoryCategory
            $table->string('group')->nullable();
            $table->string('subcategory')->nullable();
            $table->string('vintage')->nullable();
            $table->string('unit'); // bottles, cases, kg, liters, units
            $table->decimal('current_stock', 12, 3)->default(0);
            $table->decimal('min_stock', 12, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->bigInteger('default_price')->nullable(); // money: integer minor units
            $table->integer('bottles_per_case')->default(12);
            $table->boolean('is_for_sale')->default(false);
            $table->bigInteger('cost_per_unit')->nullable(); // money: integer minor units (COGS)
            $table->timestamps();

            $table->unique(['tenant_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
