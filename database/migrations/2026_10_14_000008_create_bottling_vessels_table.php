<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which vessels a bottling run drew its wine from, and how much from each —
 * so reversing the bottling can put the wine back where it was (the prototype
 * returned it to the lot with no vessel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bottling_vessels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('bottling_id')->constrained('bottlings')->cascadeOnDelete();
            $table->foreignUlid('vessel_id')->constrained('vessels')->cascadeOnDelete();
            $table->decimal('volume', 12, 3);
            $table->timestamps();

            $table->index(['tenant_id', 'bottling_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bottling_vessels');
    }
};
