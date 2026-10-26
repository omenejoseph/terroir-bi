<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ReservationStatus;
use RuntimeException;

/** Only a pending or cancelled reservation can be deleted; anything further along is history. */
class ReservationNotDeletableException extends RuntimeException
{
    public static function for(ReservationStatus $status): self
    {
        return new self(__('A :status reservation cannot be deleted. Only pending or cancelled reservations can.', ['status' => __($status->label())]));
    }
}
