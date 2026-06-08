<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\DataTransferObjects\CustomerData;
use App\Models\Customer;

class CreateCustomerAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes): CustomerData
    {
        return CustomerData::fromModel(Customer::create($attributes));
    }
}
