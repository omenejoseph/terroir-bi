import { api } from "@/lib/api/client";
import type {
  Inflow,
  InflowAnalytics,
  InflowChange,
  InflowInput,
  InflowQuery,
  InflowStatus,
  PaginationMeta,
} from "@/lib/types";

/** Money-in (inflow / A-R payment) endpoints. Mirrors routes/api.php. */
export const inflowsApi = {
  /** GET /inflows — paginated, filterable. */
  list: (query: InflowQuery = {}): Promise<{ data: Inflow[]; meta?: PaginationMeta }> =>
    api.getPage<Inflow[]>("/inflows", {
      status: query.status,
      customer_id: query.customer_id,
      order_id: query.order_id,
      search: query.search,
      page: query.page,
    }),

  /** GET /inflows/{id}. */
  get: (id: string) => api.get<Inflow>(`/inflows/${id}`),

  /** GET /inflows/{id}/changes — edit history (newest first). */
  changes: (id: string) => api.get<InflowChange[]>(`/inflows/${id}/changes`),

  /** GET /inflows/analytics — cash-in analytics for a date range. */
  analytics: (range: { from?: string; to?: string } = {}) =>
    api.get<InflowAnalytics>("/inflows/analytics", { from: range.from, to: range.to }),

  /** POST /inflows — requires finance.manage (amount is minor units). */
  create: (input: InflowInput) => api.post<Inflow>("/inflows", input),

  /** PATCH /inflows/{id} — requires finance.manage. */
  update: (id: string, input: Partial<InflowInput>) => api.patch<Inflow>(`/inflows/${id}`, input),

  /** PATCH /inflows/{id}/status — mark pending/received. */
  updateStatus: (id: string, status: InflowStatus) =>
    api.patch<Inflow>(`/inflows/${id}/status`, { status }),

  /** DELETE /inflows/{id} — requires finance.delete. */
  delete: (id: string) => api.delete<void>(`/inflows/${id}`),
};
