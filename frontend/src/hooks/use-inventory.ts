"use client";

import { useQuery } from "@tanstack/react-query";

import { inventoryApi } from "@/lib/api/inventory";
import type { InventoryQuery } from "@/lib/types";

/** Data hook for the inventory list — UI components stay free of fetch logic. */
export function useInventory(query: InventoryQuery = {}) {
  return useQuery({
    queryKey: ["inventory", query],
    queryFn: () => inventoryApi.list(query),
  });
}