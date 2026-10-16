<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cellar;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Cellar\UndoStash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The Undo on the toast after a cellar delete, or after skipping or moving
 * a task on Today: puts it back. Today calls it with fetch and gets JSON
 * (410 when it can no longer be undone, 422 with the reason; a retry of one
 * that already worked answers 200 with `already`).
 */
class CellarUndoController extends Controller
{
    public function __invoke(Request $request, string $token, UndoStash $undo, AuditLogger $audit): RedirectResponse|JsonResponse
    {
        $json = $request->expectsJson();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $entry = $undo->take($token, $user->getKey());
        if ($entry === null && $undo->wasUsedBy($token, $user->getKey())) {
            // A retry (Today's offline queue) of an undo that already went through.
            return $json ? response()->json(['data' => ['kind' => null, 'already' => true]]) : back()->with('success', __('Put back.'));
        }
        if ($entry === null) {
            $message = __('That can no longer be undone.');

            return $json ? response()->json(['message' => $message], 410) : back()->with('error', $message);
        }

        try {
            $record = $undo->restore($entry, $user->getKey());
        } catch (ValidationException $e) {
            $undo->giveBack($token, $entry);
            $message = __('Could not undo: :reason', ['reason' => collect($e->errors())->flatten()->first() ?? $e->getMessage()]);

            return $json ? response()->json(['message' => $message], 422) : back()->with('error', $message);
        }

        $audit->record($user, 'cellar.undo', $record, ['kind' => $entry['kind'], 'wine_lot_id' => $entry['lot_id']]);

        return $json ? response()->json(['data' => ['kind' => $entry['kind']]]) : back()->with('success', __('Put back.'));
    }
}
