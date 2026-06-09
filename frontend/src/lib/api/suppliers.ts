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
  SupplierPriceItem,
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

  /** DELETE /suppliers/{id}/price-items/{priceItem}. */
  deletePriceItem: (id: string, priceItemId: string) =>
    api.delete<void>(`/suppliers/${id}/price-items/${priceItemId}`),

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
