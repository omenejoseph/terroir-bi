<?php

declare(strict_types=1);

namespace App\Services\Ai\Extractors;

use App\Enums\AiCapability;
use App\Enums\AiImportType;
use App\Enums\AiTargetType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Base for the per-document-type extractors. Each subclass is BOTH a Laravel AI
 * structured-output Agent (instructions + JSON schema) and the mapper that turns
 * the model's structured result into proposed ai_import_lines. Keeping both on
 * one class keeps everything about a document type in one place — and makes the
 * agent fakeable per-class in tests.
 */
abstract class DocumentExtractor implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable;

    /**
     * Known values for open (free-text) fields, keyed by a logical field name
     * (e.g. 'finance_category', 'inventory_unit'). Fed in per-tenant by the
     * ExtractionService so the model reuses existing values instead of coining
     * near-duplicates. Set before prompting; read by schema()/userPrompt().
     *
     * @var array<string, list<string>>
     */
    protected array $vocabulary = [];

    abstract public function type(): AiImportType;

    /** The user-turn prompt sent alongside the attached document. */
    abstract public function userPrompt(): string;

    /**
     * @param  array<string, list<string>>  $vocabulary
     */
    public function withVocabulary(array $vocabulary): static
    {
        $this->vocabulary = $vocabulary;

        return $this;
    }

    /** {@inheritDoc} */
    abstract public function schema(JsonSchema $schema): array;

    /**
     * Map the validated structured output to proposed lines.
     *
     * @param  array<string, mixed>  $structured
     * @return list<array{target_type: AiTargetType, payload: array<string, mixed>, category: string|null, confidence: float|null}>
     */
    abstract public function mapToLines(array $structured): array;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
        You are a meticulous bookkeeping data-entry assistant for a beverage
        distribution business. Extract the requested data exactly as it appears
        in the attached document. Rules:
        - Use ISO 8601 dates (YYYY-MM-DD).
        - Amounts are decimal numbers in the document's own currency (e.g. 1234.56),
          never formatted with thousands separators or currency symbols.
        - Never invent values. If a field is absent, omit it or use null.
        - Use normal, readable capitalisation for free-text values such as
          descriptions and company names. If the source document is ALL-CAPS,
          convert it to sentence/Title case; keep genuine acronyms (e.g. USA,
          LTD, VAT, USD, IBAN) capitalised.
        - Give each extracted row a confidence between 0 and 1 reflecting how
          certain you are it was read correctly.
        TXT;
    }

    public function messages(): iterable
    {
        return [];
    }

    public function tools(): iterable
    {
        return [];
    }

    public function capability(): AiCapability
    {
        return AiCapability::Vision;
    }

    /** Tag used for spend attribution and usage logging. */
    public function feature(): string
    {
        return 'ai_import:'.$this->type()->value;
    }

    /** Convert a major-unit decimal amount to integer minor units (assumes scale 2). */
    protected function minor(mixed $major): int
    {
        return (int) round(((float) $major) * 100);
    }

    protected function clampConfidence(mixed $confidence): ?float
    {
        if ($confidence === null) {
            return null;
        }

        return max(0.0, min(1.0, (float) $confidence));
    }

    /**
     * Field description for an OPEN value set: lists the values already in use
     * and tells the model to reuse an exact match or coin a new one if none fit.
     */
    protected function selectOrCreate(string $noun, string $vocabularyKey): string
    {
        $options = $this->vocabulary[$vocabularyKey] ?? [];

        if ($options === []) {
            return "A short {$noun}.";
        }

        return "A {$noun}. Values already used by this business: ".implode('; ', $options)
            .'. Reuse the exact matching value when one fits; only use a new value if none of these apply.';
    }
}
