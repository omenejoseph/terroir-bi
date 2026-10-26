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
 * The Hospitality page's Analytics tab for one year (the old app's getHospitalityAnalytics).
 *
 * Revenue, guests, the monthly series, programs and agencies count every reservation except
 * cancelled ones and those of customers marked "exclude from stats". Conversion is the exception,
 * as in the old app: cancelled bookings stay in its denominator, since a cancellation is exactly
 * what the rate is measuring.
 *
 * Grouped in PHP rather than SQL: a winery takes hundreds of reservations a year, not millions,
 * and PHP keeps the month bucketing identical on sqlite, MySQL and Postgres.
 */
class HospitalityAnalyticsQuery
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function get(int $year, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $currency = $this->currency();
        $money = fn (int $minor): array => Money::fromMinor($minor, $currency)->jsonSerialize();

        $all = $this->inYear($year);
        $counted = $all->filter(fn (Reservation $r): bool => $this->counts($r));
        $previous = $this->inYear($year - 1)->filter(fn (Reservation $r): bool => $this->counts($r));

        $revenue = (int) $counted->sum(fn (Reservation $r): int => $r->total_amount->getMinorAmount());
        $guests = (int) $counted->sum('pax');

        // Ahead of today and still owed: the pipeline, whatever year the page is showing.
        $upcoming = $counted->filter(fn (Reservation $r): bool => $r->date->greaterThan($now) && $r->status->isOpenPipeline());

        $converted = $all->filter(fn (Reservation $r): bool => $r->status->isConverted() && $this->countsCustomer($r))->count();
        $considered = $all->filter(fn (Reservation $r): bool => $this->countsCustomer($r))->count();

        return [
            'year' => $year,
            'years' => $this->years($now),
            'kpis' => [
                'reservations' => $counted->count(),
                'revenue' => $money($revenue),
                'guests' => $guests,
                'revenue_per_guest' => $money($guests > 0 ? intdiv($revenue, $guests) : 0),
                'upcoming' => $upcoming->count(),
                'pipeline' => $money((int) $upcoming->sum(fn (Reservation $r): int => $r->total_amount->getMinorAmount())),
                'conversion_rate' => $considered > 0 ? round($converted / $considered * 100, 1) : null,
            ],
            'months' => $this->months($counted, $previous, $money),
            'programs' => $this->programs($counted, $revenue, $money),
            'top_agencies' => array_slice($this->agencies($counted, $money), 0, 10),
        ];
    }

    /**
     * Every season with a booking, up to next year — reservations are taken ahead.
     *
     * @return list<int>
     */
    private function years(Carbon $now): array
    {
        $first = Reservation::query()->min('date');
        $from = is_string($first) ? Carbon::parse($first)->year : $now->year;

        return range(min($from, $now->year), $now->year + 1);
    }

    /**
     * @return Collection<int, Reservation>
     */
    private function inYear(int $year): Collection
    {
        return Reservation::query()
            ->with(['program:id,name,base_price', 'customer:id,company_name,is_agency,exclude_from_stats'])
            ->whereBetween('date', [Carbon::now()->setYear($year)->startOfYear(), Carbon::now()->setYear($year)->endOfYear()])
            ->get();
    }

    private function counts(Reservation $r): bool
    {
        return $r->status !== ReservationStatus::Cancelled && $this->countsCustomer($r);
    }

    private function countsCustomer(Reservation $r): bool
    {
        return ! $r->customer instanceof Customer || ! $r->customer->exclude_from_stats;
    }

    /**
     * Revenue per calendar month against the same month a year earlier.
     *
     * @param  Collection<int, Reservation>  $current
     * @param  Collection<int, Reservation>  $previous
     * @param  callable(int): array<string, mixed>  $money
     * @return list<array{month: int, revenue: array<string, mixed>, previous_revenue: array<string, mixed>, reservations: int, guests: int}>
     */
    private function months(Collection $current, Collection $previous, callable $money): array
    {
        $bucket = function (Collection $rows): array {
            /** @var array<int, array{revenue: int, reservations: int, guests: int}> $out */
            $out = array_fill(1, 12, ['revenue' => 0, 'reservations' => 0, 'guests' => 0]);
            /** @var Reservation $r */
            foreach ($rows as $r) {
                $m = $r->date->month;
                $was = $out[$m];
                $out[$m] = [
                    'revenue' => $was['revenue'] + $r->total_amount->getMinorAmount(),
                    'reservations' => $was['reservations'] + 1,
                    'guests' => $was['guests'] + $r->pax,
                ];
            }

            return $out;
        };

        $now = $bucket($current);
        $before = $bucket($previous);

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[] = [
                'month' => $m,
                'revenue' => $money($now[$m]['revenue']),
                'previous_revenue' => $money($before[$m]['revenue']),
                'reservations' => $now[$m]['reservations'],
                'guests' => $now[$m]['guests'],
            ];
        }

        return $months;
    }

    /**
     * Each program's bookings, guests, revenue and share of the year.
     *
     * @param  Collection<int, Reservation>  $rows
     * @param  callable(int): array<string, mixed>  $money
     * @return list<array<string, mixed>>
     */
    private function programs(Collection $rows, int $total, callable $money): array
    {
        $out = [];
        foreach ($rows->groupBy('program_id') as $programId => $group) {
            $program = $group->first()?->program;
            $revenue = (int) $group->sum(fn (Reservation $r): int => $r->total_amount->getMinorAmount());
            $guests = (int) $group->sum('pax');

            $out[] = [
                'program_id' => (string) $programId,
                'name' => $program instanceof HospitalityProgram ? $program->name : '—',
                'reservations' => $group->count(),
                'guests' => $guests,
                'revenue' => $money($revenue),
                'revenue_per_guest' => $money($guests > 0 ? intdiv($revenue, $guests) : 0),
                'share' => $total > 0 ? round($revenue / $total * 100, 1) : 0.0,
            ];
        }

        usort($out, fn (array $a, array $b): int => $b['revenue']['minor'] <=> $a['revenue']['minor']);

        return $out;
    }

    /**
     * Agencies ranked by revenue. Walk-ins and direct customers are not agencies and stay out.
     *
     * @param  Collection<int, Reservation>  $rows
     * @param  callable(int): array<string, mixed>  $money
     * @return list<array<string, mixed>>
     */
    private function agencies(Collection $rows, callable $money): array
    {
        $out = [];
        $agencyRows = $rows->filter(fn (Reservation $r): bool => $r->customer instanceof Customer && $r->customer->is_agency);

        foreach ($agencyRows->groupBy('customer_id') as $customerId => $group) {
            $out[] = [
                'customer_id' => (string) $customerId,
                'name' => $group->first()?->customer->company_name ?? '—',
                'reservations' => $group->count(),
                'guests' => (int) $group->sum('pax'),
                'revenue' => $money((int) $group->sum(fn (Reservation $r): int => $r->total_amount->getMinorAmount())),
            ];
        }

        usort($out, fn (array $a, array $b): int => $b['revenue']['minor'] <=> $a['revenue']['minor']);

        return $out;
    }

    private function currency(): string
    {
        return $this->tenant->current()?->settings()->first()->default_currency ?? CurrencyRegistry::default()->code;
    }
}
