import { api } from "@/lib/api/client";
import type { DashboardPeriod } from "@/lib/dashboard-period";
import type { DashboardSummary } from "@/lib/types";

export interface DashboardQuery {
  period: DashboardPeriod;
  /** Custom range bounds (YYYY-MM-DD), only used when period === "custom". */
  from?: string;
  to?: string;
}

export const dashboardApi = {
  /** GET /dashboard — aggregated summary for the active tenant. */
  summary: ({ period, from, to }: DashboardQuery) =>
    api.get<DashboardSummary>("/dashboard", { period, from, to }),
};
