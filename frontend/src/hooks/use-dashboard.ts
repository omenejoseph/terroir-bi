"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";

import { dashboardApi, type DashboardQuery } from "@/lib/api/dashboard";
import { inventoryApi } from "@/lib/api/inventory";

/**
 * Aggregated dashboard summary for a selected period. Cached a little longer —
 * it's an aggregate. Keeps the previous payload while a new period loads so the
 * page (and especially the period-independent summary cards) never blanks out.
 */
export function useDashboard(query: DashboardQuery) {
  return useQuery({
    queryKey: ["dashboard", query.period, query.from, query.to],
    queryFn: () => dashboardApi.summary(query),
    staleTime: 60_000,
    placeholderData: keepPreviousData,
  });
}

/** Inventory analytics for the inventory charts. */
export function useInventoryAnalytics() {
  return useQuery({
    queryKey: ["inventory", "analytics"],
    queryFn: () => inventoryApi.analytics(),
    staleTime: 60_000,
  });
}