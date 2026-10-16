<?php

declare(strict_types=1);

namespace App\Services\LegacyImport\Steps;

use App\Enums\CellarTransferType;
use App\Enums\DoseBasis;
use App\Enums\VesselStatus;
use App\Enums\VesselType;
use App\Enums\WineLotStatus;
use App\Enums\WineType;
use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\Support\Normalize;
use App\Services\LegacyImport\Support\Sql;
use Illuminate\Support\Facades\DB;

/**
 * Enological products, fermentation templates, vessels, wine lots (+ grapes, vessel contents)
 * and cellar activity (analyses, additions, processes, transfers, tastings).
 *
 * Vessel/lot volumes are imported as the legacy figures (the rebuild derives them from
 * vessel_lots via VesselVolumeSync); any disagreement is reported rather than "fixed".
 */
class CellarStep extends AbstractStep
{
    /** @var array<string, string>|null lower-cased vessel name → legacy vessel id */
    private ?array $vesselsByName = null;

    public function name(): string
    {
        return 'cellar';
    }

    public function dependsOn(): array
    {
        return ['users', 'inventory', 'suppliers'];
    }

    public function run(ImportContext $ctx): void
    {
        $this->products($ctx);
        $this->templates($ctx);
        $this->protocolAdditives($ctx);
        $this->vessels($ctx);
        $this->lots($ctx);
        $this->lotGrapes($ctx);
        $this->vesselLots($ctx);
        $this->analyses($ctx);
        $this->additions($ctx);
        $this->processes($ctx);
        $this->transfers($ctx);
        $this->bottlings($ctx);
        $this->tastings($ctx);
        $this->checkVolumes($ctx);
    }

