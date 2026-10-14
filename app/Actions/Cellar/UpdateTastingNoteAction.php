<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\CellarTastingNote;

/** Correct a tasting note (score, descriptors, vessel, date). */
class UpdateTastingNoteAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(CellarTastingNote $note, array $data): CellarTastingNote
    {
        $note->fill(array_intersect_key($data, array_flip([
            'vessel_id', 'date', 'appearance', 'nose', 'palate', 'overall', 'score', 'note',
        ])));
        $note->save();

        return $note;
    }
}
