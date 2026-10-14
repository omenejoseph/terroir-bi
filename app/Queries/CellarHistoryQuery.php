<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\AuditLog;
use App\Models\Bottling;
use App\Models\CellarActivity;
use App\Models\CellarActivityRound;
use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\CellarProcess;
use App\Models\CellarTastingNote;
use App\Models\CellarTransfer;
use App\Models\EnologicalProduct;
use App\Models\FermentationTemplate;
use App\Models\ProtocolAdditive;
use App\Models\TastingReport;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Models\WineLotGrape;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The "History" panel on a lot or vessel page: who changed what, from the
 * audit trail — entries about the lot/vessel itself and about the records
 * that belong to it (additions, analyses, transfers, bottlings, …).
 */
class CellarHistoryQuery
{
    /**
     * Audit subject classes considered cellar records (the Logs page "Cellar" filter).
     *
     * @var list<class-string<Model>>
     */
    public const SUBJECT_TYPES = [
        WineLot::class, Vessel::class, VesselLot::class, WineLotGrape::class, CellarAddition::class,
        CellarAnalysis::class, CellarProcess::class, CellarTastingNote::class, CellarTransfer::class,
        Bottling::class, CellarActivity::class, CellarActivityRound::class, TastingReport::class,
        EnologicalProduct::class, FermentationTemplate::class, ProtocolAdditive::class,
    ];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forLot(WineLot $lot, int $limit = 100): array
    {
        $id = (string) $lot->getKey();
        $children = [
            WineLot::class => [$id],
            VesselLot::class => $this->ids(VesselLot::query()->where('wine_lot_id', $id)),
            WineLotGrape::class => $this->ids(WineLotGrape::query()->where('wine_lot_id', $id)),
            CellarAddition::class => $this->ids(CellarAddition::query()->where('wine_lot_id', $id)),
            CellarAnalysis::class => $this->ids(CellarAnalysis::query()->where('wine_lot_id', $id)),
            CellarProcess::class => $this->ids(CellarProcess::query()->where('wine_lot_id', $id)),
            CellarTastingNote::class => $this->ids(CellarTastingNote::query()->where('wine_lot_id', $id)),
            CellarTransfer::class => $this->ids(CellarTransfer::query()->where('from_lot_id', $id)->orWhere('to_lot_id', $id)),
            Bottling::class => $this->ids(Bottling::query()->where('wine_lot_id', $id)),
            CellarActivity::class => $this->ids(CellarActivity::query()->where('wine_lot_id', $id)),
        ];

        return $this->entries($children, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forVessel(Vessel $vessel, int $limit = 100): array
    {
        $id = (string) $vessel->getKey();

        return $this->entries([
            Vessel::class => [$id],
            VesselLot::class => $this->ids(VesselLot::query()->where('vessel_id', $id)),
            CellarAddition::class => $this->ids(CellarAddition::query()->where('vessel_id', $id)),
            CellarAnalysis::class => $this->ids(CellarAnalysis::query()->where('vessel_id', $id)),
            CellarTastingNote::class => $this->ids(CellarTastingNote::query()->where('vessel_id', $id)),
            CellarActivity::class => $this->ids(CellarActivity::query()->where('vessel_id', $id)),
        ], $limit);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return list<string>
     */
    private function ids(Builder $query): array
    {
        return array_values(array_map(fn ($k): string => (string) $k, $query->pluck('id')->all()));
    }

    /**
     * @param  array<class-string<Model>, list<string>>  $subjects
     * @return list<array<string, mixed>>
     */
    private function entries(array $subjects, int $limit): array
    {
        $logs = AuditLog::query()
            ->with('actor')
            ->where('tenant_id', $this->tenants->id())
            ->where(function ($q) use ($subjects): void {
                foreach ($subjects as $type => $ids) {
                    if ($ids !== []) {
                        $q->orWhere(fn ($w) => $w->where('subject_type', self::morph($type))->whereIn('subject_id', $ids));
                    }
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return array_values($logs->map(fn (AuditLog $log): array => [
            'id' => $log->getKey(),
            'action' => $log->action,
            'actor_name' => $log->actor?->fullName(),
            'subject_type' => class_basename((string) $log->subject_type),
            'subject_id' => $log->subject_id,
            'metadata' => $log->metadata,
            'created_at' => $log->created_at?->toIso8601String(),
        ])->all());
    }

    /**
     * The subject_type AuditLogger stores for a model class.
     *
     * @param  class-string<Model>  $class
     */
    public static function morph(string $class): string
    {
        return (new $class)->getMorphClass();
    }

    /** @return list<string> */
    public static function morphTypes(): array
    {
        return array_map(fn (string $c): string => self::morph($c), self::SUBJECT_TYPES);
    }
}
