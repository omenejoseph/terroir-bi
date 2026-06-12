<?php

declare(strict_types=1);

namespace App\Services\Ai\Extractors;

use App\Enums\AiImportType;
use App\Enums\AiTargetType;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Parses an invoice/PDF into a single proposed order (with line items). Customer
 * resolution happens at commit time (matched by name or created).
 */
class InvoiceExtractor extends DocumentExtractor
{
    public function type(): AiImportType
    {
        return AiImportType::Invoice;
    }

    public function userPrompt(): string
    {
        return 'Extract this invoice as an order: the customer name, an order/'
            .'invoice reference, the order date, any notes, and every line item '
            .'with its description, SKU, quantity and unit price.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'customer_name' => $schema->string()->nullable(),
            'reference' => $schema->string()->nullable(),
            'order_date' => $schema->string()->format('date')->nullable(),
            'notes' => $schema->string()->nullable(),
            'confidence' => $schema->number()->min(0)->max(1)->nullable(),
            'items' => $schema->array()->items(
                $schema->object(fn (JsonSchema $s) => [
                    'description' => $s->string()->required(),
                    'sku' => $s->string()->nullable(),
                    'quantity' => $s->number()->min(0)->required(),
                    'unit_price' => $s->number()->min(0)->required(),
                ])
            )->required(),
        ];
    }

    public function mapToLines(array $structured): array
    {
        $items = [];
        foreach ($structured['items'] ?? [] as $item) {
            $items[] = array_filter([
                'description' => $item['description'] ?? null,
                'sku' => $item['sku'] ?? null,
                'quantity' => (int) round((float) ($item['quantity'] ?? 1)),
                'unit_price' => $this->minor($item['unit_price'] ?? 0),
            ], fn ($v) => $v !== null);
        }

        // One import → one proposed order line carrying the whole order.
        return [[
            'target_type' => AiTargetType::Order,
            'category' => null,
            'confidence' => $this->clampConfidence($structured['confidence'] ?? null),
            'payload' => array_filter([
                'customer_name' => $structured['customer_name'] ?? null,
                'reference' => $structured['reference'] ?? null,
                'order_date' => $structured['order_date'] ?? null,
                'notes' => $structured['notes'] ?? null,
                'status' => 'RECEIVED',
                'items' => $items,
            ], fn ($v) => $v !== null && $v !== []),
        ]];
    }
}
