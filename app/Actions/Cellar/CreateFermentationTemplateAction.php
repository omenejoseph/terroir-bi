<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\FermentationTemplate;
use App\Services\Cellar\SyncProtocolAdditives;
use Illuminate\Support\Facades\DB;

/** Create a fermentation protocol template. */
class CreateFermentationTemplateAction
{
    public function __construct(private readonly SyncProtocolAdditives $additives) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data): FermentationTemplate
    {
        return DB::transaction(function () use ($data): FermentationTemplate {
            $template = FermentationTemplate::create($this->fields($data));
            if (array_key_exists('additives', $data)) {
                $this->additives->sync($template, (array) $data['additives']);
            }

            return $template;
        });
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
