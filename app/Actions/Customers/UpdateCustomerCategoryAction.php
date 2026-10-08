<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Models\CustomerCategory;

class UpdateCustomerCategoryAction
{
    /**
     * @param  array{name?: string, is_active?: bool}  $attributes
     */
    public function execute(CustomerCategory $category, array $attributes): CustomerCategory
    {
        if (isset($attributes['name'])) {
            $attributes['name'] = trim($attributes['name']);
        }

        $category->update($attributes);

        return $category;
    }
}
