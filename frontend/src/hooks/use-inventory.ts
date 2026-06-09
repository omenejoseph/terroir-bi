"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { inventoryApi } from "@/lib/api/inventory";
import type {
  InventoryItemInput,
  InventoryItemUpdate,
  InventoryQuery,
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