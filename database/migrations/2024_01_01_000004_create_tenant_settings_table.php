<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('default_currency', 3)->default('EUR'); // org's chosen base currency
            $table->string('default_locale')->default('hr');
            $table->string('company_oib')->nullable(); // Croatian tax id
            $table->string('storage_prefix')->nullable(); // tenant file-key namespace
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
