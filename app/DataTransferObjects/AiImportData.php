<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\AiImportLineStatus;
use App\Models\AiImport;
use App\Models\AiImportLine;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final class AiImportData implements Arrayable, JsonSerializable
{
    public function __construct(public readonly AiImport $import) {}

    public static function fromModel(AiImport $import): self
    {
        return new self($import);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $i = $this->import;

        $data = [
            'id' => $i->getKey(),
            'type' => $i->type->value,
            'type_label' => $i->type->label(),
            'status' => $i->status->value,
            'status_label' => $i->status->label(),
            'source_filename' => $i->source_filename,
            'source_mime' => $i->source_mime,
            'provider' => $i->provider,
            'model' => $i->model,
            'prompt_tokens' => $i->prompt_tokens,
            'completion_tokens' => $i->completion_tokens,
            'error' => $i->error,
            'created_at' => $i->created_at?->toIso8601String(),
        ];

        if ($i->relationLoaded('lines')) {
            $lines = $i->lines;
            $data['lines'] = $lines->map(fn (AiImportLine $l) => AiImportLineData::fromModel($l)->toArray())->all();
            $data['lines_total'] = $lines->count();
            $data['lines_pending'] = $lines->where('status', AiImportLineStatus::Pending)->count();
            $data['lines_committed'] = $lines->where('status', AiImportLineStatus::Committed)->count();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
