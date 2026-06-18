<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\WineLot;

/** Link or unlink a fermentation protocol on a lot. */
class AssignFermentationTemplateAction
{
    public function execute(WineLot $lot, ?string $templateId): WineLot
    {
        $lot->fermentation_template_id = $templateId;
        $lot->save();

        return $lot;
    }
}
