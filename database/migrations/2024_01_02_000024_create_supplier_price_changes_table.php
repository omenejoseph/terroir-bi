<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_price_changes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignUlid('supplier_price_item_id')->nullable()->constrained('supplier_price_items')->nullOnDelete();
            $table->string('description');
            $table->string('unit')->nullable();
            $table->bigInteger('old_price')->nullable(); // minor units, null on first entry
            $table->bigInteger('new_price'); // minor units
            $table->timestamp('created_at')->nullable();

            $table->index(['supplier_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_price_changes');
    }
};
