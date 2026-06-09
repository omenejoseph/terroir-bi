<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Finance\CreateInflowAction;
use App\DataTransferObjects\InflowData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\RecordOrderPaymentRequest;
use App\Models\Inflow;
use App\Models\Order;
use App\Models\User;
use App\Services\Finance\OrderPaymentSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payments against a specific order: the running summary (paid / balance /
 * status) plus the list of money-in records, and recording a new one.
 */
class OrderPaymentController extends Controller
{
    public function index(Order $order, OrderPaymentSummary $summary): JsonResponse
    {
        return response()->json(['data' => $this->payload($order, $summary)]);
    }

    public function store(RecordOrderPaymentRequest $request, Order $order, CreateInflowAction $action, OrderPaymentSummary $summary): JsonResponse
    {
        $attributes = $request->validated();
        $attributes['order_id'] = $order->getKey();
        $attributes['customer_id'] ??= $order->customer_id;
        $attributes['category'] ??= 'Order payment';
        $attributes['status'] ??= 'RECEIVED'; // recording a payment defaults to received

        $action->execute($attributes, $this->userId($request));

        return response()->json(['data' => $this->payload($order->refresh(), $summary)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Order $order, OrderPaymentSummary $summary): array
    {
        return [
            'summary' => $summary->for($order),
            'payments' => $order->inflows()->orderByDesc('date')->get()
                ->map(fn (Inflow $i) => InflowData::fromModel($i)->toArray())
                ->all(),
        ];
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
