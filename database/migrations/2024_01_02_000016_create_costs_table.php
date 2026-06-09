<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expenses (money-out). A cost may have line items (optionally allocated to a
 * product/category) and receipt/invoice attachments stored in the bucket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('costs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->timestamp('date');
            $table->bigInteger('total_amount'); // money: minor units
            $table->bigInteger('vat_amount')->nullable(); // money: minor units
            $table->string('category');
            $table->string('description')->nullable();
            $table->string('reference')->nullable();
            $table->string('status')->default('PENDING'); // App\Enums\CostStatus
            $table->string('payment_method')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('due_date')->nullable();
            $table->foreignUlid('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignUlid('created_by_id')->constrained('users');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'date']);
        });

        Schema::create('cost_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('cost_id')->constrained('costs')->cascadeOnDelete();
            $table->foreignUlid('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->bigInteger('unit_price'); // money: minor units
            $table->bigInteger('total'); // money: minor units
            $table->string('category')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'cost_id']);
        });

        Schema::create('cost_attachments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('cost_id')->constrained('costs')->cascadeOnDelete();
            $table->string('object_key');
            $table->string('filename');
            $table->string('content_type');
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();

            $table->index(['tenant_id', 'cost_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_attachments');
        Schema::dropIfExists('cost_items');
        Schema::dropIfExists('costs');
    }
};
