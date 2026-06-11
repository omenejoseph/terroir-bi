"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { suppliersApi } from "@/lib/api/suppliers";
import type {
  MergeSuppliersInput,
  PriceItemInput,
  SupplierInput,
  SupplierOrderInput,
  SupplierOrderQuery,
  SupplierOrderStatus,
  SupplierQuery,
} from "@/lib/types";

export function useSuppliers(query: SupplierQuery = {}) {
  return useQuery({
    queryKey: ["suppliers", query],
    queryFn: () => suppliersApi.list(query),
  });
}

export function useSupplier(id: string | undefined) {
  return useQuery({
    queryKey: ["suppliers", "item", id],
    queryFn: () => suppliersApi.get(id!),
    enabled: !!id,
  });
}

export function useCreateSupplier() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: SupplierInput) => suppliersApi.create(input),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["suppliers"] }),
  });
}

export function useUpdateSupplier() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (vars: { id: string; input: Partial<SupplierInput> }) =>
      suppliersApi.update(vars.id, vars.input),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["suppliers"] }),
  });
}

export function useDeleteSupplier() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => suppliersApi.delete(id),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["suppliers"] }),
  });
}

export function useAddPriceItem() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (vars: { id: string; input: PriceItemInput }) =>
      suppliersApi.addPriceItem(vars.id, vars.input),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["suppliers"] }),
  });
}

/** Preview a supplier merge (no changes applied). */
export function useSupplierMergePreview() {
  return useMutation({
    mutationFn: (input: MergeSuppliersInput) => suppliersApi.mergePreview(input),
  });
}

/** Apply a supplier merge; invalidates the supplier list. */
export function useMergeSuppliers() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: MergeSuppliersInput) => suppliersApi.merge(input),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["suppliers"] }),
  });
}

/** Supplier summary cards (price items + cost totals). */
export function useSupplierStats(id: string | undefined) {
  return useQuery({
    queryKey: ["suppliers", "stats", id],
    queryFn: () => suppliersApi.stats(id!),
    enabled: !!id,
  });
}

/** Audited cost-change history for a supplier's price list. */
export function useSupplierPriceChanges(id: string | undefined) {
  return useQuery({
    queryKey: ["suppliers", "price-changes", id],
    queryFn: () => suppliersApi.priceChanges(id!),
    enabled: !!id,
  });
}

/** The supplier's public portal token (admins with suppliers.manage). */
export function useSupplierPortalToken(id: string | undefined) {
  return useQuery({
    queryKey: ["suppliers", "portal-token", id],
    queryFn: () => suppliersApi.portalToken(id!),
    enabled: !!id,
  });
}

export function useGenerateSupplierPortalToken(id: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => suppliersApi.generatePortalToken(id),
    onSuccess: (data) => {
      // Seed the token cache directly; invalidate only the single supplier (not
      // the token query, which would refetch and clobber the seeded value).
      queryClient.setQueryData(["suppliers", "portal-token", id], { portal_token: data.portal_token });
      void queryClient.invalidateQueries({ queryKey: ["suppliers", "item", id] });
    },
  });
}

export function useRevokeSupplierPortalToken(id: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => suppliersApi.revokePortalToken(id),
    onSuccess: () => {
      queryClient.setQueryData(["suppliers", "portal-token", id], { portal_token: null });
      void queryClient.invalidateQueries({ queryKey: ["suppliers", "item", id] });
    },
  });
}

export function useUpdatePriceItem() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (vars: { id: string; priceItemId: string; input: PriceItemInput }) =>
      suppliersApi.updatePriceItem(vars.id, vars.priceItemId, vars.input),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["suppliers"] }),
  });
}

export function useDeletePriceItem() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (vars: { id: string; priceItemId: string }) =>
      suppliersApi.deletePriceItem(vars.id, vars.priceItemId),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["suppliers"] }),
  });
}

// ── Purchase orders ───────────────────────────────────────────────────────────

export function useSupplierOrders(query: SupplierOrderQuery = {}) {
  return useQuery({
    queryKey: ["supplier-orders", query],
    queryFn: () => suppliersApi.listOrders(query),
  });
}

export function useCreateSupplierOrder() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: SupplierOrderInput) => suppliersApi.createOrder(input),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["supplier-orders"] }),
  });
}

export function useUpdateSupplierOrderStatus() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (vars: { id: string; status: SupplierOrderStatus }) =>
      suppliersApi.updateOrderStatus(vars.id, vars.status),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["supplier-orders"] });
      // RECEIVED books stock — refresh inventory + dashboard.
      void queryClient.invalidateQueries({ queryKey: ["inventory"] });
      void queryClient.invalidateQueries({ queryKey: ["dashboard"] });
    },
  });
}

export function useDeleteSupplierOrder() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => suppliersApi.deleteOrder(id),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["supplier-orders"] }),
  });
}
