<?php

declare(strict_types=1);

namespace App\Actions\Suppliers;

use App\Enums\SupplierOrderStatus;
use App\Models\SupplierOrder;
use Illuminate\Validation\ValidationException;

class DeleteSupplierOrderAction
{
    public function execute(SupplierOrder $order): void
    {
        if (! in_array($order->status, [SupplierOrderStatus::Draft, SupplierOrderStatus::Cancelled], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only draft or cancelled purchase orders can be deleted.',
            ]);
        }

        $order->delete();
    }
}
