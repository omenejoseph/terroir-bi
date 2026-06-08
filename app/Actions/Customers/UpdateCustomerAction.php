<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\DataTransferObjects\CustomerData;
use App\Models\Customer;

class UpdateCustomerAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Customer $customer, array $attributes): CustomerData
    {
        $customer->fill($attributes)->save();

        return CustomerData::fromModel($customer);
    }
}
