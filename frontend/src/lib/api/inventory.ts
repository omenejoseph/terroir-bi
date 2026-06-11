import { api } from "@/lib/api/client";
import type {
  AttachImageInput,
  AttachDocumentInput,
  InventoryDocument,
  InventoryImage,
  InventoryItem,
  InventoryItemInput,
  InventoryItemUpdate,
  InventoryQuery,
  PaginationMeta,
  InventoryAnalytics,
  InventorySpend,
  InventoryCheckSummary,
  InventoryCheckDetail,
  InventoryCheckInput,
  ProduceInput,
  RecipeLine,
  RecipeLineInput,
  StockAdjustmentInput,
  StockAnalytics,
  StockMovement,
  TaxonomyEntry,
  ItemTierPrice,
  ItemCustomerPrice,
  BottleAnalysis,
  BottleAnalysisInput,
  Money,
} from "@/lib/types";

/** Inventory endpoints. Mirrors routes/api.php (inventory-items/*). */
export const inventoryApi = {
  /** GET /inventory-items/taxonomy — distinct category/group/subcategory combos. */
  taxonomy: () => api.get<TaxonomyEntry[]>("/inventory-items/taxonomy"),

  /** GET /inventory-items/analytics — stock levels, value by category, low stock. */
  analytics: () => api.get<InventoryAnalytics>("/inventory-items/analytics"),

  /** GET /inventory-items/spend — warehouse-exit spend for a date range. */
  spend: (from: string, to: string) =>
    api.get<InventorySpend>("/inventory-items/spend", { from, to }),

  /** POST /inventory-items/check — apply a stocktake (records an audited check). */
  applyCheck: (input: InventoryCheckInput) =>
    api.post<{ item_id: string; difference: string }[]>("/inventory-items/check", input),

  /** GET /inventory-checks — paginated audit history. */
  checkHistory: (page = 1) =>
    api.getPage<InventoryCheckSummary[]>("/inventory-checks", { page }),

  /** GET /inventory-checks/{id} — a check with its adjusted lines. */
  check: (id: string) => api.get<InventoryCheckDetail>(`/inventory-checks/${id}`),

  /** GET /inventory-items — paginated, with filters. */
  list: (query: InventoryQuery = {}): Promise<{ data: InventoryItem[]; meta?: PaginationMeta }> =>
    api.getPage<InventoryItem[]>("/inventory-items", {
      search: query.search,
      category: query.category,
      is_active: query.is_active,
      is_for_sale: query.is_for_sale,
      sellable: query.sellable,
      page: query.page,
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

  /** GET /inventory-items/{id}/stock-analytics — per-item stock dashboard for a period. */
  stockAnalytics: (id: string, period: string) =>
    api.get<StockAnalytics>(`/inventory-items/${id}/stock-analytics`, { period }),

  /** GET /inventory-items/{id}/tier-prices — the item's tier price book. */
  tierPrices: (id: string) => api.get<ItemTierPrice[]>(`/inventory-items/${id}/tier-prices`),

  /** PUT /inventory-items/{id}/tier-price/{tierId} — requires pricing.manage. */
  setTierPrice: (itemId: string, tierId: string, minor: number) =>
    api.put<Money>(`/inventory-items/${itemId}/tier-price/${tierId}`, { price: minor }),

  /** DELETE /inventory-items/{id}/tier-price/{tierId}. */
  removeTierPrice: (itemId: string, tierId: string) =>
    api.delete<void>(`/inventory-items/${itemId}/tier-price/${tierId}`),

  /** GET /inventory-items/{id}/customer-prices — the item's per-customer overrides. */
  customerPrices: (id: string) =>
    api.get<ItemCustomerPrice[]>(`/inventory-items/${id}/customer-prices`),

  /** PUT /inventory-items/{id}/customer-price/{customerId} — requires pricing.manage. */
  setCustomerPrice: (itemId: string, customerId: string, minor: number) =>
    api.put<Money>(`/inventory-items/${itemId}/customer-price/${customerId}`, { price: minor }),

  /** DELETE /inventory-items/{id}/customer-price/{customerId}. */
  removeCustomerPrice: (itemId: string, customerId: string) =>
    api.delete<void>(`/inventory-items/${itemId}/customer-price/${customerId}`),

  /** GET /inventory-items/{id}/bottle-analyses — lab analyses, newest first. */
  bottleAnalyses: (id: string) =>
    api.get<BottleAnalysis[]>(`/inventory-items/${id}/bottle-analyses`),

  /** POST /inventory-items/{id}/bottle-analyses — requires inventory.manage. */
  createBottleAnalysis: (id: string, input: BottleAnalysisInput) =>
    api.post<BottleAnalysis>(`/inventory-items/${id}/bottle-analyses`, input),

  /** DELETE /inventory-items/{id}/bottle-analyses/{analysisId}. */
  deleteBottleAnalysis: (id: string, analysisId: string) =>
    api.delete<void>(`/inventory-items/${id}/bottle-analyses/${analysisId}`),

  /** GET /inventory-items/{id}/recipe — current bill of materials. */
  recipe: (id: string) => api.get<RecipeLine[]>(`/inventory-items/${id}/recipe`),

  /** PUT /inventory-items/{id}/recipe — replaces the whole recipe. */
  setRecipe: (id: string, items: RecipeLineInput[]) =>
    api.put<RecipeLine[]>(`/inventory-items/${id}/recipe`, { items }),

  /** POST /inventory-items/{id}/produce — a production run (consumes recipe inputs). */
  produce: (id: string, input: ProduceInput) =>
    api.post<InventoryItem>(`/inventory-items/${id}/produce`, input),

  /** GET /inventory-items/{id}/images — attached images (presigned read URLs). */
  images: (id: string) => api.get<InventoryImage[]>(`/inventory-items/${id}/images`),

  /** POST /inventory-items/{id}/images — record an uploaded object as an image. */
  attachImage: (id: string, input: AttachImageInput) =>
    api.post<InventoryImage>(`/inventory-items/${id}/images`, input),

  /** DELETE /inventory-items/{id}/images/{imageId}. */
  deleteImage: (id: string, imageId: string) =>
    api.delete<void>(`/inventory-items/${id}/images/${imageId}`),

  /** GET /inventory-items/{id}/documents — attached files (presigned read URLs). */
  documents: (id: string) => api.get<InventoryDocument[]>(`/inventory-items/${id}/documents`),

  /** POST /inventory-items/{id}/documents — record an uploaded object as a document. */
  attachDocument: (id: string, input: AttachDocumentInput) =>
    api.post<InventoryDocument>(`/inventory-items/${id}/documents`, input),

  /** DELETE /inventory-items/{id}/documents/{documentId}. */
  deleteDocument: (id: string, documentId: string) =>
    api.delete<void>(`/inventory-items/${id}/documents/${documentId}`),
};