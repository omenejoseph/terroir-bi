<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\Billing\StripeGateway;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Port of App\Filament\Pages\StripeSettings: connection status/diagnostics
 * only — secrets live in the environment and are never read/written here.
 */
class StripeSettingsController extends Controller
{
    public function index(StripeGateway $gateway): Response
    {
        return Inertia::render('Admin/StripeSettings/Index', [
            'secretConfigured' => $gateway->isConfigured(),
            'webhookConfigured' => $gateway->hasWebhookSecret(),
            'successUrl' => (string) config('services.stripe.success_url'),
            'cancelUrl' => (string) config('services.stripe.cancel_url'),
        ]);
    }

    /** Fetched on demand by "Test connection" — not a page prop. */
    public function testConnection(StripeGateway $gateway): JsonResponse
    {
        if (! $gateway->isConfigured()) {
            return response()->json(['message' => __('No Stripe secret is configured (set STRIPE_SECRET in the environment).')], 422);
        }

        try {
            $snapshot = $gateway->retrieveAccount();
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $snapshot->id,
            'business_name' => $snapshot->businessName,
            'country' => $snapshot->country,
            'default_currency' => $snapshot->defaultCurrency,
            'charges_enabled' => $snapshot->chargesEnabled,
            'livemode' => $snapshot->livemode,
        ]);
    }
}
