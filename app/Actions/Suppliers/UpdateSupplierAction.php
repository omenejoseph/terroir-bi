<?php

declare(strict_types=1);

namespace App\Actions\Suppliers;

use App\DataTransferObjects\SupplierData;
use App\Models\Supplier;

class UpdateSupplierAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Supplier $supplier, array $attributes): SupplierData
    {
        $supplier->fill($attributes)->save();

        return SupplierData::fromModel($supplier);
    }
}
