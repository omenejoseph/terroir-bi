<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\Vessel;

/** Rename a cellar room across every vessel currently in it. */
class RenameCellarRoomAction
{
    public function execute(string $from, string $to): int
    {
        return Vessel::query()->where('room', $from)->update(['room' => $to]);
    }
}
