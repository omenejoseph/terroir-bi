<?php

declare(strict_types=1);

namespace App\Services\Ai\Extractors;

use App\Enums\AiImportType;
use App\Enums\AiTargetType;
use App\Enums\PaymentMethod;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Parses a document of incoming payments into inflow (money-in) lines.
 */
class CashInflowExtractor extends DocumentExtractor
{
    public function type(): AiImportType
    {
        return AiImportType::CashInflow;
    }

    public function userPrompt(): string
    {
        return 'Extract every incoming payment from this document. For each give '
            .'the date, amount received, payer, a category, and any reference.';
    }

    public function schema(JsonSchema $schema): array
    {
        $paymentMethods = array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::cases());
        $categoryHint = $this->selectOrCreate('income category', 'inflow_category');

        return [
            'inflows' => $schema->array()->items(
                $schema->object(fn (JsonSchema $s) => [
                    'date' => $s->string()->format('date')->required(),
                    'amount' => $s->number()->min(0)->required(),
                    'payer' => $s->string()->nullable(),
                    'category' => $s->string()->nullable()->description($categoryHint),
                    'payment_method' => $s->string()->enum($paymentMethods)->nullable(),
                    'reference' => $s->string()->nullable(),
                    'confidence' => $s->number()->min(0)->max(1)->nullable(),
                ])
            )->required(),
        ];
    }

    public function mapToLines(array $structured): array
    {
        $lines = [];

        foreach ($structured['inflows'] ?? [] as $row) {
            $lines[] = [
                'target_type' => AiTargetType::Inflow,
                'category' => $row['category'] ?? null,
                'confidence' => $this->clampConfidence($row['confidence'] ?? null),
                'payload' => array_filter([
                    'date' => $row['date'] ?? null,
                    'amount' => $this->minor($row['amount'] ?? 0),
                    'status' => 'PENDING',
                    'category' => $row['category'] ?? null,
                    'payment_method' => $row['payment_method'] ?? null,
                    'reference' => $row['reference'] ?? null,
                    'notes' => $row['payer'] ?? null,
                ], fn ($v) => $v !== null),
            ];
        }

        return $lines;
    }
}
