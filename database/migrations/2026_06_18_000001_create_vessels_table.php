<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vessels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('type'); // App\Enums\VesselType
            $table->string('material')->nullable();
            $table->decimal('capacity_liters', 12, 3);
            $table->decimal('current_volume', 12, 3)->default(0); // derived: Σ vessel_lots.volume
            $table->string('location')->nullable();
            $table->string('status')->default('AVAILABLE'); // App\Enums\VesselStatus
            $table->boolean('is_active')->default(true);
            $table->boolean('is_faulty')->default(false);
            $table->string('fault_note')->nullable();
            // Cellar-map placement.
            $table->string('room')->default('Main Cellar');
            $table->integer('position_x')->nullable();
            $table->integer('position_y')->nullable();
            $table->integer('map_width')->nullable();
            $table->integer('map_height')->nullable();
            $table->integer('rotation')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'status']);
            $table->index(['tenant_id', 'room']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vessels');
    }
};
