<?php

declare(strict_types=1);

namespace App\Actions\Suppliers;

use App\DataTransferObjects\SupplierData;
use App\Models\Supplier;

class CreateSupplierAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes): SupplierData
    {
        return SupplierData::fromModel(Supplier::create($attributes));
    }
}
