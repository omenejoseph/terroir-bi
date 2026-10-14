<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\WineLot;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;

/** Link or unlink a fermentation protocol on a lot. */
class AssignFermentationTemplateAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(WineLot $lot, ?string $templateId): WineLot
    {
        $from = $lot->fermentation_template_id;
        $lot->fermentation_template_id = $templateId;
        $lot->save();

        $this->audit->record(Auth::user(), 'wine_lot.protocol_assigned', $lot, ['from' => $from, 'to' => $templateId]);

        return $lot;
    }
}
