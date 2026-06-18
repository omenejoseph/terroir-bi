<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vineyards\StoreAgronomyRequest;
use App\Http\Requests\Vineyards\StoreParcelRequest;
use App\Http\Requests\Vineyards\UpdateParcelRequest;
use App\Models\CropEstimate;
use App\Models\MaturitySample;
use App\Models\PhenologyLog;
use App\Models\User;
use App\Models\VineyardApplication;
use App\Models\VineyardParcel;
use App\Services\Vineyards\CropYieldEstimator;
use App\Services\Vineyards\PhiCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VineyardParcelController extends Controller
{
    public function __construct(
        private readonly CropYieldEstimator $yield,
        private readonly PhiCalculator $phi,
    ) {}

    public function index(): JsonResponse
    {
        $parcels = VineyardParcel::query()->where('is_active', true)->orderBy('name')->get();

        return response()->json(['data' => $parcels->map(fn (VineyardParcel $p) => $this->present($p))->all()]);
    }

    public function show(VineyardParcel $vineyardParcel): JsonResponse
    {
        return response()->json(['data' => $this->present($vineyardParcel, true)]);
    }

    public function store(StoreParcelRequest $request): JsonResponse
    {
        $parcel = VineyardParcel::create($request->validated());

        return response()->json(['data' => $this->present($parcel)], 201);
    }

    public function update(UpdateParcelRequest $request, VineyardParcel $vineyardParcel): JsonResponse
    {
        $vineyardParcel->update($request->validated());

        return response()->json(['data' => $this->present($vineyardParcel)]);
    }

    public function destroy(VineyardParcel $vineyardParcel): JsonResponse
    {
        // Deactivate rather than hard-delete (history references it).
        $vineyardParcel->update(['is_active' => false]);

        return response()->json(status: 204);
    }

    public function addMaturitySample(StoreAgronomyRequest $request, VineyardParcel $vineyardParcel): JsonResponse
    {
        $vineyardParcel->maturitySamples()->create([
            'created_by_id' => $this->userId($request),
            'date' => $request->validated('date') ?? now(),
            'brix' => $request->validated('brix'),
            'ph' => $request->validated('ph'),
            'total_acidity' => $request->validated('total_acidity'),
            'temperature' => $request->validated('temperature'),
            'note' => $request->validated('note'),
        ]);

        return response()->json(['data' => $this->present($vineyardParcel->refresh(), true)], 201);
    }

    public function addPhenologyLog(StoreAgronomyRequest $request, VineyardParcel $vineyardParcel): JsonResponse
    {
        $vineyardParcel->phenologyLogs()->create([
            'created_by_id' => $this->userId($request),
            'date' => $request->validated('date') ?? now(),
            'stage' => $request->validated('stage'),
            'progress_percent' => $request->validated('progress_percent'),
            'photo_url' => $request->validated('photo_url'),
            'note' => $request->validated('note'),
        ]);

        return response()->json(['data' => $this->present($vineyardParcel->refresh(), true)], 201);
    }

    public function addCropEstimate(StoreAgronomyRequest $request, VineyardParcel $vineyardParcel): JsonResponse
    {
        $clusters = (int) $request->validated('cluster_count', 0);
        $weight = (float) $request->validated('avg_cluster_weight', 0);
        $sample = (int) $request->validated('sample_vine_count', 1);
        $estimate = $this->yield->estimate($clusters, $weight, $sample, $vineyardParcel->vine_count);

        $vineyardParcel->cropEstimates()->create([
            'created_by_id' => $this->userId($request),
            'date' => $request->validated('date') ?? now(),
            'cluster_count' => $clusters,
            'avg_cluster_weight' => $weight,
            'sample_vine_count' => $sample,
            'estimated_yield_kg' => $estimate,
            'note' => $request->validated('note'),
        ]);

        return response()->json(['data' => $this->present($vineyardParcel->refresh(), true)], 201);
    }

    public function addApplication(StoreAgronomyRequest $request, VineyardParcel $vineyardParcel): JsonResponse
    {
        $date = $request->validated('date') ?? now()->toDateTimeString();
        $phiDays = $request->validated('phi_days');

        $vineyardParcel->applications()->create([
            'created_by_id' => $this->userId($request),
            'date' => $date,
            'type' => $request->validated('type'),
            'product' => $request->validated('product'),
            'dosage' => $request->validated('dosage'),
            'phi_days' => $phiDays,
            'phi_end_date' => $this->phi->endDate((string) $date, $phiDays !== null ? (int) $phiDays : null),
            'weather' => $request->validated('weather'),
            'note' => $request->validated('note'),
        ]);

        return response()->json(['data' => $this->present($vineyardParcel->refresh(), true)], 201);
    }

    public function deleteMaturitySample(VineyardParcel $vineyardParcel, MaturitySample $sample): JsonResponse
    {
        abort_unless($sample->parcel_id === $vineyardParcel->getKey(), 404);
        $sample->delete();

        return response()->json(['data' => $this->present($vineyardParcel->refresh(), true)]);
    }

    public function deletePhenologyLog(VineyardParcel $vineyardParcel, PhenologyLog $log): JsonResponse
    {
        abort_unless($log->parcel_id === $vineyardParcel->getKey(), 404);
        $log->delete();

        return response()->json(['data' => $this->present($vineyardParcel->refresh(), true)]);
    }

    public function deleteCropEstimate(VineyardParcel $vineyardParcel, CropEstimate $estimate): JsonResponse
    {
        abort_unless($estimate->parcel_id === $vineyardParcel->getKey(), 404);
        $estimate->delete();

        return response()->json(['data' => $this->present($vineyardParcel->refresh(), true)]);
    }

    public function deleteApplication(VineyardParcel $vineyardParcel, VineyardApplication $application): JsonResponse
    {
        abort_unless($application->parcel_id === $vineyardParcel->getKey(), 404);
        $application->delete();

        return response()->json(['data' => $this->present($vineyardParcel->refresh(), true)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(VineyardParcel $p, bool $detail = false): array
    {
        $payload = [
            'id' => $p->getKey(),
            'name' => $p->name,
            'grape_variety' => $p->grape_variety,
            'area_hectares' => $p->area_hectares !== null ? (string) $p->area_hectares : null,
            'location' => $p->location,
            'elevation' => $p->elevation,
            'soil_type' => $p->soil_type,
            'planting_year' => $p->planting_year,
            'row_spacing' => $p->row_spacing !== null ? (string) $p->row_spacing : null,
            'vine_count' => $p->vine_count,
            'rootstock' => $p->rootstock,
            'training' => $p->training,
            'orientation' => $p->orientation,
            'slope' => $p->slope !== null ? (string) $p->slope : null,
            'latitude' => $p->latitude !== null ? (string) $p->latitude : null,
            'longitude' => $p->longitude !== null ? (string) $p->longitude : null,
            'geo_polygon' => $p->geo_polygon,
            'ownership' => $p->ownership->value,
            'cooperant_supplier_id' => $p->cooperant_supplier_id,
            'is_active' => $p->is_active,
            'notes' => $p->notes,
        ];

        if ($detail) {
            $p->loadMissing(['maturitySamples', 'phenologyLogs', 'cropEstimates', 'applications']);
            $payload['maturity_samples'] = $p->maturitySamples->sortByDesc('date')->values()->map(fn (MaturitySample $s) => [
                'id' => $s->getKey(), 'date' => $s->date->toIso8601String(),
                'brix' => $s->brix, 'ph' => $s->ph, 'total_acidity' => $s->total_acidity, 'temperature' => $s->temperature, 'note' => $s->note,
            ])->all();
            $payload['phenology_logs'] = $p->phenologyLogs->sortByDesc('date')->values()->map(fn (PhenologyLog $l) => [
                'id' => $l->getKey(), 'date' => $l->date->toIso8601String(), 'stage' => $l->stage->value, 'progress_percent' => $l->progress_percent, 'note' => $l->note,
            ])->all();
            $payload['crop_estimates'] = $p->cropEstimates->sortByDesc('date')->values()->map(fn (CropEstimate $c) => [
                'id' => $c->getKey(), 'date' => $c->date->toIso8601String(), 'cluster_count' => $c->cluster_count,
                'avg_cluster_weight' => $c->avg_cluster_weight, 'sample_vine_count' => $c->sample_vine_count, 'estimated_yield_kg' => $c->estimated_yield_kg, 'note' => $c->note,
            ])->all();
            $payload['applications'] = $p->applications->sortByDesc('date')->values()->map(fn (VineyardApplication $a) => [
                'id' => $a->getKey(), 'date' => $a->date->toIso8601String(), 'type' => $a->type->value, 'product' => $a->product,
                'dosage' => $a->dosage, 'phi_days' => $a->phi_days, 'phi_end_date' => $a->phi_end_date?->toDateString(), 'note' => $a->note,
            ])->all();
        }

        return $payload;
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
