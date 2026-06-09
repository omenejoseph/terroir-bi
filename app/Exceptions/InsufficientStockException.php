<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\InventoryItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Raised by the stock ledger when a deduction would drive an item's stock
 * negative. Renders as 422 so the API surfaces a clear, actionable message
 * (the source app's "mark it a backorder" guidance) instead of a 500.
 */
class InsufficientStockException extends RuntimeException
{
    private function __construct(
        public readonly string $itemName,
        public readonly string $available,
        public readonly string $needed,
        public readonly string $unit,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function for(InventoryItem $item, string $needed): self
    {
        $available = (string) $item->current_stock;
        $unit = (string) $item->unit;

        return new self(
            itemName: $item->name,
            available: $available,
            needed: $needed,
            unit: $unit,
            message: "Not enough stock for {$item->name} — {$available} {$unit} available (need {$needed}). Mark it a backorder if intended.",
        );
    }

    public function render(Request $request): JsonResponse
    {
        return new JsonResponse([
            'message' => $this->getMessage(),
            'errors' => [
                'quantity' => [$this->getMessage()],
            ],
        ], 422);
    }
}
