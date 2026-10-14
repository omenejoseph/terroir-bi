<?php

declare(strict_types=1);

namespace App\Enums;

/** What a planned cellar activity does when it is executed from Cellar › Today. */
enum CellarActivityType: string
{
    case Addition = 'ADDITION';   // dose a product into a vessel/lot (draws enological stock)
    case Analysis = 'ANALYSIS';   // take readings (free SO₂, pH, …)
    case Transfer = 'TRANSFER';   // rack wine vessel → vessel
    case Process = 'PROCESS';     // a cellar operation (pump-over, bâtonnage, …)
    case Topping = 'TOPPING';     // top up a vessel, optionally from a source vessel
    case Vineyard = 'VINEYARD';   // a vineyard task — recorded only, no cellar mutation

    public function label(): string
    {
        return match ($this) {
            self::Addition => 'Addition',
            self::Analysis => 'Analysis',
            self::Transfer => 'Transfer',
            self::Process => 'Process',
            self::Topping => 'Topping',
            self::Vineyard => 'Vineyard',
        };
    }
}
