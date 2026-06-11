"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { inventoryApi } from "@/lib/api/inventory";
import type {
  BottleAnalysisInput,
  InventoryCheckInput,
  InventoryItemInput,
  InventoryItemUpdate,
  InventoryQuery,
  ProduceInput,
  RecipeLineInput,
  StockAdjustmentInput,
} from "@/lib/types";

/**
 * Data hook for the inventory list — UI components stay free of fetch logic.
 * `enabled` lets callers (e.g. a closed dropdown) avoid firing a request until
 * they actually need the data. Identical queries are deduped/cached by React Query.
 */
export function useInventory(query: InventoryQuery = {}, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: ["inventory", query],
    queryFn: () => inventoryApi.list(query),
    enabled: options?.enabled ?? true,
  });
}

/** Distinct category/group/subcategory combinations, for autocomplete + grouping. */
export function useInventoryTaxonomy() {
  return useQuery({
    queryKey: ["inventory", "taxonomy"],
    queryFn: () => inventoryApi.taxonomy(),
  });
}

/** A single item (detail page). */
export function useInventoryItem(id: string | undefined) {
  return useQuery({
    queryKey: ["inventory", "item", id],
    queryFn: () => inventoryApi.get(id!),
    enabled: !!id,
  });
}

/** An item's stock ledger. */
export function useStockMovements(id: string | undefined) {
  return useQuery({
    queryKey: ["inventory", "movements", id],
    queryFn: () => inventoryApi.movements(id!),
    enabled: !!id,
  });
}

/** Per-item stock analytics for a period (current, realized 12m, exits, channels). */
export function useStockAnalytics(id: string | undefined, period: string) {
  return useQuery({
    queryKey: ["inventory", "stock-analytics", id, period],
    queryFn: () => inventoryApi.stockAnalytics(id!, period),
    enabled: !!id,
  });
}

/** An item's tier price book. */
export function useItemTierPrices(id: string | undefined) {
  return useQuery({
    queryKey: ["inventory", "tier-prices", id],
    queryFn: () => inventoryApi.tierPrices(id!),
    enabled: !!id,
  });
}

export function useSetItemTierPrice(itemId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (vars: { tierId: string; minor: number }) =>
      inventoryApi.setTierPrice(itemId, vars.tierId, vars.minor),
    onSuccess: () =>
      void queryClient.invalidateQueries({ queryKey: ["inventory", "tier-prices", itemId] }),
  });
}

export function useRemoveItemTierPrice(itemId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (tierId: string) => inventoryApi.removeTierPrice(itemId, tierId),
    onSuccess: () =>
      void queryClient.invalidateQueries({ queryKey: ["inventory", "tier-prices", itemId] }),
  });
}

/** An item's per-customer price overrides. */
export function useItemCustomerPrices(id: string | undefined) {
  return useQuery({
    queryKey: ["inventory", "customer-prices", id],
    queryFn: () => inventoryApi.customerPrices(id!),
    enabled: !!id,
  });
}

export function useSetItemCustomerPrice(itemId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (vars: { customerId: string; minor: number }) =>
      inventoryApi.setCustomerPrice(itemId, vars.customerId, vars.minor),
    onSuccess: () =>
      void queryClient.invalidateQueries({ queryKey: ["inventory", "customer-prices", itemId] }),
  });
}

export function useRemoveItemCustomerPrice(itemId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (customerId: string) => inventoryApi.removeCustomerPrice(itemId, customerId),
    onSuccess: () =>
      void queryClient.invalidateQueries({ queryKey: ["inventory", "customer-prices", itemId] }),
  });
}

/** Audit history of stocktakes (paginated). */
export function useInventoryChecks(page: number) {
  return useQuery({
    queryKey: ["inventory", "checks", page],
    queryFn: () => inventoryApi.checkHistory(page),
  });
}

/** A single check with its adjusted lines. */
export function useInventoryCheck(id: string | undefined) {
  return useQuery({
    queryKey: ["inventory", "check", id],
    queryFn: () => inventoryApi.check(id!),
    enabled: !!id,
  });
}

/** Apply a physical stocktake; invalidates inventory + check history. */
export function useApplyInventoryCheck() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: InventoryCheckInput) => inventoryApi.applyCheck(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["inventory"] });
    },
  });
}

/** Warehouse-exit spend for a date range (FINISHED products). */
export function useInventorySpend(from: string, to: string) {
  return useQuery({
    queryKey: ["inventory", "spend", from, to],
    queryFn: () => inventoryApi.spend(from, to),
    enabled: !!from && !!to,
    staleTime: 60_000,
  });
}

/** An item's bottle (enology) analyses. */
export function useBottleAnalyses(id: string | undefined) {
  return useQuery({
    queryKey: ["inventory", "bottle-analyses", id],
    queryFn: () => inventoryApi.bottleAnalyses(id!),
    enabled: !!id,
  });
}

export function useCreateBottleAnalysis(itemId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: BottleAnalysisInput) => inventoryApi.createBottleAnalysis(itemId, input),
    onSuccess: () =>
      void queryClient.invalidateQueries({ queryKey: ["inventory", "bottle-analyses", itemId] }),
  });
}

export function useDeleteBottleAnalysis(itemId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (analysisId: string) => inventoryApi.deleteBottleAnalysis(itemId, analysisId),
    onSuccess: () =>
      void queryClient.invalidateQueries({ queryKey: ["inventory", "bottle-analyses", itemId] }),
  });
}

/** An item's recipe. */
export function useRecipe(id: string | undefined) {
  return useQuery({
    queryKey: ["inventory", "recipe", id],
    queryFn: () => inventoryApi.recipe(id!),
    enabled: !!id,
  });
}

/** Replace an item's recipe. */
export function useSetRecipe() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (vars: { id: string; items: RecipeLineInput[] }) =>
      inventoryApi.setRecipe(vars.id, vars.items),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["inventory"] });
    },
  });
}

/**
 * Create an inventory item. Stock can't be set on create (the API ledgers it
 * separately), so an optional opening quantity is posted as a MANUAL_IN movement
 * right after the item exists. Refreshes the list on success.
 */
export function useCreateInventoryItem() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (vars: { input: InventoryItemInput; openingStock?: number | null }) => {
      const item = await inventoryApi.create(vars.input);
      if (vars.openingStock && vars.openingStock > 0) {
        await inventoryApi.adjustStock(item.id, {
          type: "MANUAL_IN",
          quantity: vars.openingStock,
        });
      }
      return item;
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["inventory"] });
    },
  });
}

/** Update an inventory item's fields. */
export function useUpdateInventoryItem() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (vars: { id: string; input: InventoryItemUpdate }) =>
      inventoryApi.update(vars.id, vars.input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["inventory"] });
    },
  });
}

/** Record a signed stock movement against an item. */
export function useAdjustStock() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (vars: { id: string; input: StockAdjustmentInput }) =>
      inventoryApi.adjustStock(vars.id, vars.input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["inventory"] });
    },
  });
}

/** Run a production: consume recipe inputs and add finished stock. */
export function useProduce() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (vars: { id: string; input: ProduceInput }) =>
      inventoryApi.produce(vars.id, vars.input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["inventory"] });
    },
  });
}