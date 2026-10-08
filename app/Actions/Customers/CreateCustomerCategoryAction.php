<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Models\CustomerCategory;

class CreateCustomerCategoryAction
{
    public function execute(string $name): CustomerCategory
    {
        // New categories go to the end of the organisation's own ordering.
        $next = (int) CustomerCategory::query()->max('sort_order') + 1;

        return CustomerCategory::create(['name' => trim($name), 'sort_order' => $next]);
    }
}
