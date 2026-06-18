"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { productionApi } from "@/lib/api/production";
import type { ProductionPlanInput, ProductionPlanUpdate } from "@/lib/types";

const PROD = ["production"] as const;

export function useProductionPlans() {
  return useQuery({ queryKey: [...PROD, "plans"], queryFn: () => productionApi.list() });
}

export function useProductionPlan(id: string | undefined) {
  return useQuery({ queryKey: [...PROD, "plan", id], queryFn: () => productionApi.get(id!), enabled: !!id });
}

export function useProductionCalculation(id: string | undefined, enabled: boolean) {
  return useQuery({
    queryKey: [...PROD, "calc", id],
    queryFn: () => productionApi.calculate(id!),
    enabled: !!id && enabled,
  });
}

export function useCreateProductionPlan() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: ProductionPlanInput) => productionApi.create(input),
    onSuccess: () => void qc.invalidateQueries({ queryKey: [...PROD, "plans"] }),
  });
}

export function useUpdateProductionPlan(id: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: ProductionPlanUpdate) => productionApi.update(id, input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: [...PROD, "plan", id] });
      void qc.invalidateQueries({ queryKey: [...PROD, "plans"] });
      void qc.invalidateQueries({ queryKey: [...PROD, "calc", id] });
    },
  });
}

export function useDeleteProductionPlan() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => productionApi.remove(id),
    onSuccess: () => void qc.invalidateQueries({ queryKey: [...PROD, "plans"] }),
  });
}

export function useConfirmProductionPlan(id: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (force: boolean) => productionApi.confirm(id, force),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: [...PROD, "plan", id] });
      void qc.invalidateQueries({ queryKey: [...PROD, "plans"] });
      void qc.invalidateQueries({ queryKey: ["inventory"] }); // confirm may auto-create items
    },
  });
}
