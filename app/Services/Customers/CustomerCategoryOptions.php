<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\CustomerCategory;

/**
 * The category picker: the Customers list's Category filter and the customer form's select
 * share it. Inactive categories are kept out of the choices (a customer already holding one
 * still shows it) so retired labels can't be handed out again.
 */
class CustomerCategoryOptions
{
    /** @return list<array{id: string, name: string}> */
    public function list(): array
    {
        return array_values(CustomerCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (CustomerCategory $c): array => ['id' => (string) $c->getKey(), 'name' => $c->name])
            ->all());
    }
}
