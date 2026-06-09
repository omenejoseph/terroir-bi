<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase orders to suppliers. Receiving a PO (status RECEIVED) writes a
 * PURCHASE_IN stock movement for each linked item and refreshes its cost_per_unit
 * (landed cost) — the loop that makes cost-per-product real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('order_number');
            $table->string('status')->default('DRAFT'); // App\Enums\SupplierOrderStatus
            $table->bigInteger('total_amount')->default(0); // money: minor units
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expected_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignUlid('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignUlid('created_by_id')->constrained('users');
            $table->timestamps();

            $table->unique(['tenant_id', 'order_number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('supplier_order_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('supplier_order_id')->constrained('supplier_orders')->cascadeOnDelete();
            $table->foreignUlid('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 3);
            $table->string('unit')->nullable();
            $table->bigInteger('unit_price'); // money: minor units
            $table->bigInteger('total'); // money: minor units
            $table->timestamps();

            $table->index(['tenant_id', 'supplier_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_order_items');
        Schema::dropIfExists('supplier_orders');
    }
};
