<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cellar_transfers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('from_lot_id')->constrained('wine_lots')->cascadeOnDelete();
            $table->foreignUlid('to_lot_id')->constrained('wine_lots')->cascadeOnDelete();
            $table->foreignUlid('from_vessel_id')->nullable()->constrained('vessels')->nullOnDelete();
            $table->foreignUlid('to_vessel_id')->nullable()->constrained('vessels')->nullOnDelete();
            $table->foreignUlid('created_by_id')->constrained('users');
            $table->string('type'); // App\Enums\CellarTransferType
            $table->decimal('volume_liters', 12, 3);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'from_lot_id']);
            $table->index(['tenant_id', 'to_lot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cellar_transfers');
    }
};
