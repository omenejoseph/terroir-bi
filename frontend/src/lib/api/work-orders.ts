import { api } from "@/lib/api/client";
import type {
  TaskStatus,
  WorkOrder,
  WorkOrderInput,
  WorkOrderQuery,
  WorkOrderStats,
} from "@/lib/types";

/** Work-order (task planner) endpoints. Mirrors routes/api.php. */
export const workOrdersApi = {
  /** GET /work-orders — non-paginated list, filterable. */
  list: (query: WorkOrderQuery = {}) =>
    api.get<WorkOrder[]>("/work-orders", {
      status: query.status,
      assignee_id: query.assignee_id,
      search: query.search,
      due_from: query.due_from,
      due_to: query.due_to,
    }),

  /** GET /work-orders/stats — board counters. */
  stats: () => api.get<WorkOrderStats>("/work-orders/stats"),

  /** POST /work-orders. */
  create: (input: WorkOrderInput) => api.post<WorkOrder>("/work-orders", input),

  /** PATCH /work-orders/{id}. */
  update: (id: string, input: Partial<WorkOrderInput>) =>
    api.patch<WorkOrder>(`/work-orders/${id}`, input),

  /** PATCH /work-orders/{id}/status. */
  updateStatus: (id: string, status: TaskStatus) =>
    api.patch<WorkOrder>(`/work-orders/${id}/status`, { status }),

  /** POST /work-orders/reorder — full ordered id list. */
  reorder: (ids: string[]) => api.post<void>("/work-orders/reorder", { ids }),

  /** DELETE /work-orders/{id}. */
  delete: (id: string) => api.delete<void>(`/work-orders/${id}`),
};
