<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One row per production run of the Produce tab: what was made, by whom, and what it took. Before this the
 * only trace was a PRODUCTION_IN / PRODUCTION_OUT movement pair sharing a reference, which cannot say which
 * outputs belonged to which run — so earlier runs are carried over from their PRODUCTION_IN movements
 * (quantity, date, who), without the inputs they used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->string('reference');
            // [{name, sku, unit, quantity}] — what the run took from stock, as it was then.
            $table->json('inputs')->nullable();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'inventory_item_id', 'created_at']);
        });

        DB::table('stock_movements')
            ->where('type', 'PRODUCTION_IN')
            ->orderBy('id')
            ->chunkById(200, function ($movements): void {
                foreach ($movements as $m) {
                    DB::table('production_runs')->insert([
                        'id' => (string) Str::ulid(),
                        'tenant_id' => $m->tenant_id,
                        'inventory_item_id' => $m->inventory_item_id,
                        'quantity' => $m->quantity,
                        'reference' => $m->reference ?? '',
                        'inputs' => null,
                        'created_by_id' => $m->created_by_id,
                        'created_at' => $m->created_at,
                        'updated_at' => $m->created_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_runs');
    }
};
