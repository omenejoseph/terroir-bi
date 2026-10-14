<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\FermentationTemplate;
use App\Services\Cellar\SyncProtocolAdditives;

/** Update a fermentation protocol template. */
class UpdateFermentationTemplateAction
{
    public function __construct(private readonly SyncProtocolAdditives $additives) {}

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

        if (array_key_exists('additives', $data)) {
            $this->additives->sync($template, (array) $data['additives']);
        }

        return $template;
    }
}
