"use client";

import { useQuery } from "@tanstack/react-query";

import { dashboardApi } from "@/lib/api/dashboard";
import { inventoryApi } from "@/lib/api/inventory";

/** Aggregated dashboard summary for a time range. Cached a little longer — it's an aggregate. */
export function useDashboard(range: string) {
  return useQuery({
    queryKey: ["dashboard", range],
    queryFn: () => dashboardApi.summary(range),
    staleTime: 60_000,
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