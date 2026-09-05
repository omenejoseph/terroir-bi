<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\PerPage;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A read-only, reverse-chronological view of the current tenant's own audit
 * trail — the per-tenant counterpart of Web\Admin\AuditLogController, which is
 * platform-wide and has no tenant scoping at all. Same response shape as that
 * page (see its docblock), scoped here with a plain `where('tenant_id', ...)`
 * rather than a global scope, since `AuditLog` deliberately isn't
 * App\Tenancy\BelongsToTenant — see that model's docblock.
 */
class LogController extends Controller
{
    public function index(Request $request, TenantContext $tenants): Response
    {
        $logs = AuditLog::query()
            ->where('tenant_id', $tenants->id())
            ->with('actor')
            ->orderByDesc('created_at')
            ->paginate(PerPage::fromRequest($request), ['*'], 'page');

        return Inertia::render('Logs/Index', [
            'logs' => [
                'data' => array_map(fn (AuditLog $log): array => [
                    'id' => $log->getKey(),
                    'actor_name' => $log->actor?->fullName(),
                    'action' => $log->action,
                    'subject_type' => $log->subject_type,
                    'subject_id' => $log->subject_id,
                    'metadata' => $log->metadata,
                    'created_at' => $log->created_at?->toIso8601String(),
                ], $logs->items()),
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                ],
            ],
        ]);
    }
}
