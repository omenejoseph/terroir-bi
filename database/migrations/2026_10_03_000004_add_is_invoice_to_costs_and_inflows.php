<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Is this an invoice?" as its own fact. The analytics (invoiced / collected / pending cards,
 * VAT, average invoice, days to pay) used to key off the reserved category "Invoice", which
 * leaves no room for a real expense category on an invoiced cost. The old app decided it by
 * whether the entry was linked to an e-invoice; that link is now this flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['costs', 'inflows'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->boolean('is_invoice')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach (['costs', 'inflows'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropColumn('is_invoice');
            });
        }
    }
};
