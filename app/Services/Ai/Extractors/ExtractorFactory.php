<?php

declare(strict_types=1);

namespace App\Services\Ai\Extractors;

use App\Enums\AiImportType;

/**
 * Resolves the extractor for a given import type.
 */
class ExtractorFactory
{
    public function for(AiImportType $type): DocumentExtractor
    {
        return match ($type) {
            AiImportType::BankStatement => new BankStatementExtractor,
            AiImportType::CashInflow => new CashInflowExtractor,
            AiImportType::Invoice => new InvoiceExtractor,
            AiImportType::InventoryList => new InventoryListExtractor,
            AiImportType::SupplierList => new SupplierListExtractor,
        };
    }
}
