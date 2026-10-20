<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An allocation order sets stock aside for its customer without moving it: the bottles stay in the
 * cellar (and in `current_stock`) but are not free to sell, and leave for real when the order ships.
 * It is active while `is_allocation` is true and `deduct_stock` is still false; shipping flips the
 * latter, which is what turns the reservation into a normal deduction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->boolean('is_allocation')->default(false)->after('is_consignment');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('is_allocation');
        });
    }
};
