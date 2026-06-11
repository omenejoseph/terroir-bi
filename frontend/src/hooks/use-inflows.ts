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
