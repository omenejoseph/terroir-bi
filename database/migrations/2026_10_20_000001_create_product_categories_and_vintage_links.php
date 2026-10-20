<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory categories become real records the organisation manages (they were free text on each
 * item): a category may have subcategories (one level), and may be flagged as carrying vintages.
 * Items point at their category, and a vintage points at the previous vintage of the same product,
 * so a wine's vintages form a chain the list can fold under its latest vintage.
 *
 * `inventory_items.group` / `.subcategory` stay as a denormalised mirror of the category names, so
 * every report that reads them keeps working; the model keeps the two in step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // A subcategory names its parent; a top-level category has none. Its foreign key is added
            // below: Postgres needs the primary key to exist before a table can reference itself.
            $table->ulid('parent_id')->nullable();
            // The InventoryCategory enum value (FINISHED, …) the category lives under on the list.
            $table->string('type', 50);
            $table->string('name');
            $table->boolean('has_vintage')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'type', 'parent_id']);
        });

        Schema::table('product_categories', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('product_categories')->cascadeOnDelete();
        });

        Schema::table('inventory_items', function (Blueprint $table): void {
            // Deleting a category is only allowed when it is empty; nullOnDelete is the safety net.
            $table->foreignUlid('product_category_id')->nullable()->after('subcategory')
                ->constrained('product_categories')->nullOnDelete();
            // An item can be the previous vintage of at most one other item, so a chain never forks.
            $table->foreignUlid('previous_vintage_id')->nullable()->after('vintage')
                ->constrained('inventory_items')->nullOnDelete();
            $table->unique('previous_vintage_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropUnique(['previous_vintage_id']);
            $table->dropConstrainedForeignId('previous_vintage_id');
            $table->dropConstrainedForeignId('product_category_id');
        });

        Schema::dropIfExists('product_categories');
    }
};
