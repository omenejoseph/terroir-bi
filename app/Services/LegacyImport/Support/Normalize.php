<?php

declare(strict_types=1);

namespace App\Services\LegacyImport\Support;

use Carbon\CarbonImmutable;

/** Coercions for the Prisma/TablePlus dump quirks ('' vs NULL, 't'/'f', float noise). */
final class Normalize
{
    public static function str(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    public static function bool(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === 'true' || $v === '1' || $v === 1;
    }

    public static function date(mixed $v): ?CarbonImmutable
    {
        $v = self::str($v);

        return $v === null ? null : CarbonImmutable::parse($v, 'UTC');
    }

    /** Major-unit decimal (numeric(65,30)/float noise) → integer minor units. */
    public static function minor(mixed $v): int
    {
        return $v === null || $v === '' ? 0 : (int) round(((float) $v) * 100);
    }

    /** Float/numeric noise → fixed-scale decimal string (quantities, percentages). */
    public static function dec(mixed $v, int $scale = 3): ?string
    {
        return $v === null || $v === '' ? null : number_format((float) $v, $scale, '.', '');
    }

    /** Nullable money: NULL stays NULL (unknown), otherwise minor units. */
    public static function minorOrNull(mixed $v): ?int
    {
        return $v === null || $v === '' ? null : self::minor($v);
    }

    /** Prisma's bcryptjs `$2b$` → PHP-recognised `$2y$` (same algorithm, so Laravel won't rehash). */
    public static function bcrypt(string $hash): string
    {
        return str_starts_with($hash, '$2b$') ? '$2y$'.substr($hash, 4) : $hash;
    }

    /** @return array{0: string, 1: string} [first, last] */
    public static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = array_shift($parts) ?: 'Unknown';

        return [$first, $parts === [] ? '-' : implode(' ', $parts)];
    }
}
