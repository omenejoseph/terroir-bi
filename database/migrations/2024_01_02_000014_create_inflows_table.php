<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money-in records (customer payments / accounts-receivable). An inflow may be
 * tied to an order (to track its paid/outstanding balance) and/or a customer.
 * Credit notes reduce what is owed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inflows', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignUlid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('date');
            $table->bigInteger('amount'); // money: minor units
            $table->string('status')->default('PENDING'); // App\Enums\InflowStatus
            $table->boolean('is_credit_note')->default(false);
            $table->string('category')->nullable();
            $table->string('reference')->nullable();
            $table->string('payment_method')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('due_date')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignUlid('created_by_id')->constrained('users');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inflows');
    }
};
