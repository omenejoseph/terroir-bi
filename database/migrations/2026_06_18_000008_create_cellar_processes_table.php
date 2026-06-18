<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cellar_processes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('wine_lot_id')->constrained('wine_lots')->cascadeOnDelete();
            $table->foreignUlid('vessel_id')->nullable()->constrained('vessels')->nullOnDelete();
            $table->foreignUlid('created_by_id')->constrained('users');
            $table->timestamp('date')->useCurrent();
            $table->string('kind'); // Press, Racking, Filter, Pump-over, Batonnage, …
            $table->decimal('volume', 12, 3)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'wine_lot_id', 'date']);
            $table->index(['tenant_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cellar_processes');
    }
};
