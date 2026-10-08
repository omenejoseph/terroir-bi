<?php

declare(strict_types=1);

namespace App\Services\LegacyImport\Steps;

use App\Enums\GrapeContractStatus;
use App\Enums\IntakeBookingStatus;
use App\Enums\ParcelOwnership;
use App\Enums\PhenologyStage;
use App\Enums\VineyardApplicationType;
use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\Support\Normalize;

/**
 * Parcels and their logs, grape contracts and intake bookings. Harvest plans, entries,
 * maturity samples and press fractions are empty in the legacy data.
 */
class VineyardsStep extends AbstractStep
{
    public function name(): string
    {
        return 'vineyards';
    }

    public function dependsOn(): array
    {
        return ['users', 'suppliers'];
    }

    public function run(ImportContext $ctx): void
    {
        $this->parcels($ctx);
        $this->phenology($ctx);
        $this->cropEstimates($ctx);
        $this->applications($ctx);
        $this->contracts($ctx);
        $this->intake($ctx);
    }

    private function parcels(ImportContext $ctx): void
    {
        $dropped = 0;

        foreach ($this->rows($ctx, 'VineyardParcel') as $r) {
            $ctx->report->read('vineyard_parcels');

            $ownership = ParcelOwnership::tryFrom((string) $r->ownership);
            if ($ownership === null) {
                $ctx->report->skipped('vineyard_parcels', "parcel {$r->name}: unknown ownership '{$r->ownership}'");

                continue;
            }

            $polygon = Normalize::str($r->geoPolygon);
            if ($polygon !== null && json_decode($polygon) === null) {
                $ctx->report->warn('vineyards', "parcel {$r->name}: geo polygon is not valid JSON; dropped");
                $polygon = null;
            }
            $dropped += ($r->weatherStationId !== null || $r->lastHarvestDate !== null) ? 1 : 0;

            $this->put($ctx, 'VineyardParcel', (string) $r->id, 'vineyard_parcels', [
                'name' => trim((string) $r->name),
                'grape_variety' => trim((string) $r->grapeVariety),
                'area_hectares' => Normalize::dec($r->areaHectares, 4),
                'location' => $this->fit($ctx, 'vineyards', "parcel {$r->name} location", Normalize::str($r->location)),
                'elevation' => $r->elevation,
                'soil_type' => $this->fit($ctx, 'vineyards', "parcel {$r->name} soil", Normalize::str($r->soilType)),
                'planting_year' => $r->plantingYear,
                'row_spacing' => Normalize::dec($r->rowSpacing, 2),
                'vine_count' => $r->vineCount,
                'rootstock' => $this->fit($ctx, 'vineyards', "parcel {$r->name} rootstock", Normalize::str($r->rootstock)),
                'training' => $this->fit($ctx, 'vineyards', "parcel {$r->name} training", Normalize::str($r->training)),
                'orientation' => $this->fit($ctx, 'vineyards', "parcel {$r->name} orientation", Normalize::str($r->orientation)),
                'slope' => Normalize::dec($r->slope, 2),
                'latitude' => Normalize::dec($r->latitude, 6),
                'longitude' => Normalize::dec($r->longitude, 6),
                'geo_polygon' => $polygon,
                'geo_area_calculated' => Normalize::dec($r->geoAreaCalculated, 4),
                'ownership' => $ownership->value,
                'cooperant_supplier_id' => $r->cooperantSupplierId === null ? null : $ctx->ids->get('Supplier', (string) $r->cooperantSupplierId),
                'is_active' => Normalize::bool($r->isActive),
                'notes' => Normalize::str($r->notes),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->updatedAt),
            ]);
            $ctx->report->written('vineyard_parcels');
        }

