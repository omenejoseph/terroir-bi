<?php

declare(strict_types=1);

namespace App\Actions\Suppliers;

use App\DataTransferObjects\SupplierData;
use App\Models\Supplier;
use Illuminate\Support\Str;

/**
 * Issues or revokes a supplier's public portal token. The token is the credential
 * for the public supplier portal, so it is high-entropy and stored as-is for
 * lookup. Generating replaces any existing token (regenerate = revoke old link).
 */
class SupplierPortalTokenAction
{
    /**
     * @return array{token: string, supplier: SupplierData}
     */
    public function generate(Supplier $supplier): array
    {
        $token = Str::random(64);
        $supplier->portal_token = $token;
        $supplier->save();

        return ['token' => $token, 'supplier' => SupplierData::fromModel($supplier)];
    }

    public function revoke(Supplier $supplier): SupplierData
    {
        $supplier->portal_token = null;
        $supplier->save();

        return SupplierData::fromModel($supplier);
    }
}
