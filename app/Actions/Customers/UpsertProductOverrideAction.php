<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\CustomerProductOverride;
use App\Models\InventoryItem;

class UpsertProductOverrideAction
{
    public function execute(Customer $customer, InventoryItem $item, bool $visible): CustomerProductOverride
    {
        return CustomerProductOverride::updateOrCreate(
            ['customer_id' => $customer->getKey(), 'inventory_item_id' => $item->getKey()],
            ['visible' => $visible],
        );
    }
}
