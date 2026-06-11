<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\BottleAnalysis;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * One bottle/enology analysis row, shaped for the client. Measurements are
 * numbers (nullable when not recorded).
 *
 * @implements Arrayable<string, mixed>
 */
final class BottleAnalysisData implements Arrayable, JsonSerializable
{
    private const MEASUREMENTS = [
        'ph', 'total_acidity', 'volatile_acidity', 'alcohol', 'residual_sugar',
        'free_so2', 'total_so2', 'temperature', 'density', 'tpi',
    ];

    public function __construct(private readonly BottleAnalysis $analysis) {}

    public static function fromModel(BottleAnalysis $analysis): self
    {
        return new self($analysis);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'id' => $this->analysis->id,
            'analyzed_on' => $this->analysis->analyzed_on->toDateString(),
            'note' => $this->analysis->note,
        ];

        foreach (self::MEASUREMENTS as $field) {
            $value = $this->analysis->getAttribute($field);
            $out[$field] = $value === null ? null : (float) $value;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
