<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sell-through / return events against a consignment (komisija) order. Quantities
 * on the items are always normalized to single bottles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consignment_reports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('kind'); // SALE | RETURN
            $table->timestamp('date');
            $table->string('note')->nullable();
            $table->foreignUlid('created_by_id')->constrained('users');
            $table->timestamps();

            $table->index(['order_id', 'kind']);
        });

        Schema::create('consignment_report_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('report_id')->constrained('consignment_reports')->cascadeOnDelete();
            $table->foreignUlid('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignUlid('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->integer('quantity'); // bottles
            $table->bigInteger('unit_price'); // money: minor units (0 for RETURN)
            $table->bigInteger('total'); // money: minor units (0 for RETURN)
            $table->timestamps();

            $table->index('report_id');
            $table->index('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consignment_report_items');
        Schema::dropIfExists('consignment_reports');
    }
};
