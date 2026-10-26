<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tenant_settings', 'onboarding')) {
            return;
        }

        Schema::table('tenant_settings', function (Blueprint $table) {
            // The issuer block on order PDFs — the organisation's own address,
            // contact and bank details, beside the existing company_oib.
            $table->string('address')->nullable()->after('company_oib');
            $table->string('city')->nullable()->after('address');
            $table->string('zip', 32)->nullable()->after('city');
            $table->string('country')->nullable()->after('zip');
            $table->string('contact_email')->nullable()->after('country');
            $table->string('contact_phone', 64)->nullable()->after('contact_email');
            $table->string('bank_name')->nullable()->after('contact_phone');
            $table->string('iban', 64)->nullable()->after('bank_name');

            // Setup checklist state that can't be read off real data: skipped
            // steps, steps an admin marked done by hand, and when the
            // dashboard reminder was dismissed. See App\Services\Onboarding.
            $table->json('onboarding')->nullable()->after('revenue_basis');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn([
                'address', 'city', 'zip', 'country', 'contact_email', 'contact_phone', 'bank_name', 'iban', 'onboarding',
            ]);
        });
    }
};
