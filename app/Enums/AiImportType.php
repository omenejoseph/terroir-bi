<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kind of document a user uploaded for AI data entry. Each type maps to an
 * extractor (App\Services\Ai\Extractors) and to the target record type(s) its
 * proposed lines commit into.
 */
enum AiImportType: string
{
    case BankStatement = 'bank_statement';
    case Invoice = 'invoice';
    case InventoryList = 'inventory_list';
    case SupplierList = 'supplier_list';
    case CashInflow = 'cash_inflow';

    public function label(): string
    {
        return match ($this) {
            self::BankStatement => 'Bank statement',
            self::Invoice => 'Invoice / order',
            self::InventoryList => 'Inventory list',
            self::SupplierList => 'Supplier list',
            self::CashInflow => 'Cash inflow',
        };
    }

    /** The capability used to read this document type. */
    public function capability(): AiCapability
    {
        // All current types are read from PDFs/images, i.e. vision. A CSV path
        // can fall back to text inside the extractor when the file is text.
        return AiCapability::Vision;
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
