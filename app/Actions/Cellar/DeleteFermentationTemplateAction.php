<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\FermentationTemplate;
use App\Models\WineLot;

/** Deactivate a template that is in use; otherwise delete it. */
class DeleteFermentationTemplateAction
{
    public function execute(FermentationTemplate $template): void
    {
        $inUse = WineLot::query()
            ->where('fermentation_template_id', $template->getKey())
            ->exists();

        if ($inUse) {
            $template->is_active = false;
            $template->save();

            return;
        }

        $template->delete();
    }
}
