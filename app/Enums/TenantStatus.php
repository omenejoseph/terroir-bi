<?php

declare(strict_types=1);

namespace App\Enums;

enum TenantStatus: string
{
    case Trial = 'TRIAL';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Canceled = 'CANCELED';

    /** Whether tenants in this status may use the application. */
    public function isUsable(): bool
    {
        return match ($this) {
            self::Active, self::Trial => true,
            self::Suspended, self::Canceled => false,
        };
    }
}
