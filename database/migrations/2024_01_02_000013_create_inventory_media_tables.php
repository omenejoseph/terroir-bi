<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product images and technical sheets, stored as private objects in the uploads
 * bucket and referenced here by object key. Read URLs are minted on demand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_images', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->string('object_key');
            $table->string('content_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('alt')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'inventory_item_id']);
        });

        Schema::create('inventory_tech_sheets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->string('name');
            $table->string('object_key');
            $table->string('content_type');
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();

            $table->index(['tenant_id', 'inventory_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_tech_sheets');
        Schema::dropIfExists('inventory_images');
    }
};
