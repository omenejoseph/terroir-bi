<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DataTransferObjects\TranslationOverrideData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a TranslationOverrideData DTO for API output. The DTO (not the model)
 * is the source of truth, so the API and any Livewire/Inertia view stay in sync.
 *
 * @property TranslationOverrideData $resource
 */
class TranslationOverrideResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
