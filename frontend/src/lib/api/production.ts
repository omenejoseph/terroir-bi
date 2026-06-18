import { api } from "@/lib/api/client";
import type {
  ProductionCalculation,
  ProductionPlan,
  ProductionPlanInput,
  ProductionPlanUpdate,
} from "@/lib/types";

/** Production planner. Mirrors routes/api.php (production-plans/*). */
export const productionApi = {
  list: () => api.get<ProductionPlan[]>("/production-plans"),
  get: (id: string) => api.get<ProductionPlan>(`/production-plans/${id}`),
  create: (input: ProductionPlanInput) => api.post<ProductionPlan>("/production-plans", input),
  update: (id: string, input: ProductionPlanUpdate) => api.patch<ProductionPlan>(`/production-plans/${id}`, input),
  remove: (id: string) => api.delete<void>(`/production-plans/${id}`),
  calculate: (id: string) => api.get<ProductionCalculation>(`/production-plans/${id}/calculate`),
  confirm: (id: string, force = false) => api.post<ProductionPlan>(`/production-plans/${id}/confirm`, { force }),
};
