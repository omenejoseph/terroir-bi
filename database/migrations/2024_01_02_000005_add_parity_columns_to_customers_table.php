<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer columns the source app's main carries that weren't in the first
 * snapshot. See docs/10-migration-deltas.md §A. reorder_contacted_at backs the
 * reorder radar (whose query lands post-Orders).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('customer_type')->nullable()->after('notes');
            $table->string('oib')->nullable()->after('country'); // OIB / EU VAT
            $table->boolean('is_agency')->default(false)->after('hide_prices');
            $table->boolean('allow_single_bottle')->default(false)->after('is_agency');
            $table->timestamp('reorder_contacted_at')->nullable()->after('pricing_tier_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'customer_type', 'oib', 'is_agency', 'allow_single_bottle', 'reorder_contacted_at',
            ]);
        });
    }
};
