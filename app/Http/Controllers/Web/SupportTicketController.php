<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Support\CreateSupportTicketAction;
use App\Actions\Support\ReplyToSupportTicketAction;
use App\Actions\Support\UpdateSupportTicketAction;
use App\Authorization\MembershipContext;
use App\Enums\SupportTicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Support\ReplySupportTicketRequest;
use App\Http\Requests\Support\StoreSupportTicketRequest;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Support\SupportTicketPresenter;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Help & support" for members of a tenant: raise a ticket with the platform
 * team, follow the conversation, reply, and mark it resolved.
 *
 * Tickets are central (SupportTicket is not tenant-scoped), so every lookup
 * here narrows by the current tenant itself. Members see the tickets they
 * raised; whoever can manage the organisation's settings sees all of the
 * tenant's tickets. Staff-only internal notes are never sent to this side.
 */
class SupportTicketController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly MembershipContext $membership,
        private readonly SupportTicketPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $tickets = $this->visible($this->user($request))
            ->with(['requester', 'assignee'])
            ->orderByRaw('CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END', [SupportTicketStatus::Resolved->value, SupportTicketStatus::Closed->value])
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

        return Inertia::render('Support/Index', [
            'tickets' => array_values($tickets->map(fn (SupportTicket $t): array => $this->presenter->row($t))->all()),
            'seesAll' => $this->membership->can('settings.manage'),
        ]);
    }

    public function store(StoreSupportTicketRequest $request, CreateSupportTicketAction $action): RedirectResponse
    {
        /** @var array{subject: string, category: string, body: string, page_url?: string|null} $data */
        $data = $request->validated();

        $ticket = $action->execute($this->tenant(), $this->user($request), $data);

        return redirect('/support/'.$ticket->getKey())
            ->with('success', __('Thanks — ticket #:number is with our support team. We\'ll reply here and by email.', ['number' => $ticket->number]));
    }

    public function show(Request $request, string $ticket): Response
    {
        $model = $this->find($request, $ticket);
        $model->load(['requester', 'assignee', 'messages.author']);

        return Inertia::render('Support/Show', [
            'ticket' => $this->presenter->detail($model, includeInternal: false),
        ]);
    }

    public function reply(ReplySupportTicketRequest $request, string $ticket, ReplyToSupportTicketAction $action): RedirectResponse
    {
        $model = $this->find($request, $ticket);

        abort_if($model->status === SupportTicketStatus::Closed, 422, __('This ticket is closed. Open a new one if you still need help.'));

        $action->execute($model, $this->user($request), (string) $request->validated('body'), staff: false);

        return back();
    }

    /** The requester (or an organisation admin) says the problem is solved. */
    public function resolve(Request $request, string $ticket, UpdateSupportTicketAction $action): RedirectResponse
    {
        $model = $this->find($request, $ticket);

        if ($model->status->isActive()) {
            $action->execute($model, ['status' => SupportTicketStatus::Resolved->value]);
        }

        return back()->with('success', __('Marked as resolved. Reply any time to reopen it.'));
    }

    /**
     * @return Builder<SupportTicket>
     */
    private function visible(User $user): Builder
    {
        return SupportTicket::query()
            ->forTenant($this->tenants->id())
            ->when(! $this->membership->can('settings.manage'), fn ($q) => $q->where('requester_id', $user->getKey()));
    }

    /** One of this tenant's tickets the member may see — 404 otherwise, never revealing another tenant's. */
    private function find(Request $request, string $id): SupportTicket
    {
        $ticket = $this->visible($this->user($request))->whereKey($id)->first();
        abort_unless($ticket instanceof SupportTicket, 404);

        return $ticket;
    }

    private function tenant(): Tenant
    {
        $tenant = $this->tenants->current();
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
