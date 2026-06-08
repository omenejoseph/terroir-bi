<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_prices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->bigInteger('price'); // money: integer minor units (absolute; rebate NOT applied)
            $table->timestamps();

            $table->unique(['inventory_item_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_prices');
    }
};
