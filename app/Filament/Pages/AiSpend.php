<?php

namespace App\Filament\Pages;

use App\Queries\AiSpendQuery;
use App\Services\Ai\CloudflareAiGatewayClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\WithPagination;
use UnitEnum;

/**
 * AI spend monitoring. Local usage logs (requests + tokens) render instantly for
 * the selected period and tenant; per-request USD cost is pulled on demand from
 * the Cloudflare AI Gateway Logs API. All data access goes through AiSpendQuery —
 * this component holds no direct DB queries.
 *
 * @phpstan-import-type AiSpendRow from AiSpendQuery
 */
class AiSpend extends Page
{
    use WithPagination;

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
     * @return array<string, string>
     */
    public function tenantOptions(): array
    {
        return app(AiSpendQuery::class)->tenantsWithUsage();
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

    // A filter change resets loaded cost (avoids stale numbers) and the page cursor.
    public function updatedPeriod(): void
    {
        $this->onFilterChange();
    }

    public function updatedFrom(): void
    {
        $this->onFilterChange();
    }

    public function updatedTo(): void
    {
        $this->onFilterChange();
    }

    public function updatedTenantId(): void
    {
        $this->onFilterChange();
    }

    public function gatewayConfigured(): bool
    {
        return app(CloudflareAiGatewayClient::class)->configured();
    }

    /**
     * @return array{requests: int, prompt_tokens: int, completion_tokens: int, cost_usd: float|null}
     */
    public function totals(): array
    {
        $totals = app(AiSpendQuery::class)->totals($this->windowStart(), $this->windowEnd(), $this->tenantId ?: null);
        $global = $this->cloudflare['global'] ?? null;

        return [
            ...$totals,
            'cost_usd' => is_array($global) && isset($global['cost_usd']) ? (float) $global['cost_usd'] : null,
        ];
    }

    /**
     * @return LengthAwarePaginator<int, AiSpendRow>
     */
    public function perTenant(): LengthAwarePaginator
    {
        return app(AiSpendQuery::class)->perTenant($this->windowStart(), $this->windowEnd(), $this->tenantId ?: null);
    }

    /** USD cost for a tenant from the loaded Cloudflare data (no DB). */
    public function tenantCost(?string $tenantId): ?float
    {
        $byTenant = $this->cloudflare['by_tenant'] ?? [];

        return is_array($byTenant) && $tenantId !== null && isset($byTenant[$tenantId]['cost_usd'])
            ? (float) $byTenant[$tenantId]['cost_usd']
            : null;
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

    private function onFilterChange(): void
    {
        $this->cloudflare = [];
        $this->resetPage();
    }
}
