"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { workOrdersApi } from "@/lib/api/work-orders";
import type { TaskStatus, WorkOrderInput, WorkOrderQuery } from "@/lib/types";

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
  const invalidate = useInvalidateTasks();
  return useMutation({
    mutationFn: (vars: { id: string; status: TaskStatus }) =>
      workOrdersApi.updateStatus(vars.id, vars.status),
    onSuccess: invalidate,
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
