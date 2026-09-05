<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\PerPage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A read-only, reverse-chronological view of `audit_logs` — currently written
 * only by this task's security-sensitive actions. See AuditLogger's docblock:
 * broader "log every action" instrumentation is a separate later task, and
 * this page grows to cover it then rather than being rebuilt.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $logs = AuditLog::query()
            ->with('actor')
            ->orderByDesc('created_at')
            ->paginate(PerPage::fromRequest($request), ['*'], 'page');

        return Inertia::render('Admin/AuditLogs/Index', [
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
