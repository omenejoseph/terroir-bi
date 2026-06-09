"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { suppliersApi } from "@/lib/api/suppliers";
import type {
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
