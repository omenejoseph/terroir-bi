<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates AI usage logs for the back-office spend page. This is a platform-
 * wide (super-admin) view, so it is intentionally NOT tenant-scoped. It owns the
 * local request/token rollups; USD cost is layered on separately by the page
 * from the Cloudflare Logs API.
 *
 * @phpstan-type AiSpendRow array{tenant_id: mixed, tenant: string, requests: int, prompt_tokens: int, completion_tokens: int}
 */
class AiSpendQuery
{
    /**
     * Headline totals for the window (optionally one tenant).
     *
     * @return array{requests: int, prompt_tokens: int, completion_tokens: int}
     */
    public function totals(Carbon $from, Carbon $to, ?string $tenantId = null): array
    {
        $row = $this->base($from, $to, $tenantId)
            ->selectRaw('COUNT(*) AS requests, COALESCE(SUM(prompt_tokens),0) AS pt, COALESCE(SUM(completion_tokens),0) AS ct')
            ->first();

        return [
            'requests' => (int) ($row->requests ?? 0),
            'prompt_tokens' => (int) ($row->pt ?? 0),
            'completion_tokens' => (int) ($row->ct ?? 0),
        ];
    }

    /**
     * Per-tenant rollup, paginated, with tenant names resolved.
     *
     * @return LengthAwarePaginator<int, AiSpendRow>
     */
    public function perTenant(Carbon $from, Carbon $to, ?string $tenantId = null, int $perPage = 15): LengthAwarePaginator
    {
        $grouped = $this->base($from, $to, $tenantId)
            ->groupBy('tenant_id')
            ->selectRaw('tenant_id, COUNT(*) AS requests, COALESCE(SUM(prompt_tokens),0) AS pt, COALESCE(SUM(completion_tokens),0) AS ct')
            ->orderByDesc('requests');

        $page = Paginator::resolveCurrentPage();
        $total = DB::query()->fromSub((clone $grouped), 'g')->count();
        $rows = (clone $grouped)->forPage($page, $perPage)->get();

        $names = Tenant::query()
            ->whereIn('id', $rows->pluck('tenant_id')->filter()->all())
            ->pluck('name', 'id');

        $items = $rows->map(fn ($r): array => [
            'tenant_id' => $r->tenant_id,
            'tenant' => $r->tenant_id === null ? 'Untagged / back-office' : (string) ($names[$r->tenant_id] ?? $r->tenant_id),
            'requests' => (int) $r->requests,
            'prompt_tokens' => (int) $r->pt,
            'completion_tokens' => (int) $r->ct,
        ])->all();

        return new Paginator($items, $total, $perPage, $page, ['path' => Paginator::resolveCurrentPath()]);
    }

    /**
     * Tenants that have any AI usage, id => name, for the filter dropdown.
     *
     * @return array<string, string>
     */
    public function tenantsWithUsage(): array
    {
        $ids = DB::table('ai_usage_logs')->whereNotNull('tenant_id')->distinct()->pluck('tenant_id')->all();

        if ($ids === []) {
            return [];
        }

        return Tenant::query()->whereIn('id', $ids)->orderBy('name')->pluck('name', 'id')->all();
    }

    private function base(Carbon $from, Carbon $to, ?string $tenantId): Builder
    {
        $query = DB::table('ai_usage_logs')->whereBetween('created_at', [$from, $to]);

        if ($tenantId !== null && $tenantId !== '') {
            $query->where('tenant_id', $tenantId);
        }

        return $query;
    }
}
