<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Queries\AiSpendQuery;
use App\Services\Ai\CloudflareAiGatewayClient;
use App\Support\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Port of App\Filament\Pages\AiSpend. Local usage totals render from
 * AiSpendQuery immediately; USD cost is pulled from the Cloudflare AI Gateway
 * Logs API only on demand ("Load Cloudflare cost") — same as Filament's
 * component-state `$cloudflare`, here returned as JSON and merged into the
 * page's local state rather than persisted as an Inertia prop.
 */
class AiSpendController extends Controller
{
    private const PERIODS = ['7d', '30d', '90d', 'ytd', 'custom'];

    public function index(Request $request, AiSpendQuery $query, CloudflareAiGatewayClient $client): Response
    {
        $period = $this->period($request);
        $tenantId = (string) $request->query('tenant_id', '');
        [$from, $to] = $this->window($period, $request->query('from'), $request->query('to'));

        $totals = $query->totals($from, $to, $tenantId !== '' ? $tenantId : null);
        $perTenant = $query->perTenant($from, $to, $tenantId !== '' ? $tenantId : null, PerPage::fromRequest($request));

        return Inertia::render('Admin/AiSpend/Index', [
            'filters' => [
                'period' => $period,
                'from' => is_string($request->query('from')) ? $request->query('from') : null,
                'to' => is_string($request->query('to')) ? $request->query('to') : null,
                'tenant_id' => $tenantId !== '' ? $tenantId : null,
            ],
            'periodOptions' => [
                ['value' => '7d', 'label' => __('Last 7 days')],
                ['value' => '30d', 'label' => __('Last 30 days')],
                ['value' => '90d', 'label' => __('Last 90 days (quarter)')],
                ['value' => 'ytd', 'label' => __('Year to date')],
                ['value' => 'custom', 'label' => __('Custom range')],
            ],
            'tenantOptions' => collect($query->tenantsWithUsage())
                ->map(fn (string $name, string $id): array => ['value' => $id, 'label' => $name])
                ->values(),
            'gatewayConfigured' => $client->configured(),
            'totals' => $totals,
            'perTenant' => [
                'data' => collect($perTenant->items())->values()->all(),
                'meta' => [
                    'current_page' => $perTenant->currentPage(),
                    'last_page' => $perTenant->lastPage(),
                    'per_page' => $perTenant->perPage(),
                    'total' => $perTenant->total(),
                ],
            ],
        ]);
    }

    /** Fetched on demand by the "Load Cloudflare cost" button — not a page prop. */
    public function loadCloudflareCost(Request $request, CloudflareAiGatewayClient $client): JsonResponse
    {
        if (! $client->configured()) {
            return response()->json(['message' => __('Cloudflare gateway is not configured.')], 422);
        }

        $period = $this->period($request);
        $tenantId = (string) $request->query('tenant_id', '');
        [$from, $to] = $this->window($period, $request->query('from'), $request->query('to'));
        $fromIso = $from->toIso8601String();
        $toIso = $to->toIso8601String();

        if ($tenantId !== '') {
            $spend = $client->spendForTenant($tenantId, $fromIso, $toIso);

            return response()->json(['global' => $spend, 'by_tenant' => [$tenantId => $spend]]);
        }

        return response()->json([
            'global' => $client->spendGlobal($fromIso, $toIso),
            'by_tenant' => $client->spendByTenant($fromIso, $toIso),
        ]);
    }

    private function period(Request $request): string
    {
        $period = $request->query('period');

        return is_string($period) && in_array($period, self::PERIODS, true) ? $period : '30d';
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(string $period, mixed $from, mixed $to): array
    {
        $start = match ($period) {
            '7d' => now()->subDays(7),
            '90d' => now()->subDays(90),
            'ytd' => now()->startOfYear(),
            'custom' => is_string($from) && $from !== '' ? Carbon::parse($from)->startOfDay() : now()->subDays(30),
            default => now()->subDays(30),
        };

        $end = $period === 'custom' && is_string($to) && $to !== ''
            ? Carbon::parse($to)->endOfDay()
            : now();

        return [$start, $end];
    }
}
