<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Old cuid → new ULID, persisted so partial re-runs and later delta syncs resolve FKs. */
    public function up(): void
    {
        Schema::create('legacy_id_map', function (Blueprint $table): void {
            $table->id();
            $table->ulid('tenant_id');
            $table->string('legacy_table', 64);
            $table->string('legacy_id', 64);
            $table->ulid('new_id');
            $table->timestamps();

            $table->unique(['tenant_id', 'legacy_table', 'legacy_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_id_map');
    }
};
