<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Organisation-wide settings: the tenant name plus its TenantSetting row
 * (currency, locale, timezone, tax id). Transport-agnostic — feeds the API and
 * the auth session so the frontend can format money/dates without a round-trip.
 *
 * @implements Arrayable<string, mixed>
 */
final class OrganizationSettingsData implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $name,
        public readonly string $defaultLocale,
        public readonly string $defaultCurrency,
        public readonly string $timezone,
        public readonly ?string $companyOib,
    ) {}

    public static function fromTenant(Tenant $tenant): self
    {
        // A tenant normally has a 1:1 settings row (created with the tenant); fall
        // back to sane defaults if one is missing or a column is unset (e.g. an
        // env that hasn't run the latest migration).
        $settings = $tenant->settings;

        if (! $settings instanceof TenantSetting) {
            return new self($tenant->name, $tenant->default_locale, 'EUR', 'Europe/Zagreb', null);
        }

        return new self(
            name: $tenant->name,
            defaultLocale: self::str($settings, 'default_locale', $tenant->default_locale),
            defaultCurrency: self::str($settings, 'default_currency', 'EUR'),
            timezone: self::str($settings, 'timezone', 'Europe/Zagreb'),
            companyOib: $settings->company_oib,
        );
    }

    /** Read a string attribute, falling back when it is missing/null/empty. */
    private static function str(TenantSetting $settings, string $attribute, string $fallback): string
    {
        $value = $settings->getAttribute($attribute);

        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'default_locale' => $this->defaultLocale,
            'default_currency' => $this->defaultCurrency,
            'timezone' => $this->timezone,
            'company_oib' => $this->companyOib,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
