import { api } from "@/lib/api/client";
import type {
  InventoryItem,
  InventoryItemInput,
  InventoryItemUpdate,
  InventoryQuery,
  PaginationMeta,
  InventoryAnalytics,
  RecipeLine,
  RecipeLineInput,
  StockAdjustmentInput,
  StockMovement,
  TaxonomyEntry,
} from "@/lib/types";

/** Inventory endpoints. Mirrors routes/api.php (inventory-items/*). */
export const inventoryApi = {
  /** GET /inventory-items/taxonomy — distinct category/group/subcategory combos. */
  taxonomy: () => api.get<TaxonomyEntry[]>("/inventory-items/taxonomy"),

  /** GET /inventory-items/analytics — stock levels, value by category, low stock. */
  analytics: () => api.get<InventoryAnalytics>("/inventory-items/analytics"),

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

  /** POST /inventory-items — requires inventory.manage. */
  create: (input: InventoryItemInput) => api.post<InventoryItem>("/inventory-items", input),

  /** PATCH /inventory-items/{id} — requires inventory.manage. */
  update: (id: string, input: InventoryItemUpdate) =>
    api.patch<InventoryItem>(`/inventory-items/${id}`, input),

  /** POST /inventory-items/{id}/stock — records a signed movement, returns the item. */
  adjustStock: (id: string, input: StockAdjustmentInput) =>
    api.post<InventoryItem>(`/inventory-items/${id}/stock`, input),

  /** GET /inventory-items/{id}/movements — ledger entries, newest first. */
  movements: (id: string) => api.get<StockMovement[]>(`/inventory-items/${id}/movements`),

  /** GET /inventory-items/{id}/recipe — current bill of materials. */
  recipe: (id: string) => api.get<RecipeLine[]>(`/inventory-items/${id}/recipe`),

  /** PUT /inventory-items/{id}/recipe — replaces the whole recipe. */
  setRecipe: (id: string, items: RecipeLineInput[]) =>
    api.put<RecipeLine[]>(`/inventory-items/${id}/recipe`, { items }),
};