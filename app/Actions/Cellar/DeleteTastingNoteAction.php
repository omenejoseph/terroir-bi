<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\CellarTastingNote;

/** Delete a tasting note. */
class DeleteTastingNoteAction
{
    public function execute(CellarTastingNote $note): void
    {
        $note->delete();
    }
}
