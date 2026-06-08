<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\DataTransferObjects\CustomerData;
use App\Models\Customer;
use Illuminate\Support\Str;

/**
 * Issues or revokes a customer's self-service order token. The token is the
 * credential for the (future) public catalog, so it is high-entropy and stored
 * as-is for lookup. Generating replaces any existing token.
 */
class OrderTokenAction
{
    /**
     * @return array{token: string, customer: CustomerData}
     */
    public function generate(Customer $customer): array
    {
        $token = Str::random(64);
        $customer->order_token = $token;
        $customer->save();

        return ['token' => $token, 'customer' => CustomerData::fromModel($customer)];
    }

    public function revoke(Customer $customer): CustomerData
    {
        $customer->order_token = null;
        $customer->save();

        return CustomerData::fromModel($customer);
    }
}
