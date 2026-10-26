<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Emails someone that the other side answered their support ticket — the
 * requester when support replies, the assigned admin when the customer does.
 */
class SupportTicketReplyNotification extends Notification
{
    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly string $body,
        public readonly string $url,
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
            ->subject("Re: [#{$this->ticket->number}] {$this->ticket->subject}")
            ->line(Str::limit($this->body, 1000))
            ->action('View the conversation', $this->url);
    }
}
