<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-customer catalog visibility override: force-show or force-hide a specific
 * item in that customer's self-service portal, independent of the item's own
 * is_for_sale / hide_from_portal flags.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_product_overrides', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUlid('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->boolean('visible')->default(true);
            $table->timestamps();

            $table->unique(['customer_id', 'inventory_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_product_overrides');
    }
};
