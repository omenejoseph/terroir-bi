<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Sends a one-line chat completion through the Cloudflare AI Gateway using the
 * app's resolved config (so there are no shell-env mistakes), to debug model /
 * BYOK / slug issues. Prints the HTTP status and raw body; never prints secrets.
 *
 *   php artisan ai:ping
 *   php artisan ai:ping "openai/gpt-4o-mini"
 *   php artisan ai:ping "google/gemini-2.5-flash-lite" --prompt="say hello"
 */
class AiPingCommand extends Command
{
    protected $signature = 'ai:ping {model=google/gemini-2.5-flash-lite : The provider/model id} {--prompt=say ok}';

    protected $description = 'Send a test chat completion through the Cloudflare AI Gateway to debug a model.';

    public function handle(): int
    {
        if (! config('ai.gateway.enabled')) {
            $this->error('Gateway not configured — set CLOUDFLARE_ACCOUNT_ID and CLOUDFLARE_API_TOKEN.');

            return self::FAILURE;
        }

        $url = rtrim((string) config('ai.gateway.inference_url'), '/').'/chat/completions';
        $model = (string) $this->argument('model');

        // Mask the account id in the printed URL.
        $this->line('POST '.preg_replace('#/accounts/[^/]+/#', '/accounts/***/', $url));
        $this->line('gateway: '.config('ai.gateway.gateway_id').'   model: '.$model);

        $response = Http::withToken((string) config('ai.gateway.token'))
            ->withHeaders(['cf-aig-gateway-id' => (string) config('ai.gateway.gateway_id')])
            ->acceptJson()
            ->post($url, [
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => (string) $this->option('prompt')]],
            ]);

        $this->newLine();
        $this->line('HTTP '.$response->status());
        $this->line($response->body());

        return $response->successful() ? self::SUCCESS : self::FAILURE;
    }
}
