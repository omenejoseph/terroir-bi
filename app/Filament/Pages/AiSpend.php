<?php

namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Services\Ai\CloudflareAiGatewayClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * AI spend monitoring. Local usage logs (requests + tokens) render instantly and
 * always; the per-request USD cost is pulled on demand from the Cloudflare AI
 * Gateway Logs API (the GraphQL analytics API can't group by the per-tenant
 * metadata tag, so we aggregate the logs client-side).
 */
class AiSpend extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.ai-spend';

    public int $days = 30;

    /**
     * Cloudflare spend loaded on demand: ['global' => array, 'by_tenant' => array].
     *
     * @var array<string, mixed>
     */
    public array $cloudflare = [];

    public function getTitle(): string
    {
        return 'AI spend';
    }

    private function since(): Carbon
    {
        return now()->subDays($this->days);
    }

    public function gatewayConfigured(): bool
    {
        return app(CloudflareAiGatewayClient::class)->configured();
    }

    /**
     * @return array{requests: int, prompt_tokens: int, completion_tokens: int}
     */
    public function localTotals(): array
    {
        // Query builder (stdClass rows) — usage logs are not tenant-scoped.
        $row = DB::table('ai_usage_logs')
            ->where('created_at', '>=', $this->since())
            ->selectRaw('COUNT(*) AS requests, COALESCE(SUM(prompt_tokens),0) AS pt, COALESCE(SUM(completion_tokens),0) AS ct')
            ->first();

        return [
            'requests' => (int) ($row->requests ?? 0),
            'prompt_tokens' => (int) ($row->pt ?? 0),
            'completion_tokens' => (int) ($row->ct ?? 0),
        ];
    }

    /**
     * Per-tenant local usage, newest window, with tenant names resolved.
     *
     * @return list<array{tenant: string, requests: int, prompt_tokens: int, completion_tokens: int, cost_usd: float|null}>
     */
    public function byTenant(): array
    {
        $rows = DB::table('ai_usage_logs')
            ->where('created_at', '>=', $this->since())
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
                ->label('Load Cloudflare spend')
                ->icon(Heroicon::OutlinedCloud)
                ->action(function (): void {
                    $client = app(CloudflareAiGatewayClient::class);

                    if (! $client->configured()) {
                        Notification::make()->title('Cloudflare gateway is not configured')->danger()->send();

                        return;
                    }

                    $from = $this->since()->toIso8601String();
                    $to = now()->toIso8601String();

                    $this->cloudflare = [
                        'global' => $client->spendGlobal($from, $to),
                        'by_tenant' => $client->spendByTenant($from, $to),
                    ];

                    Notification::make()->title('Cloudflare spend loaded')->success()->send();
                }),
        ];
    }
}
