import { api } from "@/lib/api/client";
import type {
  ConsignmentReturnInput,
  ConsignmentSaleInput,
  ConsignmentSummary,
  Order,
  OrderAnalytics,
  OrderComment,
  OrderInput,
  OrderItemInput,
  OrderItemUnit,
  OrderQuery,
  OrderStatus,
  PaginationMeta,
} from "@/lib/types";

/** Orders. Mirrors routes/api.php (orders/*, order-items/*, order-comments/*). */
export const ordersApi = {
  /** GET /orders — paginated, filterable. */
  list: (query: OrderQuery = {}): Promise<{ data: Order[]; meta?: PaginationMeta }> =>
    api.getPage<Order[]>("/orders", {
      status: query.status,
      search: query.search,
      hide_shipped: query.hide_shipped,
      page: query.page,
    }),

  /** GET /orders/{id}. */
  get: (id: string) => api.get<Order>(`/orders/${id}`),

  /** POST /orders — requires orders.manage. */
  create: (input: OrderInput) => api.post<Order>("/orders", input),

  /** PATCH /orders/{id}/status. */
  updateStatus: (id: string, input: { status: OrderStatus; note?: string | null }) =>
    api.patch<Order>(`/orders/${id}/status`, input),

  /** POST /orders/{id}/items. */
  addItems: (id: string, items: OrderItemInput[]) =>
    api.post<Order>(`/orders/${id}/items`, { items }),

  /** PATCH /order-items/{itemId}. */
  updateItem: (itemId: string, input: { quantity?: number; unit_type?: OrderItemUnit }) =>
    api.patch<Order>(`/order-items/${itemId}`, input),

  /** PATCH /order-items/{itemId}/cost. */
  updateItemCost: (itemId: string, costPerUnit: number | null) =>
    api.patch<Order>(`/order-items/${itemId}/cost`, { cost_per_unit: costPerUnit }),

  /** DELETE /order-items/{itemId}. */
  deleteItem: (itemId: string) => api.delete<Order>(`/order-items/${itemId}`),

  /** PATCH /orders/{id}/shipping. */
  updateShipping: (id: string, input: { shipping_cost: number | null; shipping_paid_by_us?: boolean }) =>
    api.patch<Order>(`/orders/${id}/shipping`, input),

  /** PATCH /orders/{id}/notes. */
  updateNotes: (id: string, notes: string | null) =>
    api.patch<Order>(`/orders/${id}/notes`, { notes }),

  /** PATCH /orders/{id}/backorder — requires orders.backorder. */
  updateBackorder: (id: string, backorderDate: string | null) =>
    api.patch<Order>(`/orders/${id}/backorder`, { backorder_date: backorderDate }),

  /** GET /orders/analytics — requires financials.view. */
  analytics: (params: { period?: string; from?: string; to?: string } = {}) =>
    api.get<OrderAnalytics>("/orders/analytics", params),

  // Comments
  addComment: (id: string, input: { content: string; mentions?: string[] }) =>
    api.post<OrderComment>(`/orders/${id}/comments`, input),
  updateComment: (commentId: string, content: string) =>
    api.patch<OrderComment>(`/order-comments/${commentId}`, { content }),
  deleteComment: (commentId: string) => api.delete<void>(`/order-comments/${commentId}`),

  // Consignment (order-level)
  consignment: (id: string) => api.get<ConsignmentSummary>(`/orders/${id}/consignment`),
  consignmentSale: (id: string, input: ConsignmentSaleInput) =>
    api.post<ConsignmentSummary>(`/orders/${id}/consignment/sale`, input),
  consignmentReturn: (id: string, input: ConsignmentReturnInput) =>
    api.post<ConsignmentSummary>(`/orders/${id}/consignment/return`, input),
  consignmentClose: (id: string) => api.post<ConsignmentSummary>(`/orders/${id}/consignment/close`, {}),
};
