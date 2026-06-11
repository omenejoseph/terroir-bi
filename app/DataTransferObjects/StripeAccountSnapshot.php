<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * The handful of fields we surface from a Stripe account when an admin tests the
 * connection. Keeps Stripe\Account out of the Filament page (the gateway is the
 * only place that touches the SDK).
 */
final class StripeAccountSnapshot
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $businessName,
        public readonly ?string $country,
        public readonly ?string $defaultCurrency,
        public readonly bool $chargesEnabled,
        public readonly bool $livemode,
    ) {}
}
