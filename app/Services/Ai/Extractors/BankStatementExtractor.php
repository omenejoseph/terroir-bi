<?php

declare(strict_types=1);

namespace App\Services\Ai\Extractors;

use App\Enums\AiImportType;
use App\Enums\AiTargetType;
use App\Enums\PaymentMethod;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Parses a bank statement into a mix of costs (money out) and inflows (money in),
 * one proposed line per transaction.
 */
class BankStatementExtractor extends DocumentExtractor
{
    public function type(): AiImportType
    {
        return AiImportType::BankStatement;
    }

    public function userPrompt(): string
    {
        return 'Extract every transaction from this bank statement. For each, '
            .'give the date, a description, the amount, whether money came in or '
            .'went out, a short expense/income category, and any reference.';
    }

    public function schema(JsonSchema $schema): array
    {
        $paymentMethods = array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::cases());
        $categoryHint = $this->selectOrCreate('expense or income category', 'finance_category');

        return [
            'transactions' => $schema->array()->items(
                $schema->object(fn (JsonSchema $s) => [
                    'date' => $s->string()->format('date')->required(),
                    'description' => $s->string()->required(),
                    'amount' => $s->number()->min(0)->required()->description('Absolute value, no sign.'),
                    'direction' => $s->string()->enum(['in', 'out'])->required(),
                    'category' => $s->string()->nullable()->description($categoryHint),
                    'payment_method' => $s->string()->enum($paymentMethods)->nullable()->description('Pick one if evident from the line.'),
                    'reference' => $s->string()->nullable(),
                    'counterparty' => $s->string()->nullable(),
                    'confidence' => $s->number()->min(0)->max(1)->nullable(),
                ])
            )->required(),
        ];
    }

    public function mapToLines(array $structured): array
    {
        $lines = [];

        foreach ($structured['transactions'] ?? [] as $tx) {
            $confidence = $this->clampConfidence($tx['confidence'] ?? null);
            $amountMinor = $this->minor($tx['amount'] ?? 0);
            $category = $tx['category'] ?? null;
            $paymentMethod = $tx['payment_method'] ?? null;

            if (($tx['direction'] ?? 'out') === 'in') {
                $lines[] = [
                    'target_type' => AiTargetType::Inflow,
                    'category' => $category,
                    'confidence' => $confidence,
                    'payload' => array_filter([
                        'date' => $tx['date'] ?? null,
                        'amount' => $amountMinor,
                        'status' => 'PENDING',
                        'category' => $category,
                        'payment_method' => $paymentMethod,
                        'reference' => $tx['reference'] ?? null,
                        'notes' => $tx['counterparty'] ?? ($tx['description'] ?? null),
                    ], fn ($v) => $v !== null),
                ];
            } else {
                $lines[] = [
                    'target_type' => AiTargetType::Cost,
                    'category' => $category,
                    'confidence' => $confidence,
                    'payload' => array_filter([
                        'date' => $tx['date'] ?? null,
                        'total_amount' => $amountMinor,
                        'status' => 'PENDING',
                        'category' => $category ?? 'uncategorised',
                        'payment_method' => $paymentMethod,
                        'description' => $tx['description'] ?? null,
                        'reference' => $tx['reference'] ?? null,
                    ], fn ($v) => $v !== null),
                ];
            }
        }

        return $lines;
    }
}
