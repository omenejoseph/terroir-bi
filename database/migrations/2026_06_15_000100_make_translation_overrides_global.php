<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Translation overrides graduated from per-tenant to platform-wide (managed in
 * the back office). Collapse duplicates, drop tenant_id, and make (locale, key)
 * globally unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Collapse duplicates across tenants: keep the earliest row per locale+key.
        $seen = [];
        foreach (DB::table('translation_overrides')->orderBy('id')->get(['id', 'locale', 'key']) as $row) {
            $signature = $row->locale.'|'.$row->key;
            if (isset($seen[$signature])) {
                DB::table('translation_overrides')->where('id', $row->id)->delete();
            } else {
                $seen[$signature] = true;
            }
        }

        Schema::table('translation_overrides', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'locale', 'key']);
            $table->dropIndex(['tenant_id', 'locale']);
            $table->dropConstrainedForeignId('tenant_id');
        });

        Schema::table('translation_overrides', function (Blueprint $table) {
            $table->unique(['locale', 'key']);
            $table->index('locale');
        });
    }

    public function down(): void
    {
        Schema::table('translation_overrides', function (Blueprint $table) {
            $table->dropUnique(['locale', 'key']);
            $table->dropIndex(['locale']);
            $table->foreignUlid('tenant_id')->nullable()->after('id')->constrained('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'locale', 'key']);
            $table->index(['tenant_id', 'locale']);
        });
    }
};
