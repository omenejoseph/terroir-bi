<?php

declare(strict_types=1);

namespace App\Support\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a decimal(18,4) minor-units column to/from a UnitPrice (see UnitPrice for why it
 * isn't whole minor units). The currency is the model's tenant currency, then the platform
 * default — as MoneyCast does for tables without a currency column.
 *
 * @implements CastsAttributes<UnitPrice, UnitPrice|Money|int|float|string>
 */
class UnitPriceCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?UnitPrice
    {
        if ($value === null) {
            return null;
        }

        return UnitPrice::fromMinor(is_numeric($value) ? $value : 0, $this->currency($model));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }
        if ($value instanceof UnitPrice) {
            return [$key => $value->minor];
        }
        if ($value instanceof Money) {
            return [$key => UnitPrice::normalize($value->getMinorAmount())];
        }

        return [$key => UnitPrice::normalize(is_numeric($value) ? $value : 0)];
    }

    private function currency(Model $model): string
    {
        return $this->tenantCurrency($model) ?? CurrencyRegistry::default()->code;
    }

    private function tenantCurrency(Model $model): ?string
    {
        $tenant = method_exists($model, 'tenant') ? $model->getRelationValue('tenant') : null;

        return $tenant?->settings?->default_currency;
    }
}
