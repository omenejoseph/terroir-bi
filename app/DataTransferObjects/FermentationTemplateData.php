<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\FermentationTemplate;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final class FermentationTemplateData implements Arrayable, JsonSerializable
{
    public function __construct(public readonly FermentationTemplate $template) {}

    public static function fromModel(FermentationTemplate $template): self
    {
        return new self($template);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $t = $this->template;

        return [
            'id' => $t->getKey(),
            'name' => $t->name,
            'wine_type' => $t->wine_type,
            'yeast_strain' => $t->yeast_strain,
            'target_temp_min' => $t->target_temp_min !== null ? (string) $t->target_temp_min : null,
            'target_temp_max' => $t->target_temp_max !== null ? (string) $t->target_temp_max : null,
            'punchdown_schedule' => $t->punchdown_schedule,
            'maceration' => $t->maceration,
            'nutrients' => $t->nutrients,
            'mlf' => $t->mlf,
            'description' => $t->description,
            'estimated_duration' => $t->estimated_duration,
            'stages' => $t->stages ?? [],
            'is_active' => $t->is_active,
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
