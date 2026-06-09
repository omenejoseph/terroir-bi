<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('order_number');
            $table->string('status')->default('RECEIVED'); // App\Enums\OrderStatus
            $table->bigInteger('total_amount')->default(0); // money: minor units
            $table->text('notes')->nullable();
            $table->foreignUlid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignUlid('created_by_id')->constrained('users');
            $table->boolean('is_backorder')->default(false);
            $table->timestamp('backorder_date')->nullable();
            $table->bigInteger('shipping_cost')->nullable(); // money: minor units
            $table->boolean('shipping_paid_by_us')->default(false);
            $table->boolean('is_consignment')->default(false);
            $table->timestamp('consignment_closed_at')->nullable();
            $table->timestamp('last_stale_notified_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'order_number']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'customer_id', 'created_at']);
            $table->index(['tenant_id', 'is_consignment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
