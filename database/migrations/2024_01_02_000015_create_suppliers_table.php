<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier master + their "learned" price lists. tax_id (OIB) is the natural
 * key used to match incoming invoices/payments to a supplier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('company_name');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->string('tax_id')->nullable(); // OIB / VAT
            $table->string('bank_account')->nullable();
            $table->string('payment_terms')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('exclude_from_stats')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'tax_id']); // OIB unique per tenant (nulls allowed)
        });

        Schema::create('supplier_price_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignUlid('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->string('description');
            $table->bigInteger('unit_price'); // money: minor units
            $table->string('unit')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('last_updated')->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'description']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_price_items');
        Schema::dropIfExists('suppliers');
    }
};
