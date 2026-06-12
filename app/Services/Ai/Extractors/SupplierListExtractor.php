<?php

declare(strict_types=1);

namespace App\Services\Ai\Extractors;

use App\Enums\AiImportType;
use App\Enums\AiTargetType;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Parses a supplier list / directory into supplier records.
 */
class SupplierListExtractor extends DocumentExtractor
{
    public function type(): AiImportType
    {
        return AiImportType::SupplierList;
    }

    public function userPrompt(): string
    {
        return 'Extract every supplier/vendor from this document with their '
            .'contact and tax details.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'suppliers' => $schema->array()->items(
                $schema->object(fn (JsonSchema $s) => [
                    'company_name' => $s->string()->required(),
                    'contact_name' => $s->string()->nullable(),
                    'email' => $s->string()->nullable(),
                    'phone' => $s->string()->nullable(),
                    'address' => $s->string()->nullable(),
                    'city' => $s->string()->nullable(),
                    'country' => $s->string()->nullable(),
                    'tax_id' => $s->string()->nullable(),
                    'payment_terms' => $s->string()->nullable()->description($this->selectOrCreate('payment terms', 'supplier_payment_terms')),
                    'confidence' => $s->number()->min(0)->max(1)->nullable(),
                ])
            )->required(),
        ];
    }

    public function mapToLines(array $structured): array
    {
        $lines = [];

        foreach ($structured['suppliers'] ?? [] as $row) {
            $lines[] = [
                'target_type' => AiTargetType::Supplier,
                'category' => null,
                'confidence' => $this->clampConfidence($row['confidence'] ?? null),
                'payload' => array_filter([
                    'company_name' => $row['company_name'] ?? null,
                    'contact_name' => $row['contact_name'] ?? null,
                    'email' => $row['email'] ?? null,
                    'phone' => $row['phone'] ?? null,
                    'address' => $row['address'] ?? null,
                    'city' => $row['city'] ?? null,
                    'country' => $row['country'] ?? null,
                    'tax_id' => $row['tax_id'] ?? null,
                    'payment_terms' => $row['payment_terms'] ?? null,
                    'is_active' => true,
                ], fn ($v) => $v !== null),
            ];
        }

        return $lines;
    }
}
