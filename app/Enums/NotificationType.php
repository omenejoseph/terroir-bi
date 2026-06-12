<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationType: string
{
    case Mention = 'MENTION';
    case NewOrder = 'NEW_ORDER';
    case OrderStatus = 'ORDER_STATUS';
    case Reply = 'REPLY';
    case Announcement = 'ANNOUNCEMENT';
}
