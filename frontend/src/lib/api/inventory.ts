import { api } from "@/lib/api/client";
import type { InventoryItem, InventoryQuery, PaginationMeta } from "@/lib/types";

/** Inventory endpoints. Mirrors routes/api.php (inventory-items/*). */
export const inventoryApi = {
  /** GET /inventory-items — paginated, with filters. */
  list: (query: InventoryQuery = {}): Promise<{ data: InventoryItem[]; meta?: PaginationMeta }> =>
    api.getPage<InventoryItem[]>("/inventory-items", {
      search: query.search,
      category: query.category,
      is_active: query.is_active,
      is_for_sale: query.is_for_sale,
      sellable: query.sellable,
    }),

  /** GET /inventory-items/{id}. */
  get: (id: string) => api.get<InventoryItem>(`/inventory-items/${id}`),
};