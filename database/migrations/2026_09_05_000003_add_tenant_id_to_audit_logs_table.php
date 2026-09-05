<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widens `audit_logs` (see the create migration's docblock: "broader 'log
     * every action' instrumentation is a separate, later task" — this is that
     * task) to carry the acting tenant, so a tenant's own members can see a
     * scoped trail of their estate's activity, not just platform admins.
     *
     * Nullable on purpose: this table now holds both tenant-scoped rows
     * (business actions) and platform-level rows with no tenant at all (e.g.
     * impersonation started by a platform admin who isn't bound to any
     * tenant) — see App\Services\Audit\AuditLogger. Scoping a read to one
     * tenant is a plain `where('tenant_id', ...)`, never a global scope.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignUlid('tenant_id')->nullable()->after('id')
                ->constrained('tenants')->nullOnDelete();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'created_at']);
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
