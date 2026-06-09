<?php

declare(strict_types=1);

namespace App\Services\Customers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Resolves a company's registered name and address from its OIB/EU-VAT number
 * via the EU VIES service, to auto-fill the customer form. The HTTP client is
 * injected so it can be faked in tests and so the call respects the
 * environment's outbound network policy.
 */
class LookupCompanyByVatService
{
    private const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms';

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @return array{vat: string, name: ?string, address: ?string, city: ?string, zip: ?string, country: string}|array{error: string}
     */
    public function lookup(string $raw): array
    {
        $parsed = $this->splitVat($raw);

        if ($parsed === null) {
            return ['error' => 'Enter a valid OIB (11 digits) or VAT number with a country prefix.'];
        }

        [$country, $number] = $parsed;

        try {
            $response = $this->http->acceptJson()
                ->timeout(8)
                ->get(self::ENDPOINT."/{$country}/vat/{$number}");
        } catch (ConnectionException) {
            return ['error' => 'Could not reach the VIES service. Try again later.'];
        }

        if (! $response->successful()) {
            return ['error' => 'VIES lookup failed.'];
        }

        /** @var array<string, mixed> $body */
        $body = (array) $response->json();

        $valid = (bool) ($body['isValid'] ?? $body['valid'] ?? false);

        if (! $valid) {
            return ['error' => 'VAT number not found or not valid.'];
        }

        $name = $this->cleanString($body['name'] ?? null);
        [$street, $city, $zip] = $this->parseAddress($this->cleanString($body['address'] ?? null));

        return [
            'vat' => $country.$number,
            'name' => $name,
            'address' => $street,
            'city' => $city,
            'zip' => $zip,
            'country' => $country,
        ];
    }

    /**
     * Split a raw input into [countryCode, number]. An all-digit 11-char input
     * is treated as a Croatian OIB.
     *
     * @return array{0: string, 1: string}|null
     */
    private function splitVat(string $raw): ?array
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', $raw) ?? '');

        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^\d{11}$/', $normalized) === 1) {
            return ['HR', $normalized];
        }

        if (preg_match('/^([A-Z]{2})([0-9A-Z]+)$/', $normalized, $m) === 1) {
            return [$m[1], $m[2]];
        }

        return null;
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' || $trimmed === '---' ? null : $trimmed;
    }

    /**
     * Best-effort parse of a VIES address blob into [street, city, zip]. VIES
     * formats vary by member state; we split on newlines/commas and pull a
     * postal code + city out of the last segment.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function parseAddress(?string $address): array
    {
        if ($address === null) {
            return [null, null, null];
        }

        $segments = array_values(array_filter(array_map(
            'trim',
            preg_split('/[\n,]+/', $address) ?: [],
        )));

        if ($segments === []) {
            return [null, null, null];
        }

        $street = $segments[0];
        $tail = $segments[count($segments) - 1];
        $zip = null;
        $city = null;

        if (preg_match('/(\d{4,5})\s+(.+)/', $tail, $m) === 1) {
            $zip = $m[1];
            $city = trim($m[2]);
        } elseif (count($segments) > 1) {
            $city = $tail;
        }

        return [$street, $city, $zip];
    }
}