    private function products(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'EnologicalProduct') as $r) {
            $ctx->report->read('enological_products');
            $this->put($ctx, 'EnologicalProduct', (string) $r->id, 'enological_products', [
                'name' => trim((string) $r->name),
                'category' => trim((string) $r->category),
                'unit' => trim((string) $r->unit),
                'current_stock' => Normalize::dec($r->currentStock, 3) ?? '0.000',
                'min_stock' => Normalize::dec($r->minStock, 3),
                'cost_per_unit' => Normalize::unitPriceOrNull($r->costPerUnit),
                'manufacturer' => Normalize::str($r->manufacturer),
                'packaging_size' => Normalize::str($r->packagingSize),
                'so2_uplift_per_unit' => Normalize::dec($r->so2UpliftPerUnit, 4),
                'supplier_id' => $r->supplierId === null ? null : $ctx->ids->get('Supplier', (string) $r->supplierId),
                'is_active' => Normalize::bool($r->isActive),
                'notes' => Normalize::str($r->notes),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->updatedAt),
            ]);
            $ctx->report->written('enological_products');
        }
    }

    private function templates(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'FermentationTemplate') as $r) {
            $ctx->report->read('fermentation_templates');

            $stages = Normalize::str($r->stages);
            if ($stages !== null && json_decode($stages) === null) {
                $ctx->report->warn('cellar', "fermentation template {$r->name}: stages is not valid JSON; dropped");
                $stages = null;
            }

            $this->put($ctx, 'FermentationTemplate', (string) $r->id, 'fermentation_templates', [
                'name' => trim((string) $r->name),
                'wine_type' => Normalize::str($r->wineType),
                'yeast_strain' => $this->fit($ctx, 'cellar', "template {$r->id} yeast", Normalize::str($r->yeastStrain)),
                'target_temp_min' => Normalize::dec($r->targetTempMin, 2),
                'target_temp_max' => Normalize::dec($r->targetTempMax, 2),
                'punchdown_schedule' => $this->fit($ctx, 'cellar', "template {$r->id} punchdown", Normalize::str($r->punchdownSchedule)),
                'maceration' => $this->fit($ctx, 'cellar', "template {$r->id} maceration", Normalize::str($r->maceration)),
                'nutrients' => $this->fit($ctx, 'cellar', "template {$r->id} nutrients", Normalize::str($r->nutrients)),
                'mlf' => Normalize::bool($r->mlf),
                'description' => Normalize::str($r->description),
                'estimated_duration' => $r->estimatedDuration === null ? null : (int) $r->estimatedDuration,
                'stages' => $stages,
                'is_active' => Normalize::bool($r->isActive),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->updatedAt),
            ]);
            $ctx->report->written('fermentation_templates');
        }
    }

    private function vessels(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'Vessel') as $r) {
            $ctx->report->read('vessels');

            $type = VesselType::tryFrom((string) $r->type);
            $status = VesselStatus::tryFrom((string) $r->status);
            if ($type === null || $status === null) {
                $ctx->report->skipped('vessels', "vessel {$r->name}: unknown ".($type === null ? "type '{$r->type}'" : "status '{$r->status}'"));

                continue;
            }

            // Names are not unique in the legacy data (two "ST01"); rows are keyed by id.
            $this->put($ctx, 'Vessel', (string) $r->id, 'vessels', [
                'name' => trim((string) $r->name),
                'type' => $type->value,
                'material' => Normalize::str($r->material),
                'capacity_liters' => Normalize::dec($r->capacityLiters, 3),
                'current_volume' => Normalize::dec($r->currentVolume, 3),
                'location' => $this->fit($ctx, 'cellar', "vessel {$r->name} location", Normalize::str($r->location)),
                'status' => $status->value,
                'is_active' => Normalize::bool($r->isActive),
                'is_faulty' => Normalize::bool($r->isFaulty),
                'fault_note' => $this->fit($ctx, 'cellar', "vessel {$r->name} fault note", Normalize::str($r->faultNote)),
                'room' => $this->fit($ctx, 'cellar', "vessel {$r->name} room", Normalize::str($r->room) ?? 'Main Cellar'),
                'position_x' => $r->positionX,
                'position_y' => $r->positionY,
                'map_width' => $r->mapWidth,
                'map_height' => $r->mapHeight,
                'rotation' => $r->rotation,
                'notes' => Normalize::str($r->notes),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->updatedAt),
            ]);
            $ctx->report->written('vessels');
        }
    }

    private function lots(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'WineLot') as $r) {
            $ctx->report->read('wine_lots');

            $status = WineLotStatus::tryFrom((string) $r->status);
            if ($status === null) {
                $ctx->report->skipped('wine_lots', "lot {$r->lotNumber}: unknown status '{$r->status}'");

                continue;
            }

            $type = WineType::tryFrom((string) $r->wineType);
            if ($type === null && Normalize::str($r->wineType) !== null) {
                $ctx->report->warn('cellar', "lot {$r->lotNumber}: unknown wine type '{$r->wineType}'; left blank");
            }

            $this->put($ctx, 'WineLot', (string) $r->id, 'wine_lots', [
                'lot_number' => trim((string) $r->lotNumber),
                'name' => trim((string) $r->name),
                'grape_variety' => trim((string) $r->grapeVariety),
                'vintage' => trim((string) $r->vintage),
                'vineyard' => $this->fit($ctx, 'cellar', "lot {$r->lotNumber} vineyard", Normalize::str($r->vineyard)),
                'wine_type' => $type?->value,
                'initial_volume' => Normalize::dec($r->initialVolume, 3),
                'current_volume' => Normalize::dec($r->currentVolume, 3),
                'status' => $status->value,
                'grape_cost' => Normalize::minorOrNull($r->grapeCost),
                'grape_price_per_kg' => Normalize::minorOrNull($r->grapePricePerKg),
                'harvest_weight_kg' => Normalize::dec($r->harvestWeightKg, 3),
                'fermentation_template_id' => $r->fermentationTemplateId === null ? null : $ctx->ids->get('FermentationTemplate', (string) $r->fermentationTemplateId),
                'notes' => Normalize::str($r->notes),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->updatedAt),
            ]);
            $ctx->report->written('wine_lots');
        }
    }

    private function lotGrapes(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'WineLotGrape') as $r) {
            $ctx->report->read('wine_lot_grapes');
            $lot = $ctx->ids->get('WineLot', (string) $r->wineLotId);
            if ($lot === null) {
                $ctx->report->skipped('wine_lot_grapes', "lot grape {$r->id}: lot not migrated");

                continue;
            }
            if ($r->harvestEntryId !== null) {
                $ctx->report->warn('cellar', "lot grape {$r->id}: harvest entries are not migrated; link dropped");
            }
            $this->put($ctx, 'WineLotGrape', (string) $r->id, 'wine_lot_grapes', [
                'wine_lot_id' => $lot,
                'grape_variety' => trim((string) $r->grapeVariety),
                'percentage' => Normalize::dec($r->percentage, 2),
                'price_per_kg' => Normalize::minorOrNull($r->pricePerKg),
                'weight_kg' => Normalize::dec($r->weightKg, 3),
                'harvest_entry_id' => null,
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->createdAt),
            ]);
            $ctx->report->written('wine_lot_grapes');
        }
    }

    private function vesselLots(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'VesselLot') as $r) {
            $ctx->report->read('vessel_lots');
            $vessel = $ctx->ids->get('Vessel', (string) $r->vesselId);
            $lot = $ctx->ids->get('WineLot', (string) $r->wineLotId);
            if ($vessel === null || $lot === null) {
                $ctx->report->skipped('vessel_lots', "vessel lot {$r->id}: vessel/lot not migrated");

                continue;
            }
            $this->put($ctx, 'VesselLot', (string) $r->id, 'vessel_lots', [
                'vessel_id' => $vessel,
                'wine_lot_id' => $lot,
                'volume' => Normalize::dec($r->volume, 3),
                'added_at' => Normalize::date($r->addedAt),
                'created_at' => Normalize::date($r->addedAt),
                'updated_at' => Normalize::date($r->addedAt),
            ]);
            $ctx->report->written('vessel_lots');
        }
    }

    private function analyses(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'CellarAnalysis') as $r) {
            $ctx->report->read('cellar_analyses');
            $lot = $ctx->ids->get('WineLot', (string) $r->wineLotId);
            if ($lot === null) {
                $ctx->report->skipped('cellar_analyses', "analysis {$r->id}: lot not migrated");

                continue;
            }
            $this->put($ctx, 'CellarAnalysis', (string) $r->id, 'cellar_analyses', [
                'wine_lot_id' => $lot,
                'vessel_id' => $r->vesselId === null ? null : $ctx->ids->get('Vessel', (string) $r->vesselId),
                'created_by_id' => $ctx->userId((string) $r->createdById, 'cellar'),
                'date' => Normalize::date($r->date),
                'ph' => Normalize::dec($r->pH, 2),
                'total_acidity' => Normalize::dec($r->totalAcidity, 2),
                'volatile_acidity' => Normalize::dec($r->volatileAcidity, 3),
                'alcohol' => Normalize::dec($r->alcohol, 2),
                'residual_sugar' => Normalize::dec($r->residualSugar, 2),
                'free_so2' => Normalize::dec($r->freeSO2, 2),
                'total_so2' => Normalize::dec($r->totalSO2, 2),
                'brix' => Normalize::dec($r->brix, 2),
                'temperature' => Normalize::dec($r->temperature, 2),
                'density' => Normalize::dec($r->density, 4),
                'malic' => Normalize::dec($r->malic, 2),
                'lactic' => Normalize::dec($r->lactic, 2),
                'tpi' => Normalize::dec($r->tpi, 2),
                'glucose_fructose' => Normalize::dec($r->glucoseFructose, 2),
                'note' => Normalize::str($r->note),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->createdAt),
            ]);
            $ctx->report->written('cellar_analyses');
        }
    }

    private function protocolAdditives(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'ProtocolAdditive') as $r) {
            $ctx->report->read('protocol_additives');
            $template = $ctx->ids->get('FermentationTemplate', (string) $r->templateId);
            $basis = DoseBasis::tryFrom((string) $r->basis);
            if ($template === null || $basis === null) {
                $ctx->report->skipped('protocol_additives', "protocol additive {$r->id}: template not migrated or unknown basis '{$r->basis}'");

                continue;
            }
            $this->put($ctx, 'ProtocolAdditive', (string) $r->id, 'protocol_additives', [
                'fermentation_template_id' => $template,
                'enological_product_id' => $r->enologicalProductId === null ? null : $ctx->ids->get('EnologicalProduct', (string) $r->enologicalProductId),
                'name' => $this->fit($ctx, 'cellar', "protocol additive {$r->id} name", trim((string) $r->name)),
                'dose' => Normalize::dec($r->dose, 4),
                'basis' => $basis->value,
                'stage' => Normalize::str($r->stage),
                'notes' => Normalize::str($r->notes),
                'sort_order' => (int) $r->sortOrder,
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->createdAt),
            ]);
            $ctx->report->written('protocol_additives');
        }
    }

    /**
     * The prototype had no vessel column on additions; the vessel was written
     * into the note ("… → F11" from the lot form, "Vessel F11 · …" from planned
     * work). Recover it when the name matches exactly one migrated vessel.
     */
    private function vesselFromNote(ImportContext $ctx, ?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        if ($this->vesselsByName === null) {
            $this->vesselsByName = [];
            $counts = [];
            foreach ($this->rows($ctx, 'Vessel') as $v) {
                $key = mb_strtolower(trim((string) $v->name));
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                $this->vesselsByName[$key] = (string) $v->id;
            }
            // Ambiguous names (two vessels share one) are not guessed.
            foreach ($counts as $key => $n) {
                if ($n > 1) {
                    unset($this->vesselsByName[$key]);
                }
            }
        }

        if (preg_match('/(?:→\s*|^Vessel\s+)([^\s·,;()]+)/u', $note, $m) !== 1) {
            return null;
        }

        $legacy = $this->vesselsByName[mb_strtolower($m[1])] ?? null;

        return $legacy === null ? null : $ctx->ids->get('Vessel', $legacy);
    }

    private function additions(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'CellarAddition') as $r) {
            $ctx->report->read('cellar_additions');
            $lot = $ctx->ids->get('WineLot', (string) $r->wineLotId);
            if ($lot === null) {
                $ctx->report->skipped('cellar_additions', "addition {$r->id}: lot not migrated");

                continue;
            }
            $this->put($ctx, 'CellarAddition', (string) $r->id, 'cellar_additions', [
                'wine_lot_id' => $lot,
                'vessel_id' => $this->vesselFromNote($ctx, Normalize::str($r->note)),
                'enological_product_id' => $r->enologicalProductId === null ? null : $ctx->ids->get('EnologicalProduct', (string) $r->enologicalProductId),
                'created_by_id' => $ctx->userId((string) $r->createdById, 'cellar'),
                'name' => $this->fit($ctx, 'cellar', "addition {$r->id} name", trim((string) $r->name)),
                'category' => Normalize::str($r->category),
                'quantity' => Normalize::dec($r->quantity, 3),
                'unit' => trim((string) $r->unit),
                'cost_per_unit' => Normalize::unitPriceOrNull($r->costPerUnit),
                'total_cost' => Normalize::minorOrNull($r->totalCost),
                'note' => Normalize::str($r->note),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->createdAt),
            ]);
            $ctx->report->written('cellar_additions');
        }
    }

    private function processes(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'CellarProcess') as $r) {
            $ctx->report->read('cellar_processes');
            $lot = $ctx->ids->get('WineLot', (string) $r->wineLotId);
            if ($lot === null) {
                $ctx->report->skipped('cellar_processes', "process {$r->id}: lot not migrated");

                continue;
            }
            $this->put($ctx, 'CellarProcess', (string) $r->id, 'cellar_processes', [
                'wine_lot_id' => $lot,
                'vessel_id' => $r->vesselId === null ? null : $ctx->ids->get('Vessel', (string) $r->vesselId),
                'created_by_id' => $ctx->userId((string) $r->createdById, 'cellar'),
                'date' => Normalize::date($r->date),
                'kind' => $this->fit($ctx, 'cellar', "process {$r->id} kind", trim((string) $r->kind)),
                'volume' => Normalize::dec($r->volume, 3),
                'note' => Normalize::str($r->note),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->createdAt),
            ]);
            $ctx->report->written('cellar_processes');
        }
    }

    private function transfers(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'CellarTransfer') as $r) {
            $ctx->report->read('cellar_transfers');
            $from = $ctx->ids->get('WineLot', (string) $r->fromLotId);
            $to = $ctx->ids->get('WineLot', (string) $r->toLotId);
            if ($from === null || $to === null) {
                $ctx->report->skipped('cellar_transfers', "transfer {$r->id}: lot not migrated");

                continue;
            }
            $type = $this->transferType($ctx, (string) $r->id, (string) $r->type, $from === $to);
            $this->put($ctx, 'CellarTransfer', (string) $r->id, 'cellar_transfers', [
                'from_lot_id' => $from,
                'to_lot_id' => $to,
                'from_vessel_id' => $r->fromVesselId === null ? null : $ctx->ids->get('Vessel', (string) $r->fromVesselId),
                'to_vessel_id' => $r->toVesselId === null ? null : $ctx->ids->get('Vessel', (string) $r->toVesselId),
                'created_by_id' => $ctx->userId((string) $r->createdById, 'cellar'),
                'type' => $type->value,
                'volume_liters' => Normalize::dec($r->volumeLiters, 3),
                'note' => Normalize::str($r->note),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->createdAt),
            ]);
            $ctx->report->written('cellar_transfers');
        }
    }

    /**
     * The prototype stored the transfer type as free text, and its seed and voice assistant wrote
     * labels such as "Pretok" (racking), "Sljubljivanje" (blending) or "Topping-up". What a
     * transfer *is* follows from its lots — within one lot it's a rack, into another lot a blend —
     * so an unrecognised label takes its type from the lot pair; only a split is read from the label.
     */
    private function transferType(ImportContext $ctx, string $id, string $raw, bool $sameLot): CellarTransferType
    {
        $type = CellarTransferType::tryFrom(strtoupper(trim($raw)));
        if ($type !== null) {
            return $type;
        }
        $type = in_array(mb_strtolower(trim($raw)), ['split', 'razdvajanje', 'podjela'], true)
            ? CellarTransferType::Split
            : ($sameLot ? CellarTransferType::Rack : CellarTransferType::Blend);
        $ctx->report->warn('cellar', "transfer {$id}: type '{$raw}' imported as {$type->value}");

        return $type;
    }

    /**
     * Bottling runs. The prototype did not record which vessels a run drew from, so no
     * bottling_vessels rows are written; reversing an imported run returns the wine to the lot only.
     */
    private function bottlings(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'Bottling') as $r) {
            $ctx->report->read('bottlings');
            $lot = $ctx->ids->get('WineLot', (string) $r->wineLotId);
            if ($lot === null) {
                $ctx->report->skipped('bottlings', "bottling {$r->id}: lot not migrated");

                continue;
            }
            $this->put($ctx, 'Bottling', (string) $r->id, 'bottlings', [
                'wine_lot_id' => $lot,
                'inventory_item_id' => $r->inventoryItemId === null ? null : $ctx->ids->get('InventoryItem', (string) $r->inventoryItemId),
                'created_by_id' => $ctx->userId((string) $r->createdById, 'cellar'),
                'bottle_count' => (int) $r->bottleCount,
                'bottle_volume_ml' => (int) ($r->bottleVolumeMl ?? 750),
                'volume_used' => Normalize::dec($r->volumeUsed, 3),
                'date' => Normalize::date($r->date),
                'note' => Normalize::str($r->note),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->createdAt),
            ]);
            $ctx->report->written('bottlings');
        }
    }

    private function tastings(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'TastingReport') as $r) {
            $ctx->report->read('tasting_reports');
            $this->put($ctx, 'TastingReport', (string) $r->id, 'tasting_reports', [
                'created_by_id' => $ctx->userId((string) $r->createdById, 'cellar'),
                'title' => $this->fit($ctx, 'cellar', "tasting report {$r->id} title", Normalize::str($r->title)),
                'date' => Normalize::date($r->date),
                'note' => Normalize::str($r->note),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->createdAt),
            ]);
            $ctx->report->written('tasting_reports');
        }

        foreach ($this->rows($ctx, 'CellarTastingNote') as $r) {
            $ctx->report->read('cellar_tasting_notes');
            $lot = $ctx->ids->get('WineLot', (string) $r->wineLotId);
            if ($lot === null) {
                $ctx->report->skipped('cellar_tasting_notes', "tasting note {$r->id}: lot not migrated");

                continue;
            }
            $this->put($ctx, 'CellarTastingNote', (string) $r->id, 'cellar_tasting_notes', [
                'wine_lot_id' => $lot,
                'vessel_id' => $r->vesselId === null ? null : $ctx->ids->get('Vessel', (string) $r->vesselId),
                'tasting_report_id' => $r->tastingReportId === null ? null : $ctx->ids->get('TastingReport', (string) $r->tastingReportId),
                'created_by_id' => $ctx->userId((string) $r->createdById, 'cellar'),
                'date' => Normalize::date($r->date),
                'appearance' => $this->fit($ctx, 'cellar', "tasting note {$r->id} appearance", Normalize::str($r->appearance)),
                'nose' => $this->fit($ctx, 'cellar', "tasting note {$r->id} nose", Normalize::str($r->nose)),
                'palate' => $this->fit($ctx, 'cellar', "tasting note {$r->id} palate", Normalize::str($r->palate)),
                'overall' => $this->fit($ctx, 'cellar', "tasting note {$r->id} overall", Normalize::str($r->overall)),
                'score' => $r->score === null ? null : (int) $r->score,
                'note' => Normalize::str($r->note),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->createdAt),
            ]);
            $ctx->report->written('cellar_tasting_notes');
        }
    }

    /** Informational: where imported volumes disagree with each other. */
    private function checkVolumes(ImportContext $ctx): void
    {
        $tenantId = (string) $ctx->tenant->getKey();

        $over = DB::table('vessels')->where('tenant_id', $tenantId)->whereColumn('current_volume', '>', 'capacity_liters')->pluck('name');
        if ($over->isNotEmpty()) {
            $ctx->report->warn('cellar', 'vessels over capacity (legacy data kept): '.$over->implode(', '));
        }

        $drift = Sql::countGroups(DB::table('vessels as v')
            ->leftJoin('vessel_lots as vl', 'vl.vessel_id', '=', 'v.id')
            ->where('v.tenant_id', $tenantId)
            ->groupBy('v.id', 'v.current_volume')
            ->havingRaw('abs(v.current_volume - coalesce(sum(vl.volume), 0)) > 0.001')
            ->select('v.id'));
        if ($drift > 0) {
            $ctx->report->warn('cellar', "{$drift} vessels have current_volume ≠ sum of their vessel_lots (legacy figures kept)");
        }

        $lotDrift = Sql::countGroups(DB::table('wine_lots as l')
            ->leftJoin('vessel_lots as vl', 'vl.wine_lot_id', '=', 'l.id')
            ->where('l.tenant_id', $tenantId)
            ->groupBy('l.id', 'l.current_volume')
            ->havingRaw('abs(l.current_volume - coalesce(sum(vl.volume), 0)) > 0.001')
            ->select('l.id'));
        if ($lotDrift > 0) {
            $ctx->report->warn('cellar', "{$lotDrift} wine lots have current_volume ≠ sum of their vessel_lots (legacy figures kept; bottled/emptied lots are expected here)");
        }
    }
}
