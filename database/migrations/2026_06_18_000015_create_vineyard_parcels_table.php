<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vineyard_parcels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('grape_variety');
            $table->decimal('area_hectares', 8, 4)->nullable();
            $table->string('location')->nullable();
            $table->integer('elevation')->nullable();
            $table->string('soil_type')->nullable();
            $table->integer('planting_year')->nullable();
            $table->decimal('row_spacing', 5, 2)->nullable();
            $table->integer('vine_count')->nullable();
            $table->string('rootstock')->nullable();
            $table->string('training')->nullable();
            $table->string('orientation')->nullable();
            $table->decimal('slope', 5, 2)->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->json('geo_polygon')->nullable();
            $table->decimal('geo_area_calculated', 10, 4)->nullable();
            $table->string('ownership')->default('OWN'); // App\Enums\ParcelOwnership
            $table->foreignUlid('cooperant_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vineyard_parcels');
    }
};
