<?php

namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Services\Ai\CloudflareAiGatewayClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * AI spend monitoring. Local usage logs (requests + tokens) render instantly for
 * the selected period and tenant; per-request USD cost is pulled on demand from
 * the Cloudflare AI Gateway Logs API (the GraphQL analytics API can't group by
 * the per-tenant metadata tag, so logs are aggregated client-side).
 */
class AiSpend extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.ai-spend';

    /** Period preset: 7d | 30d | 90d | ytd | custom. */
    public string $period = '30d';

    public ?string $from = null; // custom range (inclusive)

    public ?string $to = null;

    /** Tenant filter; '' = all tenants. */
    public string $tenantId = '';

    /**
     * Cloudflare spend loaded on demand for the current filter window.
     *
     * @var array<string, mixed>
     */
    public array $cloudflare = [];

    public function getTitle(): string
    {
        return 'AI spend';
    }

    /**
     * @return array<string, string>
     */
    public function periodOptions(): array
    {
        return [
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
            '90d' => 'Last 90 days (quarter)',
            'ytd' => 'Year to date',
            'custom' => 'Custom range',
        ];
    }

    /**
     * Tenants that have AI usage, id => name, for the filter dropdown.
     *
     * @return array<string, string>
     */
    public function tenantOptions(): array
    {
        $ids = DB::table('ai_usage_logs')->whereNotNull('tenant_id')->distinct()->pluck('tenant_id')->all();

        if ($ids === []) {
            return [];
        }

        return Tenant::query()->whereIn('id', $ids)->orderBy('name')->pluck('name', 'id')->all();
    }

    public function windowStart(): Carbon
    {
        return match ($this->period) {
            '7d' => now()->subDays(7),
            '90d' => now()->subDays(90),
            'ytd' => now()->startOfYear(),
            'custom' => $this->from !== null && $this->from !== '' ? Carbon::parse($this->from)->startOfDay() : now()->subDays(30),
            default => now()->subDays(30),
        };
    }

    public function windowEnd(): Carbon
    {
        return $this->period === 'custom' && $this->to !== null && $this->to !== ''
            ? Carbon::parse($this->to)->endOfDay()
            : now();
    }

    /** A filter change invalidates any loaded Cloudflare cost (avoids stale numbers). */
    public function updated(): void
    {
        $this->cloudflare = [];
    }

    public function gatewayConfigured(): bool
    {
        return app(CloudflareAiGatewayClient::class)->configured();
    }

    /** Usage-log query scoped to the current period + tenant filter. */
    private function baseQuery(): Builder
    {
        $query = DB::table('ai_usage_logs')->whereBetween('created_at', [$this->windowStart(), $this->windowEnd()]);

        if ($this->tenantId !== '') {
            $query->where('tenant_id', $this->tenantId);
        }

        return $query;
    }

    /**
     * @return array{requests: int, prompt_tokens: int, completion_tokens: int, cost_usd: float|null}
     */
    public function totals(): array
    {
        $row = $this->baseQuery()
            ->selectRaw('COUNT(*) AS requests, COALESCE(SUM(prompt_tokens),0) AS pt, COALESCE(SUM(completion_tokens),0) AS ct')
            ->first();

        $global = $this->cloudflare['global'] ?? null;

        return [
            'requests' => (int) ($row->requests ?? 0),
            'prompt_tokens' => (int) ($row->pt ?? 0),
            'completion_tokens' => (int) ($row->ct ?? 0),
            'cost_usd' => is_array($global) && isset($global['cost_usd']) ? (float) $global['cost_usd'] : null,
        ];
    }

    /**
     * Per-tenant usage for the window (respects the tenant filter), newest first.
     *
     * @return list<array{tenant: string, requests: int, prompt_tokens: int, completion_tokens: int, cost_usd: float|null}>
     */
    public function byTenant(): array
    {
        $rows = $this->baseQuery()
            ->selectRaw('tenant_id, COUNT(*) AS requests, COALESCE(SUM(prompt_tokens),0) AS pt, COALESCE(SUM(completion_tokens),0) AS ct')
            ->groupBy('tenant_id')
            ->get();

        $names = Tenant::query()
            ->whereIn('id', $rows->pluck('tenant_id')->filter()->all())
            ->pluck('name', 'id');

        $cfByTenant = $this->cloudflare['by_tenant'] ?? [];

        $mapped = $rows->map(fn ($r): array => [
            'tenant' => $r->tenant_id === null ? 'Untagged / back-office' : (string) ($names[$r->tenant_id] ?? $r->tenant_id),
            'requests' => (int) $r->requests,
            'prompt_tokens' => (int) $r->pt,
            'completion_tokens' => (int) $r->ct,
            'cost_usd' => isset($cfByTenant[$r->tenant_id]) ? (float) $cfByTenant[$r->tenant_id]['cost_usd'] : null,
        ])->sortByDesc('requests')->all();

        return array_values($mapped);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('loadCloudflare')
                ->label('Load Cloudflare cost')
                ->icon(Heroicon::OutlinedCloud)
                ->action(function (): void {
                    $client = app(CloudflareAiGatewayClient::class);

                    if (! $client->configured()) {
                        Notification::make()->title('Cloudflare gateway is not configured')->danger()->send();

                        return;
                    }

                    $from = $this->windowStart()->toIso8601String();
                    $to = $this->windowEnd()->toIso8601String();

                    if ($this->tenantId !== '') {
                        $tenant = $client->spendForTenant($this->tenantId, $from, $to);
                        $this->cloudflare = ['global' => $tenant, 'by_tenant' => [$this->tenantId => $tenant]];
                    } else {
                        $this->cloudflare = [
                            'global' => $client->spendGlobal($from, $to),
                            'by_tenant' => $client->spendByTenant($from, $to),
                        ];
                    }

                    Notification::make()->title('Cloudflare cost loaded for the selected period')->success()->send();
                }),
        ];
    }
}
