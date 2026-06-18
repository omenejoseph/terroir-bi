<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\CellarTastingNote;
use App\Models\WineLot;

/** Record a tasting note for a lot. */
class AddTastingNoteAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(WineLot $lot, array $data, string $createdById): CellarTastingNote
    {
        /** @var CellarTastingNote $note */
        $note = $lot->tastingNotes()->create([
            'vessel_id' => $data['vessel_id'] ?? null,
            'tasting_report_id' => $data['tasting_report_id'] ?? null,
            'created_by_id' => $createdById,
            'date' => $data['date'] ?? now(),
            'appearance' => $data['appearance'] ?? null,
            'nose' => $data['nose'] ?? null,
            'palate' => $data['palate'] ?? null,
            'overall' => $data['overall'] ?? null,
            'score' => isset($data['score']) ? (int) $data['score'] : null,
            'note' => $data['note'] ?? null,
        ]);

        return $note;
    }
}
