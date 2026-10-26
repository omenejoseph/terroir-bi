<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\HospitalityProgram;
use App\Models\Reservation;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The Analytics tab's agency performance table (the old app's getAgencyPerformance): per agency in
 * a date range, what they booked, what they paid, and the discount they got — the program's base
 * price × guests against what was actually charged. Optionally compared with the window of the
 * same length just before it.
 *
 * Counted like the rest of analytics: no cancelled reservations, no "exclude from stats" customers.
 * The base price is the program's current one; the old app did the same.
 */
class AgencyPerformanceQuery
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array{from: string, to: string, previous_from: ?string, previous_to: ?string, rows: list<array<string, mixed>>}
     */
    public function get(Carbon $from, Carbon $to, bool $compare): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $currency = $this->currency();

        $current = $this->totals($this->rows($from, $to));

        $previous = [];
        $previousFrom = $previousTo = null;
        if ($compare) {
            $days = (int) $from->diffInDays($to) + 1;
            $previousTo = $from->copy()->subDay()->endOfDay();
            $previousFrom = $previousTo->copy()->subDays($days - 1)->startOfDay();
            $previous = $this->totals($this->rows($previousFrom, $previousTo));
        }

        $money = fn (int $minor): array => Money::fromMinor($minor, $currency)->jsonSerialize();

        $rows = [];
        foreach (array_unique([...array_keys($current), ...array_keys($previous)]) as $customerId) {
            $now = $current[$customerId] ?? null;
            $before = $previous[$customerId] ?? null;
            $revenue = $now['revenue'] ?? 0;
            $list = $now['list'] ?? 0;
            $guests = $now['guests'] ?? 0;
            $previousRevenue = $before['revenue'] ?? 0;

            $rows[] = [
                'customer_id' => (string) $customerId,
                'name' => $now['name'] ?? $before['name'] ?? '—',
                'reservations' => $now['reservations'] ?? 0,
                'guests' => $guests,
                'revenue' => $money($revenue),
                'revenue_per_guest' => $money($guests > 0 ? intdiv($revenue, $guests) : 0),
                'list_value' => $money($list),
                'discount' => $money(max(0, $list - $revenue)),
                'discount_percent' => $list > 0 ? round(max(0, $list - $revenue) / $list * 100, 1) : null,
                'previous_revenue' => $compare ? $money($previousRevenue) : null,
                'previous_reservations' => $compare ? ($before['reservations'] ?? 0) : null,
                'change_percent' => $compare && $previousRevenue > 0
                    ? round(($revenue - $previousRevenue) / $previousRevenue * 100, 1)
                    : null,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['revenue']['minor'] <=> $a['revenue']['minor']);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'previous_from' => $previousFrom?->toDateString(),
            'previous_to' => $previousTo?->toDateString(),
            'rows' => $rows,
        ];
    }

    /**
     * @return Collection<int, Reservation>
     */
    private function rows(Carbon $from, Carbon $to): Collection
    {
        return Reservation::query()
            ->with(['program:id,base_price', 'customer:id,company_name,is_agency,exclude_from_stats'])
            ->whereBetween('date', [$from, $to])
            ->where('status', '!=', ReservationStatus::Cancelled)
            ->whereHas('customer', fn ($q) => $q->where('is_agency', true)->where('exclude_from_stats', false))
            ->get();
    }

    /**
     * @param  Collection<int, Reservation>  $rows
     * @return array<string, array{name: string, reservations: int, guests: int, revenue: int, list: int}>
     */
    private function totals(Collection $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $id = (string) $r->customer_id;
            $out[$id] ??= [
                'name' => $r->customer instanceof Customer ? $r->customer->company_name : '—',
                'reservations' => 0,
                'guests' => 0,
                'revenue' => 0,
                'list' => 0,
            ];
            $out[$id]['reservations']++;
            $out[$id]['guests'] += $r->pax;
            $out[$id]['revenue'] += $r->total_amount->getMinorAmount();
            $out[$id]['list'] += $r->program instanceof HospitalityProgram ? $r->program->base_price->getMinorAmount() * $r->pax : 0;
        }

        return $out;
    }

    private function currency(): string
    {
        return $this->tenant->current()?->settings()->first()?->default_currency ?? CurrencyRegistry::default()->code;
    }
}
