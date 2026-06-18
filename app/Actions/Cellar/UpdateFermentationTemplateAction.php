<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\FermentationTemplate;

/** Update a fermentation protocol template. */
class UpdateFermentationTemplateAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(FermentationTemplate $template, array $data): FermentationTemplate
    {
        $template->fill(array_intersect_key($data, array_flip([
            'name', 'wine_type', 'yeast_strain', 'target_temp_min', 'target_temp_max',
            'punchdown_schedule', 'maceration', 'nutrients', 'mlf', 'description',
            'estimated_duration', 'stages', 'is_active',
        ])));
        $template->save();

        return $template;
    }
}
