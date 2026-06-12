<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\AiImportStatus;
use App\Enums\AiImportType;
use App\Enums\NotificationType;
use App\Enums\TenantRole;
use App\Jobs\ProcessAiImportJob;
use App\Models\AiImport;
use App\Models\Cost;
use App\Models\Inflow;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\ExtractionService;
use App\Services\Ai\Extractors\BankStatementExtractor;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class AiImportTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.enabled' => true]);
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
        Sanctum::actingAs($this->admin);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    public function test_extraction_then_commit_creates_ai_tagged_cost_and_inflow(): void
    {
        // The model "reads" a statement with one outgoing and one incoming line.
        BankStatementExtractor::fake([[
            'transactions' => [
                ['date' => '2026-05-01', 'description' => 'Glass supplier', 'amount' => 123.45, 'direction' => 'out', 'category' => 'Packaging', 'reference' => 'INV-1'],
                ['date' => '2026-05-03', 'description' => 'Customer payment', 'amount' => 50.00, 'direction' => 'in', 'category' => 'Sales', 'reference' => 'PMT-9'],
            ],
        ]]);

        $this->actingAsTenant($this->tenant);
        $import = AiImport::create([
            'type' => AiImportType::BankStatement,
            'status' => AiImportStatus::Uploaded,
            'created_by_id' => $this->admin->getKey(),
        ]);
        $this->forgetTenant();

        // Run extraction as the queued job would (it rebinds the tenant itself).
        (new ProcessAiImportJob($import->getKey(), $this->tenant->getKey()))
            ->handle(app(ExtractionService::class), app(TenantContext::class));

        // Review: the import is ready with two proposed lines (one cost, one inflow).
        $response = $this->getJson("/api/v1/ai-imports/{$import->getKey()}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.lines_total', 2)
            ->assertJsonPath(
                'data.lines',
                fn (array $lines): bool => collect($lines)->pluck('target_type')->sort()->values()->all() === ['cost', 'inflow'],
            );

        $show = $response->json('data.lines');

        // Approve both lines, then commit.
        foreach ($show as $line) {
            $this->patchJson("/api/v1/ai-imports/{$import->getKey()}/lines/{$line['id']}", [
                'status' => 'approved',
            ], $this->headers())->assertOk();
        }

        $this->postJson("/api/v1/ai-imports/{$import->getKey()}/commit", [], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', 'committed')
            ->assertJsonPath('meta.committed', 2)
            ->assertJsonPath('meta.failed', 0);

        // The real records exist, tagged AI-generated, with amounts in minor units.
        $this->actingAsTenant($this->tenant);
        $cost = Cost::where('is_ai_generated', true)->firstOrFail();
        $this->assertSame(12345, $cost->total_amount->getMinorAmount());
        $this->assertSame('Packaging', $cost->category);
        $this->assertSame($import->getKey(), ($cost->ai_metadata ?? [])['ai_import_id'] ?? null);

        $inflow = Inflow::where('is_ai_generated', true)->firstOrFail();
        $this->assertSame(5000, $inflow->amount->getMinorAmount());
        $this->forgetTenant();
    }

    public function test_extraction_failure_marks_import_failed(): void
    {
        BankStatementExtractor::fake(function (): void {
            throw new \RuntimeException('model unavailable');
        });

        $this->actingAsTenant($this->tenant);
        $import = AiImport::create([
            'type' => AiImportType::BankStatement,
            'status' => AiImportStatus::Uploaded,
            'created_by_id' => $this->admin->getKey(),
        ]);
        $this->forgetTenant();

        (new ProcessAiImportJob($import->getKey(), $this->tenant->getKey()))
            ->handle(app(ExtractionService::class), app(TenantContext::class));

        $this->getJson("/api/v1/ai-imports/{$import->getKey()}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', fn ($v) => is_string($v) && str_contains($v, 'model unavailable'));
    }

    public function test_uploader_is_notified_when_extraction_is_ready(): void
    {
        BankStatementExtractor::fake([[
            'transactions' => [
                ['date' => '2026-05-01', 'description' => 'X', 'amount' => 10, 'direction' => 'out'],
            ],
        ]]);

        $this->actingAsTenant($this->tenant);
        $import = AiImport::create([
            'type' => AiImportType::BankStatement,
            'status' => AiImportStatus::Uploaded,
            'created_by_id' => $this->admin->getKey(),
        ]);
        $this->forgetTenant();

        (new ProcessAiImportJob($import->getKey(), $this->tenant->getKey()))
            ->handle(app(ExtractionService::class), app(TenantContext::class));

        // The uploader gets an in-app notification deep-linking to the import.
        $this->actingAsTenant($this->tenant);
        $note = Notification::query()
            ->where('user_id', $this->admin->getKey())
            ->where('type', NotificationType::AiImportReady)
            ->first();
        $this->forgetTenant();

        $this->assertNotNull($note);
        $this->assertSame($import->getKey(), ($note->data ?? [])['ai_import_id'] ?? null);
    }

    public function test_requires_ai_use_capability(): void
    {
        $sales = $this->createMember($this->tenant, [TenantRole::Sales]); // lacks ai.use
        Sanctum::actingAs($sales);

        $this->getJson('/api/v1/ai-imports', $this->headers())->assertForbidden();
    }
}
