import { api } from "@/lib/api/client";
import type { DashboardSummary } from "@/lib/types";

export const dashboardApi = {
  /** GET /dashboard — aggregated summary for the active tenant. */
  summary: (range: string) => api.get<DashboardSummary>("/dashboard", { range }),
};