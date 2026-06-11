<?php

declare(strict_types=1);

namespace App\Services\Billing;

use RuntimeException;
use Stripe\StripeClient;

/**
 * Builds a configured Stripe client. A single seam so the secret lives in one
 * place and the client is trivial to fake in tests (by mocking StripeGateway).
 */
class StripeClientFactory
{
    public function make(): StripeClient
    {
        $secret = config('services.stripe.secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('Stripe secret is not configured (services.stripe.secret).');
        }

        return new StripeClient($secret);
    }
}
