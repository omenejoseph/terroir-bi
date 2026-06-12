<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Ai\AiRequestContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Message\RequestInterface;

/**
 * Wires the Cloudflare AI Gateway into every outbound inference request.
 *
 * Laravel AI uses the `Http` facade, so a global request middleware can stamp
 * the gateway id and the per-tenant `cf-aig-metadata` header onto requests
 * bound for the gateway host — without the AI SDK needing to know about either.
 */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiRequestContext::class);
    }

    public function boot(): void
    {
        if (! config('ai.gateway.enabled')) {
            return;
        }

        $context = $this->app->make(AiRequestContext::class);
        $host = (string) config('ai.gateway.host');
        $gatewayId = (string) config('ai.gateway.gateway_id');

        Http::globalRequestMiddleware(function (RequestInterface $request) use ($context, $host, $gatewayId): RequestInterface {
            $uri = $request->getUri();

            // Only stamp requests headed for the gateway inference endpoint.
            if ($uri->getHost() !== $host || ! str_contains($uri->getPath(), '/ai/v1')) {
                return $request;
            }

            $request = $request->withHeader('cf-aig-gateway-id', $gatewayId);

            if ($context->has()) {
                $request = $request->withHeader(
                    'cf-aig-metadata',
                    (string) json_encode($context->metadata()),
                );
            }

            return $request;
        });
    }
}
