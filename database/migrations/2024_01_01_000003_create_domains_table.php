<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subdomain / custom-domain -> tenant mapping.
 *
 * Kept stancl-compatible in shape so we can opt into stancl's domain resolver
 * later without a migration. Resolution is currently handled by our own
 * StanclTenantAdapter::resolveFromSubdomain().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('domain')->unique();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
