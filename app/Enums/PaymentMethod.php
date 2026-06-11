<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How money moved for a cost or an inflow. Shared by both finance modules so the
 * accepted set is validated in one place. Backing values are lowercase to match
 * the stored data and the frontend's PAYMENT_METHODS catalogue.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Card = 'card';
    case Other = 'other';
}
