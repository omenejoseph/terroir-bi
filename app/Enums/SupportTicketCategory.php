<?php

declare(strict_types=1);

namespace App\Enums;

/** What a ticket is about — picked by the person raising it. */
enum SupportTicketCategory: string
{
    case Question = 'question';
    case Problem = 'problem';
    case Billing = 'billing';
    case FeatureRequest = 'feature_request';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c): string => $c->value, self::cases());
    }
}
