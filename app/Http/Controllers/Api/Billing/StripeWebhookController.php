<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Billing;

use App\Actions\Billing\SyncSubscriptionFromStripeAction;
use App\Http\Controllers\Controller;
use App\Services\Billing\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Receives Stripe webhooks. Thin: verify the signature, normalise the event and
 * hand off to the sync action. No tenant context / auth — Stripe's signature is
 * the authentication.
 */
class StripeWebhookController extends Controller
{
    public function handle(Request $request, StripeGateway $stripe, SyncSubscriptionFromStripeAction $sync): JsonResponse
    {
        try {
            $event = $stripe->constructWebhookEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature', ''),
            );
        } catch (Throwable) {
            return response()->json(['message' => 'Invalid webhook signature.'], 400);
        }

        $snapshot = $stripe->snapshotFromEvent($event);

        if ($snapshot !== null) {
            $sync->execute($snapshot);
        }

        return response()->json(status: 200);
    }
}
