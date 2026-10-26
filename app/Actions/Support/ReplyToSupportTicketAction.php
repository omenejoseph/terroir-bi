<?php

declare(strict_types=1);

namespace App\Actions\Support;

use App\Enums\NotificationType;
use App\Enums\SupportTicketStatus;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Notifications\SupportTicketReplyNotification;
use App\Services\Notifications\Notifier;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Adds a message to a ticket and moves it along:
 *
 *  - a staff reply puts the ticket in "waiting on customer" and tells the
 *    requester (in-app notification + email);
 *  - a customer reply on a waiting or resolved ticket reopens it and emails
 *    the assigned admin, if any;
 *  - an internal note (staff only) changes nothing and notifies no one.
 */
class ReplyToSupportTicketAction
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly TenantContext $tenants,
    ) {}

    public function execute(SupportTicket $ticket, User $author, string $body, bool $staff, bool $internal = false): SupportTicketMessage
    {
        $internal = $staff && $internal;

        $message = DB::transaction(function () use ($ticket, $author, $body, $staff, $internal): SupportTicketMessage {
            $message = $ticket->messages()->create([
                'author_id' => $author->getKey(),
                'body' => trim($body),
                'is_staff' => $staff,
                'is_internal' => $internal,
            ]);

            if (! $internal) {
                $ticket->last_message_at = now();

                if ($staff) {
                    $ticket->status = SupportTicketStatus::WaitingOnCustomer;
                } elseif (in_array($ticket->status, [SupportTicketStatus::WaitingOnCustomer, SupportTicketStatus::Resolved], true)) {
                    $ticket->status = SupportTicketStatus::Open;
                    $ticket->resolved_at = null;
                }

                $ticket->save();
            }

            return $message;
        });

        if (! $internal) {
            $staff ? $this->tellRequester($ticket, $body) : $this->tellAssignee($ticket, $body);
        }

        return $message;
    }

    private function tellRequester(SupportTicket $ticket, string $body): void
    {
        $requester = $ticket->requester;
        $tenant = $ticket->tenant;

        if ($requester === null || $tenant === null) {
            return;
        }

        // The bell lives inside the tenant, so the notification is written in its scope.
        $this->tenants->runFor($tenant, fn () => $this->notifier->notifyMany(
            [$requester->getKey()],
            NotificationType::SupportReply,
            __('Support replied: :subject', ['subject' => $ticket->subject]),
            Str::limit(trim($body), 140),
            ['ticket_id' => (string) $ticket->getKey()],
            null,
        ));

        $this->mail($requester, $ticket, $body, url('/support/'.$ticket->getKey()));
    }

    private function tellAssignee(SupportTicket $ticket, string $body): void
    {
        if ($ticket->assignee !== null) {
            $this->mail($ticket->assignee, $ticket, $body, url('/admin/support/'.$ticket->getKey()));
        }
    }

    private function mail(User $to, SupportTicket $ticket, string $body, string $url): void
    {
        try {
            $to->notify(new SupportTicketReplyNotification($ticket, trim($body), $url));
        } catch (Throwable $e) {
            // The reply is saved and visible in the app; a mail outage must not undo it.
            report($e);
        }
    }
}
