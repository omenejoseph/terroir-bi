<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kitchen (docs/21 §3): the kitchen's own stock, its movements, stock-takes ("Inventura") and prep
 * tickets. Kept apart from wine inventory on purpose — different items, units and locations, and
 * the old app never linked the two. Quantities are decimal(12,3); cost is minor units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('category', 20);
            $table->string('group')->nullable();
            $table->string('unit', 10);
            $table->decimal('current_stock', 12, 3)->default(0);
            $table->decimal('min_stock', 12, 3)->nullable();
            $table->bigInteger('cost_per_unit')->nullable(); // money: minor units
            $table->string('supplier')->nullable();
            $table->string('location', 20);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'category', 'sort_order']);
        });

        Schema::create('kitchen_movements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('item_id')->constrained('kitchen_items')->cascadeOnDelete();
            $table->string('type', 12);
            $table->decimal('quantity', 12, 3); // signed change
            $table->text('note')->nullable();
            $table->foreignUlid('user_id')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['item_id', 'created_at']);
        });

        Schema::create('kitchen_checks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('date');
            $table->string('status', 12)->default('IN_PROGRESS');
            $table->string('location', 20)->nullable(); // null = every location
            $table->text('note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignUlid('user_id')->constrained('users');
            $table->timestamps();
        });

        Schema::create('kitchen_check_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('check_id')->constrained('kitchen_checks')->cascadeOnDelete();
            $table->foreignUlid('item_id')->constrained('kitchen_items')->cascadeOnDelete();
            $table->decimal('system_stock', 12, 3);
            $table->decimal('physical_count', 12, 3)->nullable();
            $table->decimal('difference', 12, 3)->nullable();

            $table->unique(['check_id', 'item_id']);
        });

        Schema::create('kitchen_work_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('title');
            $table->date('date');
            $table->string('status', 12)->default('PLANNED');
            $table->string('assigned_to')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignUlid('user_id')->constrained('users');
            $table->timestamps();

            $table->index(['tenant_id', 'date']);
        });

        Schema::create('kitchen_work_order_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('work_order_id')->constrained('kitchen_work_orders')->cascadeOnDelete();
            $table->foreignUlid('item_id')->nullable()->constrained('kitchen_items')->nullOnDelete();
            $table->string('custom_name')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 10)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_work_order_items');
        Schema::dropIfExists('kitchen_work_orders');
        Schema::dropIfExists('kitchen_check_items');
        Schema::dropIfExists('kitchen_checks');
        Schema::dropIfExists('kitchen_movements');
        Schema::dropIfExists('kitchen_items');
    }
};
