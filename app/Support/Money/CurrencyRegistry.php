<?php

declare(strict_types=1);

namespace App\Support\Money;

use App\Support\Money\Exceptions\UnsupportedCurrencyException;

/**
 * Resolves Currency descriptors from config/money.php. Single source of truth
 * for which currencies an organisation may choose.
 */
final class CurrencyRegistry
{
    /** @var array<string, Currency> */
    private static array $cache = [];

    /** @return array<string, Currency> */
    public static function all(): array
    {
        if (self::$cache !== []) {
            return self::$cache;
        }

        foreach ((array) config('money.currencies', []) as $code => $meta) {
            $code = strtoupper((string) $code);
            self::$cache[$code] = new Currency(
                code: $code,
                minorUnit: (int) ($meta['minor_unit'] ?? 2),
                symbol: (string) ($meta['symbol'] ?? $code),
                name: (string) ($meta['name'] ?? $code),
            );
        }

        return self::$cache;
    }

    public static function isSupported(string $code): bool
    {
        return isset(self::all()[strtoupper($code)]);
    }

    public static function get(string $code): Currency
    {
        $code = strtoupper($code);

        return self::all()[$code] ?? throw UnsupportedCurrencyException::for($code);
    }

    public static function default(): Currency
    {
        return self::get((string) config('money.default', 'EUR'));
    }

    /** Reset the in-memory cache (used in tests when config changes). */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