        if ($dropped > 0) {
            $ctx->report->warn('vineyards', "{$dropped} parcels had a weather station id / last harvest date, which have no column in the rebuild");
        }
    }

    private function phenology(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'PhenologyLog') as $r) {
            $ctx->report->read('phenology_logs');
            $parcel = $ctx->ids->get('VineyardParcel', (string) $r->parcelId);
            $stage = PhenologyStage::tryFrom((string) $r->stage);
            if ($parcel === null || $stage === null) {
                $ctx->report->skipped('phenology_logs', "phenology log {$r->id}: ".($stage === null ? "unknown stage '{$r->stage}'" : 'parcel not migrated'));

                continue;
            }
            $this->put($ctx, 'PhenologyLog', (string) $r->id, 'phenology_logs', [
                'parcel_id' => $parcel,
                'created_by_id' => $ctx->fallbackUser()->getKey(),
                'date' => Normalize::date($r->date),
                'stage' => $stage->value,
                'progress_percent' => Normalize::dec($r->progress, 2),
                // Still a Vercel Blob URL; the media-copy step is where files move.
                'photo_url' => $this->fit($ctx, 'vineyards', "phenology log {$r->id} photo url", Normalize::str($r->photoUrl)),
                'note' => Normalize::str($r->notes),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->createdAt),
            ]);
            $ctx->report->written('phenology_logs');
        }
    }

    private function cropEstimates(ImportContext $ctx): void
    {
        $zeroFilled = 0;

        foreach ($this->rows($ctx, 'CropEstimate') as $r) {
            $ctx->report->read('crop_estimates');
            $parcel = $ctx->ids->get('VineyardParcel', (string) $r->parcelId);
            if ($parcel === null) {
                $ctx->report->skipped('crop_estimates', "crop estimate {$r->id}: parcel not migrated");

                continue;
            }
            // The rebuild requires the sampling inputs; legacy rows often only had the final yield.
            $zeroFilled += ($r->clusterCount === null || $r->avgClusterWeight === null || $r->sampleVineCount === null) ? 1 : 0;

            $this->put($ctx, 'CropEstimate', (string) $r->id, 'crop_estimates', [
                'parcel_id' => $parcel,
                'created_by_id' => $ctx->fallbackUser()->getKey(),
                'date' => Normalize::date($r->date),
                'cluster_count' => (int) ($r->clusterCount ?? 0),
                'avg_cluster_weight' => Normalize::dec($r->avgClusterWeight ?? 0, 2),
                'sample_vine_count' => (int) ($r->sampleVineCount ?? 0),
                'estimated_yield_kg' => Normalize::dec($r->estimatedYieldKg ?? 0, 3),
                'note' => Normalize::str($r->notes),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->createdAt),
            ]);
            $ctx->report->written('crop_estimates');
        }

        if ($zeroFilled > 0) {
            $ctx->report->warn('vineyards', "{$zeroFilled} crop estimates had no cluster/sample inputs (only the yield); those inputs are stored as 0");
        }
    }

    private function applications(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'VineyardApplication') as $r) {
            $ctx->report->read('vineyard_applications');
            $parcel = $ctx->ids->get('VineyardParcel', (string) $r->parcelId);
            $type = VineyardApplicationType::tryFrom((string) $r->type);
            if ($parcel === null || $type === null) {
                $ctx->report->skipped('vineyard_applications', "application {$r->id}: ".($type === null ? "unknown type '{$r->type}'" : 'parcel not migrated'));

                continue;
            }

            // Legacy kept the number and unit apart; the rebuild has one free-text dosage.
            $dosage = null;
            if ($r->dosage !== null) {
                $dosage = rtrim(rtrim(number_format((float) $r->dosage, 3, '.', ''), '0'), '.').' '.trim((string) $r->dosageUnit);
                $dosage = trim($dosage);
            }

            $this->put($ctx, 'VineyardApplication', (string) $r->id, 'vineyard_applications', [
                'parcel_id' => $parcel,
                'created_by_id' => $ctx->fallbackUser()->getKey(),
                'date' => Normalize::date($r->date),
                'type' => $type->value,
                'product' => $this->fit($ctx, 'vineyards', "application {$r->id} product", Normalize::str($r->product)),
                'dosage' => $this->fit($ctx, 'vineyards', "application {$r->id} dosage", $dosage),
                'phi_days' => $r->phiDays,
                'phi_end_date' => Normalize::date($r->phiEndDate)?->toDateString(),
                'weather' => $this->fit($ctx, 'vineyards', "application {$r->id} weather", Normalize::str($r->weather)),
                'note' => Normalize::str($r->notes),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->createdAt),
            ]);
            $ctx->report->written('vineyard_applications');
        }
    }

    private function contracts(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'GrapeContract') as $r) {
            $ctx->report->read('grape_contracts');
            $supplier = $ctx->ids->get('Supplier', (string) $r->supplierId);
            $status = GrapeContractStatus::tryFrom((string) $r->status);
            if ($supplier === null || $status === null) {
                $ctx->report->skipped('grape_contracts', "contract {$r->id}: ".($status === null ? "unknown status '{$r->status}'" : 'supplier not migrated'));

                continue;
            }
            $this->put($ctx, 'GrapeContract', (string) $r->id, 'grape_contracts', [
                'supplier_id' => $supplier,
                'parcel_id' => $r->parcelId === null ? null : $ctx->ids->get('VineyardParcel', (string) $r->parcelId),
                'season' => trim((string) $r->season),
                'status' => $status->value,
                'grape_variety' => trim((string) $r->grapeVariety),
                'estimated_kg' => Normalize::dec($r->estimatedKg, 3),
                'delivered_kg' => Normalize::dec($r->deliveredKg, 3),
                'price_per_kg' => Normalize::minor($r->pricePerKg),
                'min_brix' => Normalize::dec($r->minBrix, 2),
                'max_ph' => Normalize::dec($r->maxPH, 2),
                'delivery_window' => $this->fit($ctx, 'vineyards', "contract {$r->id} delivery window", Normalize::str($r->deliveryWindow)),
                'payment_terms' => $this->fit($ctx, 'vineyards', "contract {$r->id} payment terms", Normalize::str($r->paymentTerms)),
                'notes' => Normalize::str($r->notes),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->updatedAt),
            ]);
            $ctx->report->written('grape_contracts');
        }
    }

    private function intake(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'IntakeBooking') as $r) {
            $ctx->report->read('intake_bookings');

            // Legacy used COMPLETED where the rebuild says PROCESSED.
            $raw = strtoupper((string) $r->status) === 'COMPLETED' ? 'PROCESSED' : (string) $r->status;
            $status = IntakeBookingStatus::tryFrom($raw);
            if ($status === null) {
                $ctx->report->skipped('intake_bookings', "intake booking {$r->id}: unknown status '{$r->status}'");

                continue;
            }
            $this->put($ctx, 'IntakeBooking', (string) $r->id, 'intake_bookings', [
                'harvest_plan_id' => null, // harvest plans are empty in the legacy data
                'supplier_id' => $r->supplierId === null ? null : $ctx->ids->get('Supplier', (string) $r->supplierId),
                'date' => Normalize::date($r->date),
                'time_slot' => $this->fit($ctx, 'vineyards', "intake {$r->id} time slot", Normalize::str($r->timeSlot)),
                'grape_variety' => $this->fit($ctx, 'vineyards', "intake {$r->id} variety", Normalize::str($r->grapeVariety)),
                'estimated_kg' => Normalize::dec($r->estimatedKg, 3),
                'grower_name' => $this->fit($ctx, 'vineyards', "intake {$r->id} grower", Normalize::str($r->growerName)),
                'status' => $status->value,
                'notes' => Normalize::str($r->notes),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->updatedAt),
            ]);
            $ctx->report->written('intake_bookings');
        }
    }
}
