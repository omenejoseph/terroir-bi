<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique(); // the only globally-unique business key
            $table->string('status')->default('TRIAL'); // App\Enums\TenantStatus
            $table->string('isolation_mode')->default('shared_row'); // shared_row | dedicated_db
            $table->foreignUlid('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('default_locale')->default('hr'); // convenience mirror of settings
            $table->json('data')->nullable(); // tenancy-driver internal/virtual keys
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
