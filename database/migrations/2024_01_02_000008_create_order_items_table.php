<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUlid('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->integer('quantity');
            $table->string('unit_type')->default('bottles'); // bottles / cases
            $table->bigInteger('unit_price'); // money: minor units
            $table->bigInteger('total'); // money: minor units
            $table->bigInteger('cost_per_unit')->nullable(); // money: COGS snapshot at order time
            $table->string('custom_description')->nullable(); // non-product lines
            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
