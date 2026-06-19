"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { workOrdersApi } from "@/lib/api/work-orders";
import type { TaskStatus, WorkOrder, WorkOrderInput, WorkOrderQuery } from "@/lib/types";

export function useWorkOrders(query: WorkOrderQuery = {}) {
  return useQuery({
    queryKey: ["work-orders", query],
    queryFn: () => workOrdersApi.list(query),
  });
}

export function useWorkOrderStats(range?: string) {
  return useQuery({
    queryKey: ["work-orders", "stats", range ?? "ALL"],
    queryFn: () => workOrdersApi.stats(range),
  });
}

function useInvalidateTasks() {
  const queryClient = useQueryClient();
  return () => {
    void queryClient.invalidateQueries({ queryKey: ["work-orders"] });
    void queryClient.invalidateQueries({ queryKey: ["dashboard"] });
  };
}

export function useCreateWorkOrder() {
  const invalidate = useInvalidateTasks();
  return useMutation({
    mutationFn: (input: WorkOrderInput) => workOrdersApi.create(input),
    onSuccess: invalidate,
  });
}

export function useUpdateWorkOrder() {
  const invalidate = useInvalidateTasks();
  return useMutation({
    mutationFn: (vars: { id: string; input: Partial<WorkOrderInput> }) =>
      workOrdersApi.update(vars.id, vars.input),
    onSuccess: invalidate,
  });
}

export function useUpdateWorkOrderStatus() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (vars: { id: string; status: TaskStatus }) =>
      workOrdersApi.updateStatus(vars.id, vars.status),

    // Optimistic: flip the checkbox immediately, before the server responds.
    onMutate: async (vars) => {
      // Stop in-flight list refetches from clobbering our optimistic value.
      await queryClient.cancelQueries({ queryKey: ["work-orders"] });

      // Snapshot + patch every active work-orders *list* query (board, list,
      // each calendar view, filtered searches). The list queries cache a plain
      // WorkOrder[]; the stats query (["work-orders","stats",range]) caches an
      // object, so Array.isArray skips it.
      const snapshots = queryClient.getQueriesData<WorkOrder[]>({
        queryKey: ["work-orders"],
      });
      for (const [key, cached] of snapshots) {
        if (!Array.isArray(cached)) continue;
        queryClient.setQueryData(
          key,
          cached.map((w) => (w.id === vars.id ? { ...w, status: vars.status } : w)),
        );
      }
      return { snapshots };
    },

    // Server rejected: roll back every query we touched -> checkbox un-checks itself.
    onError: (_err, _vars, ctx) => {
      ctx?.snapshots.forEach(([key, data]) => queryClient.setQueryData(key, data));
    },

    // Reconcile with the server (status, completed_at, stats counters, dashboard)
    // after both success and rollback.
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: ["work-orders"] });
      void queryClient.invalidateQueries({ queryKey: ["dashboard"] });
    },
  });
}

export function useReorderWorkOrders() {
  const invalidate = useInvalidateTasks();
  return useMutation({
    mutationFn: (ids: string[]) => workOrdersApi.reorder(ids),
    onSuccess: invalidate,
  });
}

export function useDeleteWorkOrder() {
  const invalidate = useInvalidateTasks();
  return useMutation({
    mutationFn: (id: string) => workOrdersApi.delete(id),
    onSuccess: invalidate,
  });
}
