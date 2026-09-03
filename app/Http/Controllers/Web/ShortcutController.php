<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Shortcuts\ClearRecentVisitsAction;
use App\Actions\Shortcuts\SetPinnedShortcutsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shortcuts\UpdateShortcutsRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Manage Shortcuts (Figma `143:4179`): saving pins and clearing visit history. */
class ShortcutController extends Controller
{
    public function update(UpdateShortcutsRequest $request, SetPinnedShortcutsAction $action): RedirectResponse
    {
        /** @var list<string> $keys */
        $keys = array_values((array) $request->validated('keys'));
        $action->execute($this->user($request), $keys);

        return back();
    }

    public function clearRecent(Request $request, ClearRecentVisitsAction $action): RedirectResponse
    {
        $action->execute($this->user($request));

        return back();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
