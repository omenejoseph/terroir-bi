<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Money & currency
    |--------------------------------------------------------------------------
    |
    | Money is stored as integer minor units (bigint) and represented by the
    | App\Support\Money\Money value object. Each organisation chooses ONE base
    | currency (stored on tenant_settings.default_currency).
    |
    | NOTE: There is deliberately NO exchange-rate / conversion machinery here.
    | Multi-currency conversion is a deferred feature. Because every Money value
    | already carries its currency code, conversion can be added later WITHOUT a
    | schema change. Do not add float-based math anywhere — use the Money VO.
    |
    */

    // Platform default currency, used as a fallback when no tenant currency is set.
    'default' => env('MONEY_DEFAULT_CURRENCY', 'EUR'),

    /*
    | Supported currencies an organisation may choose from.
    |
    | minor_unit is read by the Money VO (not hardcoded) so non-2-decimal
    | currencies can be supported later without touching call sites.
    */
    'currencies' => [
        'EUR' => ['minor_unit' => 2, 'symbol' => '€',  'name' => 'Euro'],
        'USD' => ['minor_unit' => 2, 'symbol' => '$',  'name' => 'US Dollar'],
        'GBP' => ['minor_unit' => 2, 'symbol' => '£',  'name' => 'Pound Sterling'],
        'HRK' => ['minor_unit' => 2, 'symbol' => 'kn', 'name' => 'Croatian Kuna'],
    ],

];
