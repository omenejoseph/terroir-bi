"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { inflowsApi } from "@/lib/api/inflows";
import type { InflowInput, InflowQuery, InflowStatus } from "@/lib/types";

export function useInflows(query: InflowQuery = {}) {
  return useQuery({
    queryKey: ["inflows", query],
    queryFn: () => inflowsApi.list(query),
  });
}

export function useInflow(id: string | undefined) {
  return useQuery({
    queryKey: ["inflows", "item", id],
    queryFn: () => inflowsApi.get(id!),
    enabled: !!id,
  });
}

export function useInflowChanges(id: string | undefined) {
  return useQuery({
    queryKey: ["inflows", "changes", id],
    queryFn: () => inflowsApi.changes(id!),
    enabled: !!id,
  });
}

export function useInflowAnalytics(range: { from?: string; to?: string } = {}) {
  return useQuery({
    queryKey: ["inflows", "analytics", range],
    queryFn: () => inflowsApi.analytics(range),
  });
}

/** Money-in changes ripple into cash flow, A/R aging and the dashboard KPIs. */
function useInvalidateInflows() {
  const queryClient = useQueryClient();
  return () => {
    void queryClient.invalidateQueries({ queryKey: ["inflows"] });
    void queryClient.invalidateQueries({ queryKey: ["cash-flow"] });
    void queryClient.invalidateQueries({ queryKey: ["ar-aging"] });
    void queryClient.invalidateQueries({ queryKey: ["dashboard"] });
  };
}

export function useCreateInflow() {
  const invalidate = useInvalidateInflows();
  return useMutation({
    mutationFn: (input: InflowInput) => inflowsApi.create(input),
    onSuccess: invalidate,
  });
}

export function useUpdateInflow() {
  const invalidate = useInvalidateInflows();
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (vars: { id: string; input: Partial<InflowInput> }) =>
      inflowsApi.update(vars.id, vars.input),
    onSuccess: (_data, vars) => {
      invalidate();
      void queryClient.invalidateQueries({ queryKey: ["inflows", "item", vars.id] });
      void queryClient.invalidateQueries({ queryKey: ["inflows", "changes", vars.id] });
    },
  });
}

export function useUpdateInflowStatus() {
  const invalidate = useInvalidateInflows();
  return useMutation({
    mutationFn: (vars: { id: string; status: InflowStatus }) =>
      inflowsApi.updateStatus(vars.id, vars.status),
    onSuccess: invalidate,
  });
}

export function useDeleteInflow() {
  const invalidate = useInvalidateInflows();
  return useMutation({
    mutationFn: (id: string) => inflowsApi.delete(id),
    onSuccess: invalidate,
  });
}
