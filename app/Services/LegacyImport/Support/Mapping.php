<?php

declare(strict_types=1);

namespace App\Services\LegacyImport\Support;

use App\Enums\PaymentMethod;

/** Legacy free-text → rebuild enum value mappings shared by several steps. */
final class Mapping
{
    /** "Bank Transfer" / "Credit Card" / … → PaymentMethod value; unknown text → other, blank → null. */
    public static function paymentMethod(?string $legacy): ?string
    {
        $t = strtolower(trim((string) $legacy));

        return match (true) {
            $t === '' => null,
            str_contains($t, 'transfer'), str_contains($t, 'bank') => PaymentMethod::BankTransfer->value,
            str_contains($t, 'card') => PaymentMethod::Card->value,
            str_contains($t, 'cash') => PaymentMethod::Cash->value,
            default => PaymentMethod::Other->value,
        };
    }
}
