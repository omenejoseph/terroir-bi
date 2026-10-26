<?php

declare(strict_types=1);

namespace App\Actions\Support;

use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use App\Models\SupportTicket;

/**
 * Triage by a platform admin — status, priority, assignee — or the requester
 * marking their own ticket resolved. `resolved_at` follows the status.
 */
class UpdateSupportTicketAction
{
    /**
     * @param  array{status?: string, priority?: string, assignee_id?: string|null}  $changes
     */
    public function execute(SupportTicket $ticket, array $changes): SupportTicket
    {
        if (isset($changes['status'])) {
            $ticket->status = SupportTicketStatus::from($changes['status']);
            $ticket->resolved_at = $ticket->status->isActive() ? null : ($ticket->resolved_at ?? now());
        }

        if (isset($changes['priority'])) {
            $ticket->priority = SupportTicketPriority::from($changes['priority']);
        }

        if (array_key_exists('assignee_id', $changes)) {
            $ticket->assignee_id = $changes['assignee_id'];
        }

        $ticket->save();

        return $ticket;
    }
}
