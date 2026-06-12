<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\CloudflareAiGatewayClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareAiGatewayClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai.gateway.enabled' => true,
            'ai.gateway.account_id' => 'acct_1',
            'ai.gateway.gateway_id' => 'default',
            'ai.gateway.analytics_token' => 'cf_token',
            'ai.gateway.api_base' => 'https://api.cloudflare.com/client/v4',
        ]);
    }

    public function test_per_tenant_spend_is_aggregated_from_the_logs_api(): void
    {
        Http::fake([
            'api.cloudflare.com/*/ai-gateway/gateways/default/logs*' => Http::response([
                'result' => [
                    ['cost' => 0.0125, 'tokens_in' => 100, 'tokens_out' => 40, 'metadata' => ['tenant_id' => 't1']],
                    ['cost' => 0.0075, 'tokens_in' => 60, 'tokens_out' => 20, 'metadata' => ['tenant_id' => 't1']],
                ],
                'result_info' => ['total_count' => 2, 'page' => 1, 'per_page' => 200],
                'success' => true,
            ]),
        ]);

        $spend = app(CloudflareAiGatewayClient::class)
            ->spendForTenant('t1', '2026-05-01T00:00:00Z', '2026-06-01T00:00:00Z');

        $this->assertEqualsWithDelta(0.02, $spend['cost_usd'], 0.0001);
        $this->assertSame(160, $spend['prompt_tokens']);
        $this->assertSame(60, $spend['completion_tokens']);
        $this->assertSame(2, $spend['requests']);

        // The request filters by the tenant metadata tag.
        Http::assertSent(function ($request): bool {
            $url = urldecode($request->url());

            return str_contains($url, '/accounts/acct_1/ai-gateway/gateways/default/logs')
                && str_contains($url, 'metadata.tenant_id')
                && str_contains($url, 't1');
        });
    }

    public function test_unconfigured_gateway_returns_zeroes_without_calling_the_api(): void
    {
        config(['ai.gateway.analytics_token' => null]);
        Http::fake();

        $spend = app(CloudflareAiGatewayClient::class)->spendGlobal('2026-05-01', '2026-06-01');

        $this->assertSame(0, $spend['requests']);
        Http::assertNothingSent();
    }
}
