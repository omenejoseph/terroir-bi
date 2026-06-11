"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { customersApi } from "@/lib/api/customers";
import type {
  Customer,
  CustomerInput,
  CustomerQuery,
  MergeCustomersInput,
  PricingTierInput,
} from "@/lib/types";

export function useCustomers(query: CustomerQuery = {}, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: ["customers", query],
    queryFn: () => customersApi.list(query),
    enabled: options?.enabled ?? true,
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

/** Preview a customer merge (no changes applied). */
export function useMergePreview() {
  return useMutation({
    mutationFn: (input: MergeCustomersInput) => customersApi.mergePreview(input),
  });
}

/** Apply a customer merge; invalidates the customer list. */
export function useMergeCustomers() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: MergeCustomersInput) => customersApi.merge(input),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["customers"] }),
  });
}

/** Per-bottle resolved prices for a customer + a set of items (custom/tier/rebate/default). */
export function useResolvedPrices(customerId: string, itemIds: string[]) {
  const key = [...itemIds].sort().join(",");
  return useQuery({
    queryKey: ["customers", "resolved-prices", customerId, key],
    queryFn: () => customersApi.resolvedPrices(customerId, itemIds),
    enabled: !!customerId && itemIds.length > 0,
  });
}

/** Tenant-wide customer analytics (summary + per-customer table). */
export function useCustomerAnalytics() {
  return useQuery({
    queryKey: ["customers", "analytics"],
    queryFn: () => customersApi.analytics(),
    staleTime: 60_000,
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
export function useCustomerToken(id: string | undefined, enabled = true) {
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
    onSuccess: (data) => {
      // Write the fresh token straight into the cache so the panel updates
      // immediately (no refetch/flicker); refresh the single customer for consistency.
      queryClient.setQueryData(["customers", "order-token", id], { order_token: data.order_token });
      void queryClient.invalidateQueries({ queryKey: ["customers", "item", id] });
    },
  });
}

export function useRevokeOrderToken(id: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => customersApi.revokeToken(id),
    onSuccess: () => {
      queryClient.setQueryData(["customers", "order-token", id], { order_token: null });
      void queryClient.invalidateQueries({ queryKey: ["customers", "item", id] });
    },
  });
}