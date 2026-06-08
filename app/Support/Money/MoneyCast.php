<?php

declare(strict_types=1);

namespace App\Support\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a bigint minor-units column to/from a Money value object.
 *
 * Optionally pair it with a currency column:
 *
 *     protected function casts(): array
 *     {
 *         return ['total_amount' => MoneyCast::class.':currency'];
 *     }
 *
 * When no currency column is given, the currency is resolved (in order) from the
 * model's tenant default_currency, then the platform default. This lets early
 * tables store just an amount while still producing a currency-aware Money.
 *
 * @implements CastsAttributes<Money, Money>
 */
class MoneyCast implements CastsAttributes
{
    public function __construct(
        private readonly ?string $currencyColumn = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return Money::fromMinor((int) $value, $this->resolveCurrency($model, $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        $money = $value instanceof Money
            ? $value
            : Money::fromMinor((int) $value, $this->resolveCurrency($model, $attributes));

        $result = [$key => $money->getMinorAmount()];

        if ($this->currencyColumn !== null) {
            $result[$this->currencyColumn] = $money->getCurrencyCode();
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveCurrency(Model $model, array $attributes): string
    {
        if ($this->currencyColumn !== null && ! empty($attributes[$this->currencyColumn])) {
            return (string) $attributes[$this->currencyColumn];
        }

        // Fall back to the model's tenant currency, then the platform default.
        $tenantCurrency = $this->tenantCurrency($model);

        return $tenantCurrency ?? CurrencyRegistry::default()->code;
    }

    private function tenantCurrency(Model $model): ?string
    {
        $tenant = method_exists($model, 'tenant') ? $model->getRelationValue('tenant') : null;

        return $tenant?->settings?->default_currency;
    }
}
