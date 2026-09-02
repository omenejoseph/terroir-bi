<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatus: string
{
    case Received = 'RECEIVED';
    case InProcess = 'IN_PROCESS';
    case ReadyToShip = 'READY_TO_SHIP';
    case Shipped = 'SHIPPED';

    /**
     * The label the design uses for this status — on the list's status chips
     * and badges, the drawer's stepper, and the pipeline card (Figma 455:1577,
     * 376:1592). Defined here so those three cannot spell it differently.
     */
    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::InProcess => 'In Process',
            self::ReadyToShip => 'Ready to Ship',
            self::Shipped => 'Shipped',
        };
    }

    public function isShipped(): bool
    {
        return $this === self::Shipped;
    }
}
