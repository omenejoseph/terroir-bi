import { api } from "@/lib/api/client";
import type {
  PaginationMeta,
  PriceItemInput,
  Supplier,
  SupplierInput,
  SupplierOrder,
  SupplierOrderInput,
  SupplierOrderQuery,
  SupplierOrderStatus,
  MergeSuppliersInput,
  SupplierMergePreview,
  SupplierPriceChange,
  SupplierPriceItem,
  SupplierStats,
  SupplierQuery,
} from "@/lib/types";

/** Supplier, price-list and purchase-order endpoints. Mirrors routes/api.php. */
export const suppliersApi = {
  /** GET /suppliers — paginated, filterable. */
  list: (query: SupplierQuery = {}): Promise<{ data: Supplier[]; meta?: PaginationMeta }> =>
    api.getPage<Supplier[]>("/suppliers", {
      search: query.search,
      is_active: query.is_active,
    }),

  /** GET /suppliers/{id} — includes the price list. */
  get: (id: string) => api.get<Supplier>(`/suppliers/${id}`),

  /** POST /suppliers — requires suppliers.manage. */
  create: (input: SupplierInput) => api.post<Supplier>("/suppliers", input),

  /** PATCH /suppliers/{id} — requires suppliers.manage. */
  update: (id: string, input: Partial<SupplierInput>) =>
    api.patch<Supplier>(`/suppliers/${id}`, input),

  /** DELETE /suppliers/{id} — requires suppliers.delete. */
  delete: (id: string) => api.delete<void>(`/suppliers/${id}`),

  /** POST /suppliers/{id}/price-items — upserts a price-list line (unit_price is minor units). */
  addPriceItem: (id: string, input: PriceItemInput) =>
    api.post<SupplierPriceItem>(`/suppliers/${id}/price-items`, input),

  /** PATCH /suppliers/{id}/price-items/{priceItem} — edit a line by id. */
  updatePriceItem: (id: string, priceItemId: string, input: PriceItemInput) =>
    api.patch<SupplierPriceItem>(`/suppliers/${id}/price-items/${priceItemId}`, input),

  /** DELETE /suppliers/{id}/price-items/{priceItem}. */
  deletePriceItem: (id: string, priceItemId: string) =>
    api.delete<void>(`/suppliers/${id}/price-items/${priceItemId}`),

  /** GET /suppliers/{id}/stats — summary cards (price items + cost totals). */
  stats: (id: string) => api.get<SupplierStats>(`/suppliers/${id}/stats`),

  /** POST /suppliers/merge/preview — what would move (requires suppliers.manage). */
  mergePreview: (input: MergeSuppliersInput) =>
    api.post<SupplierMergePreview>("/suppliers/merge/preview", input),

  /** POST /suppliers/merge — apply the merge (requires suppliers.delete). */
  merge: (input: MergeSuppliersInput) => api.post<SupplierMergePreview>("/suppliers/merge", input),

  /** GET /suppliers/{id}/price-changes — audited cost-change history. */
  priceChanges: (id: string) =>
    api.get<SupplierPriceChange[]>(`/suppliers/${id}/price-changes`),

  // ── Public portal token (admin) ─────────────────────────────────────────────

  /** GET /suppliers/{id}/portal-token. */
  portalToken: (id: string) =>
    api.get<{ portal_token: string | null }>(`/suppliers/${id}/portal-token`),

  /** POST /suppliers/{id}/portal-token — (re)generate the portal link. */
  generatePortalToken: (id: string) =>
    api.post<Supplier & { portal_token: string }>(`/suppliers/${id}/portal-token`, {}),

  /** DELETE /suppliers/{id}/portal-token — disable the portal. */
  revokePortalToken: (id: string) => api.delete<Supplier>(`/suppliers/${id}/portal-token`),

  // ── Purchase orders ─────────────────────────────────────────────────────────

  /** GET /supplier-orders — paginated, status/supplier filterable. */
  listOrders: (
    query: SupplierOrderQuery = {},
  ): Promise<{ data: SupplierOrder[]; meta?: PaginationMeta }> =>
    api.getPage<SupplierOrder[]>("/supplier-orders", {
      status: query.status,
      supplier_id: query.supplier_id,
    }),

  /** POST /supplier-orders — requires suppliers.manage. */
  createOrder: (input: SupplierOrderInput) => api.post<SupplierOrder>("/supplier-orders", input),

  /** PATCH /supplier-orders/{id}/status — RECEIVED books stock. */
  updateOrderStatus: (id: string, status: SupplierOrderStatus) =>
    api.patch<SupplierOrder>(`/supplier-orders/${id}/status`, { status }),

  /** DELETE /supplier-orders/{id} — DRAFT/CANCELLED only. */
  deleteOrder: (id: string) => api.delete<void>(`/supplier-orders/${id}`),
};
