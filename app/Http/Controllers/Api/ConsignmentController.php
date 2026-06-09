<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Orders\CloseConsignmentAction;
use App\Actions\Orders\RecordConsignmentReturnAction;
use App\Actions\Orders\RecordConsignmentSaleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\ConsignmentReturnRequest;
use App\Http\Requests\Orders\ConsignmentSaleRequest;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\ConsignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsignmentController extends Controller
{
    public function summary(Order $order, ConsignmentService $service): JsonResponse
    {
        return response()->json(['data' => $service->summary($order)]);
    }

    public function sale(ConsignmentSaleRequest $request, Order $order, RecordConsignmentSaleAction $action, ConsignmentService $service): JsonResponse
    {
        /** @var list<array{order_item_id: string, quantity: int|string, unit_price?: int|string|null}> $items */
        $items = (array) $request->validated('items', []);
        $note = $request->has('note') ? $request->string('note')->value() : null;

        $action->execute($order, $items, $note, $this->userId($request));

        return response()->json(['data' => $service->summary($order->refresh())]);
    }

    public function recordReturn(ConsignmentReturnRequest $request, Order $order, RecordConsignmentReturnAction $action, ConsignmentService $service): JsonResponse
    {
        /** @var list<array{order_item_id: string, quantity: int|string}> $items */
        $items = (array) $request->validated('items', []);
        $note = $request->has('note') ? $request->string('note')->value() : null;

        $action->execute($order, $items, $note, $this->userId($request));

        return response()->json(['data' => $service->summary($order->refresh())]);
    }

    public function close(Request $request, Order $order, CloseConsignmentAction $action, ConsignmentService $service): JsonResponse
    {
        $action->execute($order, $this->userId($request));

        return response()->json(['data' => $service->summary($order->refresh())]);
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
