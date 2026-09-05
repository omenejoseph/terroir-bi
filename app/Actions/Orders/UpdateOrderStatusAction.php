<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UpdateOrderStatusAction
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Order $order, OrderStatus $status, ?string $note, string $changedById): Order
    {
        $from = $order->status;

        DB::transaction(function () use ($order, $status, $note, $changedById): void {
            $order->status = $status;
            $order->save();

            $order->statusHistories()->create([
                'status' => $status,
                'note' => $note,
                'changed_by_id' => $changedById,
            ]);
        });

        // Order's own Auditable listener ignores `status` (see its
        // auditIgnoreOnUpdate()) so this is the only entry for the change,
        // named for the transition rather than a bare before/after diff.
        $this->audit->record(Auth::user(), 'order.status_changed', $order, [
            'from' => $from->value,
            'to' => $status->value,
            'note' => $note,
        ]);

        $this->notifier->orderStatusChanged($order, $changedById);

        return $order;
    }
}
