<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Actions\Support\ReplyToSupportTicketAction;
use App\Actions\Support\UpdateSupportTicketAction;
use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Support\ReplySupportTicketRequest;
use App\Http\Requests\Support\UpdateSupportTicketRequest;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Support\SupportTicketPresenter;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform team's support queue (/admin/support): every tenant's tickets
 * in one list, filtered by status ("active" by default), assignee, priority
 * or a search over number, subject and tenant. A ticket page holds the whole
 * conversation, internal notes included, and the triage controls.
 */
class SupportTicketController extends Controller
{
    public function __construct(private readonly SupportTicketPresenter $presenter) {}

    public function index(Request $request): Response
    {
        $status = (string) $request->query('status', 'active');
        $assignee = (string) $request->query('assignee', '');
        $priority = (string) $request->query('priority', '');
        $search = trim((string) $request->query('search', ''));

        $tickets = SupportTicket::query()
            ->with(['tenant', 'requester', 'assignee'])
            ->when($status === 'active', fn ($q) => $q->whereNotIn('status', [SupportTicketStatus::Resolved->value, SupportTicketStatus::Closed->value]))
            ->when(in_array($status, SupportTicketStatus::values(), true), fn ($q) => $q->where('status', $status))
            ->when($assignee === 'me', fn ($q) => $q->where('assignee_id', $request->user()?->getKey()))
            ->when($assignee === 'none', fn ($q) => $q->whereNull('assignee_id'))
            ->when(in_array($priority, SupportTicketPriority::values(), true), fn ($q) => $q->where('priority', $priority))
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search): void {
                $q->whereLike('subject', "%{$search}%")
                    ->orWhereHas('tenant', fn ($t) => $t->whereLike('name', "%{$search}%"));

                if (ctype_digit(ltrim($search, '#'))) {
                    $q->orWhere('number', (int) ltrim($search, '#'));
                }
            }))
            // Urgent first, then whatever waited longest since its last message.
            ->orderByRaw('CASE priority WHEN ? THEN 0 WHEN ? THEN 1 WHEN ? THEN 2 ELSE 3 END', ['urgent', 'high', 'normal'])
            ->orderBy('last_message_at')
            ->paginate(PerPage::fromRequest($request));

        return Inertia::render('Admin/Support/Index', [
            'tickets' => [
                'data' => array_map(fn (SupportTicket $t): array => $this->presenter->row($t), $tickets->items()),
                'meta' => [
                    'current_page' => $tickets->currentPage(),
                    'last_page' => $tickets->lastPage(),
                    'per_page' => $tickets->perPage(),
                    'total' => $tickets->total(),
                ],
            ],
            'filters' => ['status' => $status, 'assignee' => $assignee, 'priority' => $priority, 'search' => $search],
            'counts' => [
                'active' => SupportTicket::query()->whereNotIn('status', [SupportTicketStatus::Resolved->value, SupportTicketStatus::Closed->value])->count(),
                'unassigned' => SupportTicket::query()->whereNull('assignee_id')->whereNotIn('status', [SupportTicketStatus::Resolved->value, SupportTicketStatus::Closed->value])->count(),
            ],
        ]);
    }

    public function show(SupportTicket $ticket): Response
    {
        $ticket->load(['tenant', 'requester', 'assignee', 'messages.author']);

        return Inertia::render('Admin/Support/Show', [
            'ticket' => $this->presenter->detail($ticket, includeInternal: true),
            'admins' => array_values(User::query()
                ->where('is_platform_admin', true)
                ->orderBy('first_name')
                ->get()
                ->map(fn (User $u): array => ['value' => (string) $u->getKey(), 'label' => $u->fullName()])
                ->all()),
        ]);
    }

    public function reply(ReplySupportTicketRequest $request, SupportTicket $ticket, ReplyToSupportTicketAction $action): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $action->execute($ticket, $user, (string) $request->validated('body'), staff: true, internal: $request->boolean('internal'));

        return back()->with('success', $request->boolean('internal') ? __('Note added.') : __('Reply sent.'));
    }

    public function update(UpdateSupportTicketRequest $request, SupportTicket $ticket, UpdateSupportTicketAction $action): RedirectResponse
    {
        /** @var array{status?: string, priority?: string, assignee_id?: string|null} $changes */
        $changes = $request->validated();

        $action->execute($ticket, $changes);

        return back()->with('success', __('Ticket updated.'));
    }
}
