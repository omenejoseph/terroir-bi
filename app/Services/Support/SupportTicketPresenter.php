<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;

/**
 * The array shapes the support pages render, shared by the tenant side
 * (Web\SupportTicketController) and the admin side
 * (Web\Admin\SupportTicketController). The tenant never gets internal notes.
 */
class SupportTicketPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function row(SupportTicket $ticket): array
    {
        return [
            'id' => (string) $ticket->getKey(),
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'category' => $ticket->category->value,
            'priority' => $ticket->priority->value,
            'status' => $ticket->status->value,
            'tenant' => $ticket->relationLoaded('tenant') ? ['id' => (string) $ticket->tenant_id, 'name' => $ticket->tenant?->name] : null,
            'requester' => $ticket->requester?->fullName(),
            'assignee' => $ticket->assignee?->fullName(),
            'assignee_id' => $ticket->assignee_id,
            'last_message_at' => $ticket->last_message_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(SupportTicket $ticket, bool $includeInternal): array
    {
        $messages = $ticket->messages
            ->filter(fn (SupportTicketMessage $m): bool => $includeInternal || ! $m->is_internal)
            ->map(fn (SupportTicketMessage $m): array => [
                'id' => (string) $m->getKey(),
                'body' => $m->body,
                'author' => $m->author?->fullName(),
                'is_staff' => $m->is_staff,
                'is_internal' => $m->is_internal,
                'created_at' => $m->created_at?->toIso8601String(),
            ]);

        return $this->row($ticket) + [
            'page_url' => $ticket->page_url,
            'requester_email' => $ticket->requester?->email,
            'messages' => array_values($messages->all()),
        ];
    }
}
