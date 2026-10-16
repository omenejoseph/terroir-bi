<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cellar;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Cellar\UndoStash;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The Undo on the toast after a cellar delete: puts the record back.
 */
class CellarUndoController extends Controller
{
    public function __invoke(Request $request, string $token, UndoStash $undo, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $entry = $undo->take($token, $user->getKey());
        if ($entry === null) {
            return back()->with('error', __('That can no longer be undone.'));
        }

        try {
            $record = $undo->restore($entry, $user->getKey());
        } catch (ValidationException $e) {
            $undo->giveBack($token, $entry);

            return back()->with('error', __('Could not undo: :reason', ['reason' => collect($e->errors())->flatten()->first() ?? $e->getMessage()]));
        }

        $audit->record($user, 'cellar.undo', $record, ['kind' => $entry['kind'], 'wine_lot_id' => $entry['lot_id']]);

        return back()->with('success', __('Put back.'));
    }
}
