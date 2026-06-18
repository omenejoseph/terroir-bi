<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vineyards\StoreIntakeBookingRequest;
use App\Models\IntakeBooking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntakeBookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $bookings = IntakeBooking::query()
            ->when($request->query('harvest_plan_id'), fn ($q, $id) => $q->where('harvest_plan_id', $id))
            ->orderBy('date')
            ->get();

        return response()->json(['data' => $bookings->map(fn (IntakeBooking $b) => $this->present($b))->all()]);
    }

    public function store(StoreIntakeBookingRequest $request): JsonResponse
    {
        $booking = IntakeBooking::create($request->validated());

        return response()->json(['data' => $this->present($booking)], 201);
    }

    public function update(StoreIntakeBookingRequest $request, IntakeBooking $intakeBooking): JsonResponse
    {
        $intakeBooking->update($request->validated());

        return response()->json(['data' => $this->present($intakeBooking)]);
    }

    public function destroy(IntakeBooking $intakeBooking): JsonResponse
    {
        $intakeBooking->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(IntakeBooking $b): array
    {
        return [
            'id' => $b->getKey(),
            'harvest_plan_id' => $b->harvest_plan_id,
            'supplier_id' => $b->supplier_id,
            'date' => $b->date->toIso8601String(),
            'time_slot' => $b->time_slot,
            'grape_variety' => $b->grape_variety,
            'estimated_kg' => $b->estimated_kg !== null ? (string) $b->estimated_kg : null,
            'grower_name' => $b->grower_name,
            'status' => $b->status->value,
            'notes' => $b->notes,
        ];
    }
}
