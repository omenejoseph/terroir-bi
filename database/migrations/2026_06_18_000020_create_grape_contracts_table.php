<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grape_contracts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignUlid('parcel_id')->nullable()->constrained('vineyard_parcels')->nullOnDelete();
            $table->string('season');
            $table->string('status')->default('ACTIVE'); // App\Enums\GrapeContractStatus
            $table->string('grape_variety');
            $table->decimal('estimated_kg', 12, 3);
            $table->decimal('delivered_kg', 12, 3)->default(0);
            $table->bigInteger('price_per_kg'); // money: minor units
            $table->decimal('min_brix', 5, 2)->nullable();
            $table->decimal('max_ph', 4, 2)->nullable();
            $table->string('delivery_window')->nullable();
            $table->string('payment_terms')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'supplier_id']);
            $table->index(['tenant_id', 'season']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grape_contracts');
    }
};
