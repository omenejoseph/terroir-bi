"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { customersApi } from "@/lib/api/customers";
import type { CustomerInput, CustomerQuery, PricingTierInput } from "@/lib/types";

export function useCustomers(query: CustomerQuery = {}) {
  return useQuery({
    queryKey: ["customers", query],
    queryFn: () => customersApi.list(query),
  });
}

export function useCustomer(id: string | undefined) {
  return useQuery({
    queryKey: ["customers", "item", id],
    queryFn: () => customersApi.get(id!),
    enabled: !!id,
  });
}

export function usePricingTiers() {
  return useQuery({
    queryKey: ["pricing-tiers"],
    queryFn: () => customersApi.pricingTiers(),
    staleTime: 60_000,
  });
}

export function useCreatePricingTier() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: PricingTierInput) => customersApi.createPricingTier(input),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["pricing-tiers"] }),
  });
}

export function useCreateCustomer() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: CustomerInput) => customersApi.create(input),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["customers"] }),
  });
}

export function useUpdateCustomer() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (vars: { id: string; input: Partial<CustomerInput> }) =>
      customersApi.update(vars.id, vars.input),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["customers"] }),
  });
}

export function useDeleteCustomer() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => customersApi.delete(id),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["customers"] }),
  });
}