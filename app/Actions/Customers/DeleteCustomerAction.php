<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Models\Customer;

/**
 * Remove a customer — or, when that would destroy history, retire them.
 *
 * A customer with orders is deactivated rather than deleted: their orders are
 * the revenue record, and deleting the customer would orphan them. One with no
 * orders is deleted outright. Returns true when the customer was deactivated,
 * so the caller can say which of the two happened.
 *
 * Extracted so the JSON API and the Inertia web controller cannot disagree
 * about what "delete this customer" means.
 */
class DeleteCustomerAction
{
    public function execute(Customer $customer): bool
    {
        if ($customer->orders()->exists()) {
            $customer->is_active = false;
            $customer->save();

            return true;
        }

        $customer->delete();

        return false;
    }
}
