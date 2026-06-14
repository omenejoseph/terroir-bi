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
        $name = 'translation_overrides';

        // Collapse duplicates across tenants: keep the earliest row per locale+key.
        $seen = [];
        foreach (DB::table($name)->orderBy('id')->get(['id', 'locale', 'key']) as $row) {
            $signature = $row->locale.'|'.$row->key;
            if (isset($seen[$signature])) {
                DB::table($name)->where('id', $row->id)->delete();
            } else {
                $seen[$signature] = true;
            }
        }

        // Idempotent: a partial earlier run may already have dropped some of these.
        // The foreign key must go first — MySQL backs it with the (tenant_id, locale)
        // index and refuses to drop that index while the constraint exists.
        $indexes = collect(Schema::getIndexes($name))->pluck('name')->all();
        $hasTenantFk = collect(Schema::getForeignKeys($name))
            ->contains(fn (array $fk) => in_array('tenant_id', $fk['columns'], true));

        Schema::table($name, function (Blueprint $table) use ($name, $indexes, $hasTenantFk) {
            if ($hasTenantFk) {
                $table->dropForeign(['tenant_id']);
            }
            if (in_array($name.'_tenant_id_locale_key_unique', $indexes, true)) {
                $table->dropUnique(['tenant_id', 'locale', 'key']);
            }
            if (in_array($name.'_tenant_id_locale_index', $indexes, true)) {
                $table->dropIndex(['tenant_id', 'locale']);
            }
        });

        if (Schema::hasColumn($name, 'tenant_id')) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('tenant_id'));
        }

        $indexes = collect(Schema::getIndexes($name))->pluck('name')->all();
        if (! in_array($name.'_locale_key_unique', $indexes, true)) {
            Schema::table($name, fn (Blueprint $table) => $table->unique(['locale', 'key']));
        }
    }

    public function down(): void
    {
        Schema::table('translation_overrides', function (Blueprint $table) {
            $table->dropUnique(['locale', 'key']);
            $table->foreignUlid('tenant_id')->nullable()->after('id')->constrained('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'locale', 'key']);
            $table->index(['tenant_id', 'locale']);
        });
    }
};
