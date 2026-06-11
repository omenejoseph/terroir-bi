<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Whether catalog lines deduct stock at creation. Standard/consignment
            // orders deduct now; backorders default to false (stock leaves when
            // fulfilled) but may opt in.
            $table->boolean('deduct_stock')->default(true)->after('backorder_date');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('deduct_stock');
        });
    }
};
