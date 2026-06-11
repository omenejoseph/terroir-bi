"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { supplierPortalApi } from "@/lib/api/supplier-portal";

export function usePublicSupplierPortal(token: string | undefined) {
  return useQuery({
    queryKey: ["supplier-portal", token],
    queryFn: () => supplierPortalApi.get(token!),
    enabled: !!token,
    retry: false,
  });
}

export function useImportPublicPriceList(token: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (items: { description: string; unit_price: number; unit: string | null }[]) =>
      supplierPortalApi.importPriceItems(token, items),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["supplier-portal", token] }),
  });
}

export function useConfirmPublicOrder(token: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (orderId: string) => supplierPortalApi.confirmOrder(token, orderId),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["supplier-portal", token] }),
  });
}
