"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { customersApi } from "@/lib/api/customers";
import type { Customer, CustomerInput, CustomerQuery, PricingTierInput } from "@/lib/types";

export function useCustomers(query: CustomerQuery = {}) {
  return useQuery({
    queryKey: ["customers", query],
    queryFn: () => customersApi.list(query),
  });
}

/**
 * Distinct customer types in use, gathered from whatever customer lists are
 * already cached. customer_type is a free string (no dedicated endpoint), so
 * these are suggestions for a creatable picker — you can still enter a new one.
 */
export function useCustomerTypes(): string[] {
  const queryClient = useQueryClient();
  const entries = queryClient.getQueriesData<{ data: Customer[] }>({ queryKey: ["customers"] });
  const types = new Set<string>();
  for (const [, value] of entries) {
    if (!value || !Array.isArray(value.data)) continue; // skip non-list caches (e.g. single item)
    for (const customer of value.data) {
      if (customer.customer_type) types.add(customer.customer_type);
    }
  }
  return [...types].sort((a, b) => a.localeCompare(b));
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

export function useCustomerOrderAnalytics(id: string | undefined, enabled = true) {
  return useQuery({
    queryKey: ["customers", "order-analytics", id],
    queryFn: () => customersApi.orderAnalytics(id!),
    enabled: !!id && enabled,
  });
}

export function useCustomerCustomPrices(id: string | undefined) {
  return useQuery({
    queryKey: ["customers", "custom-prices", id],
    queryFn: () => customersApi.customPrices(id!),
    enabled: !!id,
  });
}

export function useSetCustomPrice(customerId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (vars: { itemId: string; minor: number }) =>
      customersApi.setCustomPrice(vars.itemId, customerId, vars.minor),
    onSuccess: () =>
      void queryClient.invalidateQueries({ queryKey: ["customers", "custom-prices", customerId] }),
  });
}

export function useRemoveCustomPrice(customerId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (itemId: string) => customersApi.removeCustomPrice(itemId, customerId),
    onSuccess: () =>
      void queryClient.invalidateQueries({ queryKey: ["customers", "custom-prices", customerId] }),
  });
}

/** The customer's current order token (admins with customers.tokens). */
export function useCustomerToken(id: string | undefined, enabled: boolean) {
  return useQuery({
    queryKey: ["customers", "order-token", id],
    queryFn: () => customersApi.orderToken(id!),
    enabled: !!id && enabled,
  });
}

export function useGenerateOrderToken(id: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => customersApi.generateToken(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["customers", "item", id] });
      void queryClient.invalidateQueries({ queryKey: ["customers", "order-token", id] });
    },
  });
}

export function useRevokeOrderToken(id: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => customersApi.revokeToken(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["customers", "item", id] });
      void queryClient.invalidateQueries({ queryKey: ["customers", "order-token", id] });
    },
  });
}