<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_estimates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('parcel_id')->constrained('vineyard_parcels')->cascadeOnDelete();
            $table->foreignUlid('created_by_id')->constrained('users');
            $table->timestamp('date')->useCurrent();
            $table->integer('cluster_count');
            $table->decimal('avg_cluster_weight', 8, 2);
            $table->integer('sample_vine_count');
            $table->decimal('estimated_yield_kg', 12, 3);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'parcel_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_estimates');
    }
};
