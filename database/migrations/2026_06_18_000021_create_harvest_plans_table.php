<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('harvest_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('created_by_id')->constrained('users');
            $table->string('name');
            $table->string('season');
            $table->string('status')->default('PLANNING'); // App\Enums\HarvestPlanStatus
            $table->decimal('yield_ratio', 5, 3)->default(0.650);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'season']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('harvest_plans');
    }
};
