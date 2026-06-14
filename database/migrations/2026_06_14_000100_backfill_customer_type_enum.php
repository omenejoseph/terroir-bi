<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `customer_type` graduated from free text to the CustomerType enum
 * (WHOLESALE/RETAIL/AGENCY/SHIPSHOP/OTHER). Map existing values best-effort so
 * the cast never trips on a stale string; staff can correct via the form.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Shops / wine shops → Retail.
        foreach (['%shop%', '%retail%', '%vinotek%'] as $needle) {
            DB::table('customers')->whereRaw('lower(customer_type) like ?', [$needle])->update(['customer_type' => 'RETAIL']);
        }

        // Consignment partners → Shipshop.
        foreach (['%shipshop%', '%consign%'] as $needle) {
            DB::table('customers')->whereRaw('lower(customer_type) like ?', [$needle])->update(['customer_type' => 'SHIPSHOP']);
        }

        // The agency flag wins as the primary channel.
        DB::table('customers')->where('is_agency', true)->update(['customer_type' => 'AGENCY']);

        // Everything else (null or unrecognised free text) defaults to Wholesale.
        DB::table('customers')
            ->whereNotIn('customer_type', ['WHOLESALE', 'RETAIL', 'AGENCY', 'SHIPSHOP', 'OTHER'])
            ->orWhereNull('customer_type')
            ->update(['customer_type' => 'WHOLESALE']);
    }

    public function down(): void
    {
        // One-way data normalisation — nothing to restore.
    }
};
