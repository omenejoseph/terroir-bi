import { api } from "@/lib/api/client";
import type {
  Cost,
  CostAnalytics,
  CostGroupCounts,
  CostInput,
  CostQuery,
  CostStatus,
  PaginationMeta,
} from "@/lib/types";

/** Cost / expense endpoints. Mirrors routes/api.php. */
export const costsApi = {
  /** GET /costs — paginated, filterable. */
  list: (query: CostQuery = {}): Promise<{ data: Cost[]; meta?: PaginationMeta }> =>
    api.getPage<Cost[]>("/costs", {
      search: query.search,
      category: query.category,
      status: query.status,
      supplier_id: query.supplier_id,
      group: query.group,
      date_from: query.date_from,
      date_to: query.date_to,
      page: query.page,
    }),

  /** GET /costs/group-counts — All / Invoices / Payments / Others counts. */
  groupCounts: (query: CostQuery = {}) =>
    api.get<CostGroupCounts>("/costs/group-counts", {
      search: query.search,
      category: query.category,
      status: query.status,
      supplier_id: query.supplier_id,
      date_from: query.date_from,
      date_to: query.date_to,
    }),

  /** GET /costs/categories — distinct categories for the filter/picker. */
  categories: () => api.get<string[]>("/costs/categories"),

  /** GET /costs/analytics — spend totals + breakdowns. */
  analytics: () => api.get<CostAnalytics>("/costs/analytics"),

  /** POST /costs — requires finance.manage (money fields are minor units). */
  create: (input: CostInput) => api.post<Cost>("/costs", input),

  /** PATCH /costs/{id} — requires finance.manage. */
  update: (id: string, input: Partial<CostInput>) => api.patch<Cost>(`/costs/${id}`, input),

  /** PATCH /costs/{id}/status — requires finance.manage. */
  updateStatus: (id: string, status: CostStatus) =>
    api.patch<Cost>(`/costs/${id}/status`, { status }),

  /** DELETE /costs/{id} — requires finance.delete. */
  delete: (id: string) => api.delete<void>(`/costs/${id}`),
};
