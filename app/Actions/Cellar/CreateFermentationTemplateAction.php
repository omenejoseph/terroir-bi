<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\FermentationTemplate;

/** Create a fermentation protocol template. */
class CreateFermentationTemplateAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data): FermentationTemplate
    {
        return FermentationTemplate::create($this->fields($data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'name', 'wine_type', 'yeast_strain', 'target_temp_min', 'target_temp_max',
            'punchdown_schedule', 'maceration', 'nutrients', 'mlf', 'description',
            'estimated_duration', 'stages', 'is_active',
        ]));
    }
}
