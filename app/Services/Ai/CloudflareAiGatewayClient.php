<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads AI spend from the Cloudflare AI Gateway Logs API. The GraphQL analytics
 * API cannot group by custom metadata, so per-tenant attribution is done here by
 * filtering logs on `metadata.tenant_id` (stamped by AiServiceProvider) and
 * aggregating client-side. The Logs API returns a USD `cost` per request.
 *
 *   GET {api_base}/accounts/{account}/ai-gateway/gateways/{gateway}/logs
 *   Authorization: Bearer {analytics_token}  (needs AI Gateway Read)
 */
class CloudflareAiGatewayClient
{
    private const PER_PAGE = 200;

    private const MAX_PAGES = 25; // safety cap → up to 5000 logs per window

    public function __construct(private readonly Http $http) {}

    public function configured(): bool
    {
        return (bool) config('ai.gateway.enabled') && (bool) config('ai.gateway.analytics_token');
    }

    /**
     * Aggregate spend for one tenant over a window.
     *
     * @return array{cost_usd: float, prompt_tokens: int, completion_tokens: int, requests: int, truncated: bool}
     */
    public function spendForTenant(string $tenantId, string $from, string $to): array
    {
        return $this->aggregate(
            [['key' => 'metadata.tenant_id', 'operator' => 'eq', 'value' => $tenantId]],
            $from,
            $to,
        );
    }

    /**
     * Aggregate spend across the whole platform over a window.
     *
     * @return array{cost_usd: float, prompt_tokens: int, completion_tokens: int, requests: int, truncated: bool}
     */
    public function spendGlobal(string $from, string $to): array
    {
        return $this->aggregate([], $from, $to);
    }

    /**
     * Per-tenant spend across the platform, grouped client-side by the
     * `metadata.tenant_id` tag.
     *
     * @return array<string, array{cost_usd: float, prompt_tokens: int, completion_tokens: int, requests: int}>
     */
    public function spendByTenant(string $from, string $to): array
    {
        $groups = [];

        $this->eachLog([], $from, $to, function (array $log) use (&$groups): void {
            $tenantId = $log['metadata']['tenant_id'] ?? '_untagged';
            $groups[$tenantId] ??= ['cost_usd' => 0.0, 'prompt_tokens' => 0, 'completion_tokens' => 0, 'requests' => 0];
            $groups[$tenantId]['cost_usd'] += (float) ($log['cost'] ?? 0);
            $groups[$tenantId]['prompt_tokens'] += (int) ($log['tokens_in'] ?? 0);
            $groups[$tenantId]['completion_tokens'] += (int) ($log['tokens_out'] ?? 0);
            $groups[$tenantId]['requests']++;
        });

        return $groups;
    }

    /**
     * @param  list<array{key: string, operator: string, value: string}>  $filters
     * @return array{cost_usd: float, prompt_tokens: int, completion_tokens: int, requests: int, truncated: bool}
     */
    public function aggregate(array $filters, string $from, string $to): array
    {
        $totals = ['cost_usd' => 0.0, 'prompt_tokens' => 0, 'completion_tokens' => 0, 'requests' => 0, 'truncated' => false];

        $totals['truncated'] = $this->eachLog($filters, $from, $to, function (array $log) use (&$totals): void {
            $totals['cost_usd'] += (float) ($log['cost'] ?? 0);
            $totals['prompt_tokens'] += (int) ($log['tokens_in'] ?? 0);
            $totals['completion_tokens'] += (int) ($log['tokens_out'] ?? 0);
            $totals['requests']++;
        });

        return $totals;
    }

    /**
     * Stream every log in the window through $handle, paginating up to the cap.
     * Returns true if the cap was hit (results truncated).
     *
     * @param  list<array{key: string, operator: string, value: string}>  $filters
     */
    private function eachLog(array $filters, string $from, string $to, callable $handle): bool
    {
        if (! $this->configured()) {
            return false;
        }

        $base = sprintf(
            '%s/accounts/%s/ai-gateway/gateways/%s/logs',
            config('ai.gateway.api_base'),
            config('ai.gateway.account_id'),
            config('ai.gateway.gateway_id'),
        );

        $page = 1;

        while ($page <= self::MAX_PAGES) {
            $query = [
                'per_page' => self::PER_PAGE,
                'page' => $page,
                'start_date' => $from,
                'end_date' => $to,
            ];

            if ($filters !== []) {
                $query['filters'] = json_encode($filters);
            }

            $response = $this->http
                ->withToken((string) config('ai.gateway.analytics_token'))
                ->acceptJson()
                ->get($base, $query);

            if ($response->failed()) {
                Log::warning('Cloudflare AI Gateway logs request failed', [
                    'status' => $response->status(),
                    'body' => $response->json('errors') ?? $response->body(),
                ]);

                return false;
            }

            $logs = $response->json('result') ?? [];

            foreach ($logs as $log) {
                $handle($log);
            }

            $total = (int) ($response->json('result_info.total_count') ?? count($logs));

            if (count($logs) < self::PER_PAGE || ($page * self::PER_PAGE) >= $total) {
                return false;
            }

            $page++;
        }

        return true; // hit the page cap
    }
}
