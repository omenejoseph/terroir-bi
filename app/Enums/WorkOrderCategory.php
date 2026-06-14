<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kind of work a work order represents. Mirrors the order-mgmt set so the
 * two apps classify tasks the same way.
 */
enum WorkOrderCategory: string
{
    case Cellar = 'CELLAR';
    case Vineyard = 'VINEYARD';
    case Maintenance = 'MAINTENANCE';
    case Admin = 'ADMIN';
    case Delivery = 'DELIVERY';
    case Event = 'EVENT';
    case Other = 'OTHER';
}
