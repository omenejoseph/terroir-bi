<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hospitality (the old app's "Agencies"): the programs a winery sells to groups, the
 * per-agency price book, and the reservations taken against them. Money is in minor units;
 * a reservation snapshots its price and total, so later price-book edits never rewrite it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hospitality_programs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('duration_mins')->nullable();
            $table->bigInteger('base_price')->default(0); // money: minor units, per person
            $table->unsignedInteger('max_pax')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'sort_order']);
        });

        Schema::create('hospitality_agency_prices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('program_id')->constrained('hospitality_programs')->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->bigInteger('price_per_pax'); // money: minor units
            $table->decimal('rebate_percent', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['program_id', 'customer_id']);
        });

        Schema::create('reservations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->dateTime('date');
            $table->unsignedInteger('pax');
            $table->string('status', 20)->default('PENDING');
            $table->bigInteger('price_per_pax')->default(0); // money: minor units, snapshot
            $table->bigInteger('total_amount')->default(0);  // money: minor units, snapshot
            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();
            $table->string('guest_phone')->nullable();
            $table->text('allergies')->nullable();
            $table->text('notes')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignUlid('program_id')->constrained('hospitality_programs');
            // A walk-in has no customer; deleting an agency keeps its reservations as walk-ins.
            $table->foreignUlid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignUlid('created_by_id')->constrained('users');
            $table->timestamps();

            $table->index(['tenant_id', 'date']);
            $table->index(['tenant_id', 'status']);
            $table->index('customer_id');
        });

        Schema::create('reservation_status_histories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->string('status', 20);
            $table->text('note')->nullable();
            $table->foreignUlid('changed_by_id')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index('reservation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_status_histories');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('hospitality_agency_prices');
        Schema::dropIfExists('hospitality_programs');
    }
};
