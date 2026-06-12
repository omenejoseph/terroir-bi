"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { costsApi } from "@/lib/api/costs";
import type { CostInput, CostQuery, CostStatus } from "@/lib/types";

export function useCosts(query: CostQuery = {}) {
  return useQuery({
    queryKey: ["costs", query],
    queryFn: () => costsApi.list(query),
  });
}

export function useCostCategories() {
  return useQuery({
    queryKey: ["costs", "categories"],
    queryFn: () => costsApi.categories(),
    staleTime: 60_000,
  });
}

/** All / Invoices / Payments / Others counts for the current filter context (sans tab group). */
export function useCostGroupCounts(query: CostQuery = {}) {
  return useQuery({
    queryKey: ["costs", "group-counts", query],
    queryFn: () => costsApi.groupCounts(query),
  });
}

export function useCostAnalytics(range: { from?: string; to?: string } = {}) {
  return useQuery({
    queryKey: ["costs", "analytics", range],
    queryFn: () => costsApi.analytics(range),
  });
}

function useInvalidateCosts() {
  const queryClient = useQueryClient();
  return () => {
    void queryClient.invalidateQueries({ queryKey: ["costs"] });
    void queryClient.invalidateQueries({ queryKey: ["cash-flow"] });
  };
}

export function useCreateCost() {
  const invalidate = useInvalidateCosts();
  return useMutation({
    mutationFn: (input: CostInput) => costsApi.create(input),
    onSuccess: invalidate,
  });
}

export function useUpdateCost() {
  const invalidate = useInvalidateCosts();
  return useMutation({
    mutationFn: (vars: { id: string; input: Partial<CostInput> }) => costsApi.update(vars.id, vars.input),
    onSuccess: invalidate,
  });
}

export function useUpdateCostStatus() {
  const invalidate = useInvalidateCosts();
  return useMutation({
    mutationFn: (vars: { id: string; status: CostStatus }) =>
      costsApi.updateStatus(vars.id, vars.status),
    onSuccess: invalidate,
  });
}

export function useDeleteCost() {
  const invalidate = useInvalidateCosts();
  return useMutation({
    mutationFn: (id: string) => costsApi.delete(id),
    onSuccess: invalidate,
  });
}
