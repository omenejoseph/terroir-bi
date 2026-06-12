<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\Extractors\BankStatementExtractor;
use App\Services\Ai\Extractors\InventoryListExtractor;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use PHPUnit\Framework\TestCase;

class ExtractorVocabularyTest extends TestCase
{
    /** @param array<string, Type> $def */
    private function json(array $def): string
    {
        return (string) json_encode(array_map(fn ($t) => $t->toArray(), $def));
    }

    public function test_existing_values_are_offered_to_the_model_for_open_fields(): void
    {
        $extractor = (new BankStatementExtractor)->withVocabulary([
            'finance_category' => ['Glassware', 'Rent'],
        ]);

        $json = $this->json($extractor->schema(new JsonSchemaTypeFactory));

        // The category field lists existing values and tells the model to reuse/create.
        $this->assertStringContainsString('Glassware', $json);
        $this->assertStringContainsString('Rent', $json);
        $this->assertStringContainsString('Reuse the exact matching value', $json);
        // Payment method is a closed enum.
        $this->assertStringContainsString('bank_transfer', $json);
    }

    public function test_inventory_category_is_a_closed_enum_and_unit_is_open(): void
    {
        $extractor = (new InventoryListExtractor)->withVocabulary([
            'inventory_unit' => ['bottle', 'case'],
        ]);

        $json = $this->json($extractor->schema(new JsonSchemaTypeFactory));

        // Closed enum: the three inventory categories, no "create".
        $this->assertStringContainsString('FINISHED', $json);
        $this->assertStringContainsString('RAW_MATERIAL', $json);
        // Open unit field carries the existing units.
        $this->assertStringContainsString('bottle', $json);
        $this->assertStringContainsString('case', $json);
    }

    public function test_no_vocabulary_yields_a_generic_hint(): void
    {
        $json = $this->json((new BankStatementExtractor)->schema(new JsonSchemaTypeFactory));

        $this->assertStringContainsString('A short expense or income category', $json);
    }
}
