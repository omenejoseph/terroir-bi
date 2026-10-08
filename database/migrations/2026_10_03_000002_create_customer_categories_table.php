<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer categories: a list each organisation maintains itself ("Restaurant", "Hotel",
 * "Distributor / Importer", …) and assigns to its customers. Separate from `customer_type`,
 * which is the fixed sales-channel enum that drives revenue-by-channel on the dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('customers', function (Blueprint $table): void {
            // Deleting a category un-labels its customers rather than removing them.
            $table->foreignUlid('customer_category_id')->nullable()->after('pricing_tier_id')
                ->constrained('customer_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_category_id');
        });

        Schema::dropIfExists('customer_categories');
    }
};
