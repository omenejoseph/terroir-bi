<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accommodation (docs/21 §4): villas with their guest-portal content and nightly rates, bookings
 * priced from those rates, the in-villa menu, and the orders guests place from it. Money is minor
 * units. Portal tokens are kept unique so printed QR codes keep working after the import.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('villas', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('portal_token', 64)->unique();
            $table->string('address')->nullable();
            $table->string('coordinates')->nullable();
            $table->string('wifi_name')->nullable();
            $table->string('wifi_password')->nullable();
            $table->string('check_in_time', 10)->nullable();
            $table->string('check_out_time', 10)->nullable();
            $table->text('instructions')->nullable();
            $table->text('rules')->nullable();
            $table->text('welcome')->nullable();
            $table->text('transport')->nullable();
            $table->text('amenities')->nullable();
            $table->string('emergency_phone')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('logo')->nullable();
            $table->json('portal_tab_order')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('villa_rates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('villa_id')->constrained('villas')->cascadeOnDelete();
            $table->string('label');
            $table->date('start_date');
            $table->date('end_date'); // inclusive
            $table->bigInteger('nightly_rate'); // money: minor units
            $table->unsignedInteger('min_nights')->nullable(); // stored, not enforced (as in the old app)
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['villa_id', 'start_date']);
        });

        Schema::create('villa_sections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('villa_id')->constrained('villas')->cascadeOnDelete();
            $table->string('title');
            $table->string('icon')->nullable();
            $table->text('content')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('villa_bookings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('villa_id')->constrained('villas');
            $table->string('guest_name');
            $table->string('guest_email')->nullable();
            $table->string('guest_phone')->nullable();
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedInteger('guests')->default(2);
            $table->string('portal_token', 64)->unique();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('UPCOMING');
            $table->bigInteger('total_amount')->nullable(); // money: the agreed figure, editable
            $table->decimal('rebate_percent', 5, 2)->default(0);
            $table->bigInteger('auto_calculated_amount')->nullable(); // money: snapshot from the rates at save
            $table->bigInteger('deposit_amount')->nullable();
            $table->boolean('deposit_paid')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'check_in']);
            $table->index(['villa_id', 'check_in', 'check_out']);
        });

        Schema::create('villa_menu_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('villa_id')->constrained('villas')->cascadeOnDelete();
            $table->string('category');
            $table->string('name');
            $table->text('description')->nullable();
            $table->bigInteger('price'); // money: minor units
            $table->string('image')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('vat_included')->default(true);
            // Shows the product's picture; never moves its stock (as in the old app).
            $table->foreignUlid('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->timestamps();

            $table->index(['villa_id', 'category', 'sort_order']);
        });

        Schema::create('villa_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained('villa_bookings')->cascadeOnDelete();
            $table->string('status', 20)->default('PENDING');
            $table->bigInteger('total')->default(0); // money: minor units
            $table->dateTime('delivery_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('stripe_payment_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('villa_order_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('order_id')->constrained('villa_orders')->cascadeOnDelete();
            $table->foreignUlid('menu_item_id')->nullable()->constrained('villa_menu_items')->nullOnDelete();
            $table->string('name'); // snapshot, so a deleted menu item still reads
            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_price'); // money: minor units
            $table->bigInteger('total');
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('villa_order_items');
        Schema::dropIfExists('villa_orders');
        Schema::dropIfExists('villa_menu_items');
        Schema::dropIfExists('villa_bookings');
        Schema::dropIfExists('villa_sections');
        Schema::dropIfExists('villa_rates');
        Schema::dropIfExists('villas');
    }
};
