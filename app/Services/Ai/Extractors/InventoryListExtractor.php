<?php

declare(strict_types=1);

namespace App\Services\Ai\Extractors;

use App\Enums\AiImportType;
use App\Enums\AiTargetType;
use App\Enums\InventoryCategory;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Parses a product / inventory list into inventory item records.
 */
class InventoryListExtractor extends DocumentExtractor
{
    public function type(): AiImportType
    {
        return AiImportType::InventoryList;
    }

    public function userPrompt(): string
    {
        return 'Extract every product/inventory line from this document: name, '
            .'SKU, category, selling price and unit cost where shown, and current '
            .'stock quantity if present.';
    }

    public function schema(JsonSchema $schema): array
    {
        $categories = array_map(fn (InventoryCategory $c) => $c->value, InventoryCategory::cases());
        $unitHint = $this->selectOrCreate('unit of measure (e.g. bottle, case, kg, L)', 'inventory_unit');

        return [
            'items' => $schema->array()->items(
                $schema->object(fn (JsonSchema $s) => [
                    'name' => $s->string()->required(),
                    'sku' => $s->string()->nullable(),
                    // Closed set: a product is finished goods, semi-finished, or raw material.
                    'category' => $s->string()->enum($categories)->nullable()
                        ->description('FINISHED = sellable product, SEMI_FINISHED = intermediate, RAW_MATERIAL = input.'),
                    'unit' => $s->string()->nullable()->description($unitHint),
                    'default_price' => $s->number()->min(0)->nullable()->description('Selling price.'),
                    'cost_per_unit' => $s->number()->min(0)->nullable(),
                    'current_stock' => $s->number()->min(0)->nullable(),
                    'confidence' => $s->number()->min(0)->max(1)->nullable(),
                ])
            )->required(),
        ];
    }

    public function mapToLines(array $structured): array
    {
        $lines = [];

        foreach ($structured['items'] ?? [] as $row) {
            $payload = array_filter([
                'name' => $row['name'] ?? null,
                'sku' => $row['sku'] ?? null,
                'category' => $row['category'] ?? null,
                'unit' => $row['unit'] ?? null,
                'is_active' => true,
            ], fn ($v) => $v !== null);

            // Money columns only when present (kept as minor units).
            if (isset($row['default_price'])) {
                $payload['default_price'] = $this->minor($row['default_price']);
            }
            if (isset($row['cost_per_unit'])) {
                $payload['cost_per_unit'] = $this->minor($row['cost_per_unit']);
            }
            if (isset($row['current_stock'])) {
                $payload['current_stock'] = (string) $row['current_stock'];
            }

            $lines[] = [
                'target_type' => AiTargetType::InventoryItem,
                'category' => $row['category'] ?? null,
                'confidence' => $this->clampConfidence($row['confidence'] ?? null),
                'payload' => $payload,
            ];
        }

        return $lines;
    }
}
