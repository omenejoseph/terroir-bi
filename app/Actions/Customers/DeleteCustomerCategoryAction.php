<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Models\CustomerCategory;

class DeleteCustomerCategoryAction
{
    /** Customers keep existing; they simply lose the label (the foreign key nulls it). */
    public function execute(CustomerCategory $category): void
    {
        $category->delete();
    }
}
