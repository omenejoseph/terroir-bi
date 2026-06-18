<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vessel_lots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('vessel_id')->constrained('vessels')->cascadeOnDelete();
            $table->foreignUlid('wine_lot_id')->constrained('wine_lots')->cascadeOnDelete();
            $table->decimal('volume', 12, 3); // how much of the lot lives in this vessel
            $table->timestamp('added_at')->useCurrent();
            $table->timestamps();

            $table->index(['tenant_id', 'vessel_id']);
            $table->index(['tenant_id', 'wine_lot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vessel_lots');
    }
};
