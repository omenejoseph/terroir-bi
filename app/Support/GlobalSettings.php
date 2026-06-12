<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\GlobalSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Cached read/write access to global (platform-wide) settings. Lookups are
 * cached forever and invalidated on write. Values are JSON (see GlobalSetting's
 * `value` cast), so booleans, strings and arrays all round-trip.
 */
class GlobalSettings
{
    private const CACHE_PREFIX = 'global_setting:';

    public function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::rememberForever(
            self::CACHE_PREFIX.$key,
            fn () => GlobalSetting::query()->where('key', $key)->first()?->value,
        );

        return $value ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        GlobalSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        Cache::forget(self::CACHE_PREFIX.$key);
    }

    public function forget(string $key): void
    {
        GlobalSetting::query()->where('key', $key)->delete();

        Cache::forget(self::CACHE_PREFIX.$key);
    }
}
