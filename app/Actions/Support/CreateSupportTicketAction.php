<?php

declare(strict_types=1);

namespace App\Actions\Support;

use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketStatus;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\NewSupportTicketNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Someone in a tenant asks the platform team for help: the ticket, its first
 * message, and an email to every platform admin.
 */
class CreateSupportTicketAction
{
    /** Numbers start above this so the first ticket doesn't read "#1". */
    private const FIRST_NUMBER = 1000;

    /**
     * @param  array{subject: string, category: string, body: string, page_url?: string|null}  $data
     */
    public function execute(Tenant $tenant, User $requester, array $data): SupportTicket
    {
        $ticket = DB::transaction(function () use ($tenant, $requester, $data): SupportTicket {
            $last = SupportTicket::query()->lockForUpdate()->max('number');

            $ticket = SupportTicket::create([
                'number' => max((int) $last, self::FIRST_NUMBER) + 1,
                'tenant_id' => $tenant->getKey(),
                'requester_id' => $requester->getKey(),
                'subject' => trim($data['subject']),
                'category' => SupportTicketCategory::from($data['category']),
                'status' => SupportTicketStatus::Open,
                'page_url' => $data['page_url'] ?? null,
                'last_message_at' => now(),
            ]);

            $ticket->messages()->create([
                'author_id' => $requester->getKey(),
                'body' => trim($data['body']),
                'is_staff' => false,
            ]);

            return $ticket;
        });

        $admins = User::query()->where('is_platform_admin', true)->get();

        try {
            Notification::send($admins, new NewSupportTicketNotification($ticket->load(['tenant', 'requester']), trim($data['body'])));
        } catch (Throwable $e) {
            // The ticket is in the admin queue either way; a mail outage must not lose it.
            report($e);
        }

        return $ticket;
    }
}
