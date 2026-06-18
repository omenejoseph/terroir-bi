<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vineyard_applications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('parcel_id')->constrained('vineyard_parcels')->cascadeOnDelete();
            $table->foreignUlid('created_by_id')->constrained('users');
            $table->timestamp('date')->useCurrent();
            $table->string('type'); // App\Enums\VineyardApplicationType
            $table->string('product')->nullable();
            $table->string('dosage')->nullable();
            $table->integer('phi_days')->nullable();
            $table->date('phi_end_date')->nullable();
            $table->string('weather')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'parcel_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vineyard_applications');
    }
};
