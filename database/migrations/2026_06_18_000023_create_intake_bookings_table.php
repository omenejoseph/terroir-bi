<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_bookings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('harvest_plan_id')->nullable()->constrained('harvest_plans')->nullOnDelete();
            $table->foreignUlid('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->timestamp('date');
            $table->string('time_slot')->nullable();
            $table->string('grape_variety')->nullable();
            $table->decimal('estimated_kg', 12, 3)->nullable();
            $table->string('grower_name')->nullable();
            $table->string('status')->default('SCHEDULED'); // App\Enums\IntakeBookingStatus
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_bookings');
    }
};
