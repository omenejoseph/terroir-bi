<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Tells platform admins a tenant has raised a support ticket.
 */
class NewSupportTicketNotification extends Notification
{
    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly string $body,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("[#{$this->ticket->number}] {$this->ticket->subject}")
            ->greeting('New support ticket')
            ->line(($this->ticket->tenant->name ?? 'A tenant').' · '.($this->ticket->requester?->fullName() ?? 'unknown user'))
            ->line(Str::limit($this->body, 500))
            ->action('Open ticket', url('/admin/support/'.$this->ticket->getKey()));
    }
}
