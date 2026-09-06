<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a customer their order confirmation — the drawer's overflow menu
 * "Resend" (OrderController::resendConfirmation) sends this same notification
 * again; there is no separate "first send" copy to drift from it.
 */
class OrderConfirmationNotification extends Notification
{
    public function __construct(public readonly Order $order) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order;

        $message = (new MailMessage)
            ->subject('Your order #'.$order->order_number)
            ->greeting('Hello,')
            ->line('Thank you for your order #'.$order->order_number.'. Here is a summary:');

        foreach ($order->items as $item) {
            /** @var OrderItem $item */
            $message->line('• '.$this->lineDescription($item));
        }

        return $message
            ->line('Total: '.$order->total_amount->toMajor().' '.$order->total_amount->getCurrencyCode())
            ->line('We will be in touch with any updates on your order.');
    }

    private function lineDescription(OrderItem $item): string
    {
        $name = $item->inventoryItem->name ?? $item->custom_description ?? 'Item';

        return $item->quantity.'x '.$name;
    }
}
