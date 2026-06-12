<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AiImportLineStatus;
use App\Enums\AiImportStatus;
use App\Enums\AiImportType;
use App\Models\AiImport;
use App\Models\Cost;
use App\Models\Inflow;
use App\Models\InventoryItem;
use App\Models\Supplier;
use App\Services\Ai\Extractors\ExtractorFactory;
use App\Services\Notifications\Notifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Runs an import end-to-end: read the document, extract structured data through
 * the configured model, and persist the proposed lines for review. Always
 * leaves the import in a terminal-ish state (ready or failed).
 */
class ExtractionService
{
    public function __construct(
        private readonly ExtractorFactory $factory,
        private readonly AiClient $ai,
        private readonly Notifier $notifier,
    ) {}

    public function process(AiImport $import): void
    {
        $import->update(['status' => AiImportStatus::Processing]);

        try {
            $extractor = $this->factory->for($import->type)
                ->withVocabulary($this->vocabularyFor($import->type));

            $attachments = [];
            if ($import->source_object_key !== null) {
                $attachments = $this->ai->attachmentsFor($import->source_object_key, $import->source_mime);
            }

            $response = $this->ai->prompt(
                $extractor,
                $extractor->userPrompt(),
                $extractor->capability(),
                $extractor->feature(),
                $attachments,
                $import->getKey(),
            );

            // Structured agents return a StructuredAgentResponse (array-able).
            $structured = $response instanceof StructuredAgentResponse ? $response->toArray() : [];
            $lines = $extractor->mapToLines($structured);

            DB::transaction(function () use ($import, $lines, $response): void {
                $import->lines()->delete();

                foreach ($lines as $i => $line) {
                    $import->lines()->create([
                        'index' => $i,
                        'target_type' => $line['target_type'],
                        'payload' => $line['payload'],
                        'category' => $line['category'] ?? null,
                        'confidence' => $line['confidence'] ?? null,
                        'status' => AiImportLineStatus::Pending,
                    ]);
                }

                $import->update([
                    'status' => AiImportStatus::Ready,
                    'provider' => $this->providerFromModel($response->meta->model),
                    'model' => $response->meta->model ?? $this->ai->modelFor($import->type->capability()),
                    'prompt_tokens' => $response->usage->promptTokens,
                    'completion_tokens' => $response->usage->completionTokens,
                    'error' => null,
                ]);
            });

            // Tell the uploader it's ready so they can return and review it.
            $this->notifier->aiImportReady($import, count($lines));
        } catch (Throwable $e) {
            $import->update([
                'status' => AiImportStatus::Failed,
                'error' => $e->getMessage(),
            ]);

            $this->notifier->aiImportFailed($import);
            report($e);
        }
    }

    private function providerFromModel(?string $model): ?string
    {
        if ($model === null) {
            return null;
        }

        return str_contains($model, '/') ? explode('/', $model, 2)[0] : null;
    }

    /**
     * Existing free-text values to offer the model so it reuses them instead of
     * coining near-duplicates. Tenant-scoped (the job has the tenant bound).
     *
     * @return array<string, list<string>>
     */
    private function vocabularyFor(AiImportType $type): array
    {
        return match ($type) {
            AiImportType::BankStatement => [
                'finance_category' => array_values(array_unique(array_merge(
                    $this->distinct(Cost::class, 'category'),
                    $this->distinct(Inflow::class, 'category'),
                ))),
            ],
            AiImportType::CashInflow => [
                'inflow_category' => $this->distinct(Inflow::class, 'category'),
            ],
            AiImportType::InventoryList => [
                'inventory_unit' => $this->distinct(InventoryItem::class, 'unit'),
            ],
            AiImportType::SupplierList => [
                'supplier_payment_terms' => $this->distinct(Supplier::class, 'payment_terms'),
            ],
            AiImportType::Invoice => [],
        };
    }

    /**
     * Distinct non-empty values of a column for the current tenant (capped).
     *
     * @param  class-string<Model>  $model
     * @return list<string>
     */
    private function distinct(string $model, string $column): array
    {
        $values = $model::query()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->limit(100)
            ->pluck($column)
            ->filter(fn ($v) => is_string($v) && $v !== '')
            ->all();

        return array_values($values);
    }
}
