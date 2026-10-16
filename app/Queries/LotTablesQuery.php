<?php

declare(strict_types=1);

namespace App\Queries;

use App\DataTransferObjects\LotRows;
use App\Models\Bottling;
use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\CellarProcess;
use App\Models\CellarTastingNote;
use App\Models\CellarTransfer;
use App\Models\WineLot;
use App\Support\ArrayPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The lot page's tables, one page at a time, newest first, narrowed to the barrel in scope (see
 * LotScope) — filtered and paginated in the database, so a lot with years of readings never ships
 * them all. A record with no vessel (a reading or addition made on the lot as a whole) is kept in a
 * barrel's view, as the prototype did, because it applies to every barrel.
 *
 * Every method returns the `{data, meta}` envelope the Pagination component takes.
 *
 * @phpstan-type Envelope array{data: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
 */
class LotTablesQuery
{
    public const TABS = ['analysis', 'additions', 'tasting', 'transfers', 'processes'];

    public function __construct(private readonly LotTimelineQuery $timeline) {}

    /** @return Envelope */
    public function analyses(WineLot $lot, ?string $vessel, int $perPage, int $page): array
    {
        $q = CellarAnalysis::query()->with('vessel:id,name')->where('wine_lot_id', $lot->getKey());

        return $this->page($this->ownOrLotWide($q, $vessel)->orderByDesc('date')->orderByDesc('created_at'), $perPage, $page, fn (CellarAnalysis $a): array => LotRows::analysis($a));
    }

    /** @return Envelope */
    public function additions(WineLot $lot, ?string $vessel, int $perPage, int $page): array
    {
        $q = CellarAddition::query()->with('vessel:id,name')->where('wine_lot_id', $lot->getKey());

        return $this->page($this->ownOrLotWide($q, $vessel)->orderByDesc('created_at'), $perPage, $page, fn (CellarAddition $a): array => LotRows::addition($a));
    }

    /** @return Envelope */
    public function tastings(WineLot $lot, ?string $vessel, int $perPage, int $page): array
    {
        $q = CellarTastingNote::query()->with('vessel:id,name')->where('wine_lot_id', $lot->getKey());

        return $this->page($this->ownOrLotWide($q, $vessel)->orderByDesc('date')->orderByDesc('created_at'), $perPage, $page, fn (CellarTastingNote $n): array => LotRows::tasting($n));
    }

    /** @return Envelope */
    public function processes(WineLot $lot, ?string $vessel, int $perPage, int $page): array
    {
        $q = CellarProcess::query()->with('vessel:id,name')->where('wine_lot_id', $lot->getKey());

        return $this->page($this->ownOrLotWide($q, $vessel)->orderByDesc('date')->orderByDesc('created_at'), $perPage, $page, fn (CellarProcess $p): array => LotRows::process($p));
    }

    /**
     * Transfers in and out of the lot (a barrel's view: those that left or entered that barrel).
     *
     * @return Envelope
     */
    public function transfers(WineLot $lot, ?string $vessel, int $perPage, int $page): array
    {
        $id = (string) $lot->getKey();
        $q = CellarTransfer::query()
            ->with(['fromLot:id,name', 'toLot:id,name', 'fromVessel:id,name', 'toVessel:id,name'])
            ->where(fn (Builder $w) => $w->where('from_lot_id', $id)->orWhere('to_lot_id', $id))
            ->when($vessel !== null, fn (Builder $w) => $w->where(fn (Builder $v) => $v->where('from_vessel_id', $vessel)->orWhere('to_vessel_id', $vessel)))
            ->orderByDesc('created_at');

        return $this->page($q, $perPage, $page, fn (CellarTransfer $t): array => LotRows::transfer($t, $id));
    }

    /**
     * Bottlings are lot-wide (they have no vessel), so a barrel view shows them all.
     *
     * @return Envelope
     */
    public function bottlings(WineLot $lot, int $perPage, int $page): array
    {
        $q = Bottling::query()->with('inventoryItem:id,name')->where('wine_lot_id', $lot->getKey())->orderByDesc('date');

        return $this->page($q, $perPage, $page, fn (Bottling $b): array => LotRows::bottling($b));
    }

    /**
     * The lot's journey: built in PHP from its eleven record kinds, so it is filtered by kind and scope
     * and then sliced to the page here.
     *
     * @param  list<string>|null  $types
     * @return Envelope
     */
    public function journey(WineLot $lot, ?string $vessel, ?array $types, int $perPage, int $page): array
    {
        $events = collect($this->timeline->get($lot))
            ->when($vessel !== null, fn ($c) => $c->filter(fn (array $e): bool => $e['vessel_ids'] === [] || in_array($vessel, $e['vessel_ids'], true)))
            ->when($types !== null, fn ($c) => $c->filter(fn (array $e): bool => in_array($e['type'], $types, true)))
            ->values();

        return $this->slice($events->all(), $perPage, $page);
    }

    /**
     * Row counts for the tab labels, in the same scope as the tables.
     *
     * @return array<string, int>
     */
    public function counts(WineLot $lot, ?string $vessel): array
    {
        $id = (string) $lot->getKey();
        $own = fn (Builder $q): Builder => $this->ownOrLotWide($q, $vessel);

        return [
            'barrels' => $lot->vesselLots()->count(),
            'analysis' => $own(CellarAnalysis::query()->where('wine_lot_id', $id))->count(),
            'additions' => $own(CellarAddition::query()->where('wine_lot_id', $id))->count(),
            'tasting' => $own(CellarTastingNote::query()->where('wine_lot_id', $id))->count(),
            'processes' => $own(CellarProcess::query()->where('wine_lot_id', $id))->count(),
            'transfers' => CellarTransfer::query()
                ->where(fn (Builder $w) => $w->where('from_lot_id', $id)->orWhere('to_lot_id', $id))
                ->when($vessel !== null, fn (Builder $w) => $w->where(fn (Builder $v) => $v->where('from_vessel_id', $vessel)->orWhere('to_vessel_id', $vessel)))
                ->count(),
        ];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $q
     * @return Builder<TModel>
     */
    private function ownOrLotWide(Builder $q, ?string $vessel): Builder
    {
        return $vessel === null ? $q : $q->where(fn (Builder $w) => $w->where('vessel_id', $vessel)->orWhereNull('vessel_id'));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $q
     * @param  callable(TModel): array<string, mixed>  $map
     * @return Envelope
     */
    private function page(Builder $q, int $perPage, int $page, callable $map): array
    {
        /** @var LengthAwarePaginator<int, TModel> $p */
        $p = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => array_values($p->getCollection()->map($map)->all()),
            'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage(), 'total' => $p->total()],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return Envelope
     */
    public function slice(array $rows, int $perPage, int $page): array
    {
        return ArrayPage::of($rows, $perPage, $page);
    }
}
