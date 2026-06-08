<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\InventoryItem;

class UpsertCustomerPriceAction
{
    public function execute(InventoryItem $item, Customer $customer, int $priceMinor): CustomerPrice
    {
        return CustomerPrice::updateOrCreate(
            ['inventory_item_id' => $item->getKey(), 'customer_id' => $customer->getKey()],
            ['price' => $priceMinor],
        );
    }
}
