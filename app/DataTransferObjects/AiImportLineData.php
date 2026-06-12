<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\AiImportLine;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final class AiImportLineData implements Arrayable, JsonSerializable
{
    public function __construct(public readonly AiImportLine $line) {}

    public static function fromModel(AiImportLine $line): self
    {
        return new self($line);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $l = $this->line;

        return [
            'id' => $l->getKey(),
            'index' => $l->index,
            'target_type' => $l->target_type->value,
            'target_label' => $l->target_type->label(),
            'category' => $l->category,
            'confidence' => $l->confidence,
            'status' => $l->status->value,
            'status_label' => $l->status->label(),
            // The AI proposal and any user edit are both exposed so the review UI
            // can show/restore the original.
            'payload' => $l->payload,
            'edited_payload' => $l->edited_payload,
            'effective_payload' => $l->effectivePayload(),
            'committed_id' => $l->committed_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
