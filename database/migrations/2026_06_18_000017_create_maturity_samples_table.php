<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maturity_samples', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('parcel_id')->constrained('vineyard_parcels')->cascadeOnDelete();
            $table->foreignUlid('created_by_id')->constrained('users');
            $table->timestamp('date')->useCurrent();
            $table->decimal('brix', 5, 2)->nullable();
            $table->decimal('ph', 4, 2)->nullable();
            $table->decimal('total_acidity', 5, 2)->nullable();
            $table->decimal('temperature', 5, 2)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'parcel_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maturity_samples');
    }
};
