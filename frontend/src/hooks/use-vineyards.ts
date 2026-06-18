"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { grapeContractsApi, harvestPlansApi, parcelsApi } from "@/lib/api/vineyards";
import type {
  GrapeContractInput,
  HarvestEntryInput,
  HarvestPlanInput,
  ParcelInput,
  RecordIntakeInput,
} from "@/lib/types";

const VINE = ["vineyards"] as const;

/* --- Parcels -------------------------------------------------------------- */

export function useParcels() {
  return useQuery({ queryKey: [...VINE, "parcels"], queryFn: () => parcelsApi.list() });
}

export function useParcel(id: string | undefined) {
  return useQuery({ queryKey: [...VINE, "parcel", id], queryFn: () => parcelsApi.get(id!), enabled: !!id });
}

export function useCreateParcel() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: ParcelInput) => parcelsApi.create(input),
    onSuccess: () => void qc.invalidateQueries({ queryKey: [...VINE, "parcels"] }),
  });
}

/** Agronomy adds all refresh the same parcel detail. */
function useParcelMutation<T>(fn: (id: string, input: T) => Promise<unknown>, id: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: T) => fn(id, input),
    onSuccess: () => void qc.invalidateQueries({ queryKey: [...VINE, "parcel", id] }),
  });
}

export const useAddMaturity = (id: string) => useParcelMutation<Record<string, unknown>>((i, a) => parcelsApi.addMaturity(i, a), id);
export const useAddPhenology = (id: string) => useParcelMutation<Record<string, unknown>>((i, a) => parcelsApi.addPhenology(i, a), id);
export const useAddCropEstimate = (id: string) => useParcelMutation<Record<string, unknown>>((i, a) => parcelsApi.addCropEstimate(i, a), id);
export const useAddApplication = (id: string) => useParcelMutation<Record<string, unknown>>((i, a) => parcelsApi.addApplication(i, a), id);

/* --- Contracts ------------------------------------------------------------ */

export function useGrapeContracts(season?: string) {
  return useQuery({ queryKey: [...VINE, "contracts", season], queryFn: () => grapeContractsApi.list(season) });
}

export function useCreateGrapeContract() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: GrapeContractInput) => grapeContractsApi.create(input),
    onSuccess: () => void qc.invalidateQueries({ queryKey: [...VINE, "contracts"] }),
  });
}

/* --- Harvest plans + intake ----------------------------------------------- */

export function useHarvestPlans() {
  return useQuery({ queryKey: [...VINE, "plans"], queryFn: () => harvestPlansApi.list() });
}

export function useHarvestPlan(id: string | undefined) {
  return useQuery({ queryKey: [...VINE, "plan", id], queryFn: () => harvestPlansApi.get(id!), enabled: !!id });
}

export function useCreateHarvestPlan() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: HarvestPlanInput) => harvestPlansApi.create(input),
    onSuccess: () => void qc.invalidateQueries({ queryKey: [...VINE, "plans"] }),
  });
}

function usePlanMutation<T>(fn: (id: string, input: T) => Promise<unknown>, id: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: T) => fn(id, input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: [...VINE, "plan", id] });
      void qc.invalidateQueries({ queryKey: ["cellar"] }); // intake mints a wine lot
    },
  });
}

export const useAddHarvestEntry = (id: string) => usePlanMutation<HarvestEntryInput>((i, a) => harvestPlansApi.addEntry(i, a), id);
export const useRemoveHarvestEntry = (id: string) => usePlanMutation<string>((i, eid) => harvestPlansApi.removeEntry(i, eid), id);
export const useRecordIntake = (id: string) =>
  usePlanMutation<{ entryId: string; input: RecordIntakeInput }>((i, a) => harvestPlansApi.recordIntake(i, a.entryId, a.input), id);
