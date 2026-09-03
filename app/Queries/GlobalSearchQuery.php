<?php

declare(strict_types=1);

namespace App\Queries;

use App\DataTransferObjects\SearchResultData;
use App\DataTransferObjects\SearchResultsData;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;

/**
 * The header's global search (Figma 389:1679): a handful of top matches per
 * category. Reuses each list query's own `build()` — the exact LIKE clause its
 * list page already filters by — rather than re-deriving what "search" means
 * per model, so this can never search a field a list page itself does not.
 */
class GlobalSearchQuery
{
    private const LIMIT = 5;

    public function __construct(
        private readonly ListOrdersQuery $orders,
        private readonly ListCustomersQuery $customers,
        private readonly ListInventoryItemsQuery $inventory,
    ) {}

    /**
     * @param  list<string>  $categories  Which of orders/customers/inventory to
     *                                    search — the controller passes only
     *                                    what the member's capabilities and the
     *                                    tenant's plan modules both allow.
     */
    public function search(string $term, array $categories): SearchResultsData
    {
        return new SearchResultsData(
            orders: in_array('orders', $categories, true) ? $this->searchOrders($term) : [],
            customers: in_array('customers', $categories, true) ? $this->searchCustomers($term) : [],
            inventory: in_array('inventory', $categories, true) ? $this->searchInventory($term) : [],
        );
    }

    /** @return list<SearchResultData> */
    private function searchOrders(string $term): array
    {
        return $this->orders->build(['search' => $term])
            ->with('customer')
            ->latest()
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Order $order): SearchResultData => SearchResultData::fromOrder($order))
            ->all();
    }

    /** @return list<SearchResultData> */
    private function searchCustomers(string $term): array
    {
        return $this->customers->build(['search' => $term])
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Customer $customer): SearchResultData => SearchResultData::fromCustomer($customer))
            ->all();
    }

    /** @return list<SearchResultData> */
    private function searchInventory(string $term): array
    {
        return $this->inventory->build(['search' => $term])
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (InventoryItem $item): SearchResultData => SearchResultData::fromInventoryItem($item))
            ->all();
    }
}
