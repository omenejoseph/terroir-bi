<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Actions\Notifications\SendBroadcastAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Broadcast\StoreBroadcastRequest;
use App\Queries\ListTenantOptionsQuery;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Port of App\Filament\Pages\Broadcast: compose and send a platform-wide (or
 * tenant-scoped) announcement. No history/log page — Filament's had none
 * either, just the compose action.
 */
class BroadcastController extends Controller
{
    public function index(ListTenantOptionsQuery $tenants): Response
    {
        return Inertia::render('Admin/Broadcast/Index', [
            'tenantOptions' => collect($tenants->options())
                ->map(fn (string $name, string $id): array => ['value' => $id, 'label' => $name])
                ->values(),
        ]);
    }

    public function store(StoreBroadcastRequest $request, SendBroadcastAction $action): RedirectResponse
    {
        $data = $request->validated();
        $tenantIds = array_values($data['tenants'] ?? []);

        $result = $action->execute(
            $data['title'],
            ($data['body'] ?? '') !== '' ? $data['body'] : null,
            $tenantIds === [] ? null : $tenantIds,
        );

        return back()->with('success', __(':recipients recipients across :tenants tenant(s).', [
            'recipients' => $result['recipients'],
            'tenants' => $result['tenants'],
        ]));
    }
}
