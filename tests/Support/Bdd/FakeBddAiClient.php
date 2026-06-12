<?php

declare(strict_types=1);

namespace Tests\Support\Bdd;

use App\Enums\AiCapability;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiRequestContext;
use App\Services\Bdd\Tools\BddTool;
use App\Support\Ai\AiModelConfig;
use App\Tenancy\Contracts\TenantContext;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Tools\Request;

/**
 * Deterministic AiClient for BDD live-runner tests: prompt() replays a
 * scripted tool-call sequence against the agent's REAL tool instances —
 * executing real Tool::handle() inside the runner's open transaction — then
 * returns a stub response. (laravel/ai's FakeTextGateway cannot chain more
 * than one faked ToolCall, so the loop is driven here instead; everything the
 * app owns — tools, context, invoker, verdict, rollback — runs for real.)
 *
 * Tool results are collected on $results so tests can assert on the exact
 * feedback strings the model would have seen.
 */
class FakeBddAiClient extends AiClient
{
    /** @var list<string> */
    public array $results = [];

    /**
     * @param  list<array{0: string, 1: array<string, mixed>}>  $script  [toolName, arguments] pairs
     */
    public function __construct(
        private readonly array $script,
        private readonly string $finalText = 'Run complete.',
    ) {
        parent::__construct(app(AiModelConfig::class), app(AiRequestContext::class), app(TenantContext::class));
    }

    public function enabled(): bool
    {
        return true;
    }

    public function prompt(
        Agent $agent,
        string $prompt,
        AiCapability $capability,
        string $feature,
        array $attachments = [],
        ?string $importId = null,
        ?int $timeout = null,
    ): AgentResponse {
        assert($agent instanceof HasTools);

        $tools = [];
        foreach ($agent->tools() as $tool) {
            if ($tool instanceof BddTool) {
                $tools[$tool->name()] = $tool;
            }
        }

        foreach ($this->script as [$name, $arguments]) {
            $this->results[] = (string) $tools[$name]->handle(new Request($arguments));
        }

        return new AgentResponse('fake-invocation', $this->finalText, new Usage, new Meta('fake', 'fake-model'));
    }
}
