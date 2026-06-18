"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  cellarReportsApi,
  enologicalApi,
  fermentationApi,
  fermentationTemplatesApi,
  tastingsApi,
  vesselsApi,
  wineLotsApi,
} from "@/lib/api/cellar";
import type {
  AdditionInput,
  AnalysisInput,
  BlendInput,
  BottlingInput,
  BulkAdditionsInput,
  BulkCreateVesselsInput,
  EnologicalProductInput,
  FermentationTemplateInput,
  ProcessInput,
  TastingNoteInput,
  TastingReportInput,
  TransferInput,
  VesselInput,
  VesselLayoutUpdate,
  WineLotInput,
  WineLotQuery,
} from "@/lib/types";

// Shared key roots so a mutation can invalidate the whole module cheaply.
const CELLAR = ["cellar"] as const;

/* --- Vessels (cellar map) ------------------------------------------------- */

export function useVessels(params: { room?: string; active_only?: boolean } = {}) {
  return useQuery({
    queryKey: [...CELLAR, "vessels", params],
    queryFn: () => vesselsApi.list(params),
  });
}

export function useCreateVessel() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: VesselInput) => vesselsApi.create(input),
    onSuccess: () => void qc.invalidateQueries({ queryKey: [...CELLAR, "vessels"] }),
  });
}

export function useBulkCreateVessels() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: BulkCreateVesselsInput) => vesselsApi.bulkCreate(input),
    onSuccess: () => void qc.invalidateQueries({ queryKey: [...CELLAR, "vessels"] }),
  });
}

export function useUpdateVessel() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (vars: { id: string; input: Partial<VesselInput> & { status?: string; is_active?: boolean } }) =>
      vesselsApi.update(vars.id, vars.input),
    onSuccess: () => void qc.invalidateQueries({ queryKey: [...CELLAR, "vessels"] }),
  });
}

export function useSaveVesselLayout() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (updates: VesselLayoutUpdate[]) => vesselsApi.saveLayout(updates),
    onSuccess: () => void qc.invalidateQueries({ queryKey: [...CELLAR, "vessels"] }),
  });
}

export function useRenameRoom() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (vars: { from: string; to: string }) => vesselsApi.renameRoom(vars.from, vars.to),
    onSuccess: () => void qc.invalidateQueries({ queryKey: [...CELLAR, "vessels"] }),
  });
}

export function useDeleteVessel() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => vesselsApi.remove(id),
    onSuccess: () => void qc.invalidateQueries({ queryKey: [...CELLAR, "vessels"] }),
  });
}

/* --- Wine lots ------------------------------------------------------------ */

export function useWineLots(query: WineLotQuery = {}) {
  return useQuery({
    queryKey: [...CELLAR, "wine-lots", query],
    queryFn: () => wineLotsApi.list(query),
  });
}

export function useWineLot(id: string | undefined) {
  return useQuery({
    queryKey: [...CELLAR, "wine-lot", id],
    queryFn: () => wineLotsApi.get(id!),
    enabled: !!id,
  });
}

export function useLotAnalysisTrend(id: string | undefined) {
  return useQuery({
    queryKey: [...CELLAR, "analysis-trend", id],
    queryFn: () => wineLotsApi.analysisTrend(id!),
    enabled: !!id,
  });
}

export function useCreateWineLot() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: WineLotInput) => wineLotsApi.create(input),
    onSuccess: () => void qc.invalidateQueries({ queryKey: CELLAR }),
  });
}

/**
 * Every per-lot mutation refreshes both the lot detail and the vessels (volume
 * and SO₂ badges change). One factory keeps the call sites tiny.
 */
function useLotMutation<TArgs>(fn: (id: string, args: TArgs) => Promise<unknown>, id: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (args: TArgs) => fn(id, args),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: [...CELLAR, "wine-lot", id] });
      void qc.invalidateQueries({ queryKey: [...CELLAR, "wine-lots"] });
      void qc.invalidateQueries({ queryKey: [...CELLAR, "vessels"] });
      void qc.invalidateQueries({ queryKey: [...CELLAR, "analysis-trend", id] });
    },
  });
}

export const useUpdateWineLot = (id: string) =>
  useLotMutation<Partial<WineLotInput> & { status?: string }>((i, a) => wineLotsApi.update(i, a), id);
export const useAssignVessel = (id: string) =>
  useLotMutation<{ vesselId: string; volume: number }>((i, a) => wineLotsApi.assignVessel(i, a.vesselId, a.volume), id);
export const useUnassignVessel = (id: string) =>
  useLotMutation<string>((i, vesselLotId) => wineLotsApi.unassignVessel(i, vesselLotId), id);
export const useAdjustLotVolume = (id: string) =>
  useLotMutation<{ delta: number; vesselId?: string | null }>((i, a) => wineLotsApi.adjustVolume(i, a.delta, a.vesselId), id);
export const useAddAnalysis = (id: string) =>
  useLotMutation<AnalysisInput>((i, a) => wineLotsApi.addAnalysis(i, a), id);
export const useDeleteAnalysis = (id: string) =>
  useLotMutation<string>((i, aid) => wineLotsApi.deleteAnalysis(i, aid), id);
export const useAddAddition = (id: string) =>
  useLotMutation<AdditionInput>((i, a) => wineLotsApi.addAddition(i, a), id);
export const useDeleteAddition = (id: string) =>
  useLotMutation<string>((i, aid) => wineLotsApi.deleteAddition(i, aid), id);
export const useAddProcess = (id: string) =>
  useLotMutation<ProcessInput>((i, a) => wineLotsApi.addProcess(i, a), id);
export const useDeleteProcess = (id: string) =>
  useLotMutation<string>((i, pid) => wineLotsApi.deleteProcess(i, pid), id);
export const useAddTasting = (id: string) =>
  useLotMutation<TastingNoteInput>((i, a) => wineLotsApi.addTasting(i, a), id);
export const useDeleteTasting = (id: string) =>
  useLotMutation<string>((i, nid) => wineLotsApi.deleteTasting(i, nid), id);
export const useAddTransfer = (id: string) =>
  useLotMutation<TransferInput>((i, a) => wineLotsApi.addTransfer(i, a), id);
export const useDeleteTransfer = (id: string) =>
  useLotMutation<string>((i, tid) => wineLotsApi.deleteTransfer(i, tid), id);
export const useAddBottling = (id: string) =>
  useLotMutation<BottlingInput>((i, a) => wineLotsApi.addBottling(i, a), id);
export const useAssignProtocol = (id: string) =>
  useLotMutation<string | null>((i, tid) => wineLotsApi.assignProtocol(i, tid), id);
export const useGenerateProtocol = (id: string) =>
  useLotMutation<void>((i) => wineLotsApi.generateProtocol(i), id);

/* --- Enological products, templates, monitor ------------------------------ */

export function useEnologicalProducts(params: { active_only?: boolean } = {}) {
  return useQuery({
    queryKey: [...CELLAR, "enological", params],
    queryFn: () => enologicalApi.list(params),
  });
}

export function useCreateEnologicalProduct() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: EnologicalProductInput) => enologicalApi.create(input),
    onSuccess: () => void qc.invalidateQueries({ queryKey: [...CELLAR, "enological"] }),
  });
}

export function useUpdateEnologicalProduct() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (vars: { id: string; input: Partial<EnologicalProductInput> & { is_active?: boolean } }) =>
      enologicalApi.update(vars.id, vars.input),
    onSuccess: () => void qc.invalidateQueries({ queryKey: [...CELLAR, "enological"] }),
  });
}

export function useAdjustEnologicalStock() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (vars: { id: string; delta: number }) => enologicalApi.adjustStock(vars.id, vars.delta),
    onSuccess: () => void qc.invalidateQueries({ queryKey: [...CELLAR, "enological"] }),
  });
}

export function useDeleteEnologicalProduct() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => enologicalApi.remove(id),
    onSuccess: () => void qc.invalidateQueries({ queryKey: [...CELLAR, "enological"] }),
  });
}

/* --- Reports, tastings, bulk ops, blend ----------------------------------- */

export function useCellarCosts() {
  return useQuery({ queryKey: [...CELLAR, "costs"], queryFn: () => cellarReportsApi.costs() });
}

export function useCellarAnalytics() {
  return useQuery({ queryKey: [...CELLAR, "analytics"], queryFn: () => cellarReportsApi.analytics() });
}

export function useTastingReports() {
  return useQuery({ queryKey: [...CELLAR, "tastings"], queryFn: () => tastingsApi.list() });
}

export function useCreateTastingReport() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: TastingReportInput) => tastingsApi.create(input),
    onSuccess: () => void qc.invalidateQueries({ queryKey: [...CELLAR, "tastings"] }),
  });
}

export function useBulkAnalyses() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (analyses: Array<AnalysisInput & { wine_lot_id: string }>) => wineLotsApi.bulkAnalyses(analyses),
    onSuccess: () => void qc.invalidateQueries({ queryKey: CELLAR }),
  });
}

export function useBulkAdditions() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: BulkAdditionsInput) => wineLotsApi.bulkAdditions(input),
    onSuccess: () => void qc.invalidateQueries({ queryKey: CELLAR }),
  });
}

export function useExecuteBlend() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: BlendInput) => wineLotsApi.blend(input),
    onSuccess: () => void qc.invalidateQueries({ queryKey: CELLAR }),
  });
}

export function useFermentationTemplates(params: { active_only?: boolean } = {}) {
  return useQuery({
    queryKey: [...CELLAR, "templates", params],
    queryFn: () => fermentationTemplatesApi.list(params),
  });
}

export function useCreateFermentationTemplate() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: FermentationTemplateInput) => fermentationTemplatesApi.create(input),
    onSuccess: () => void qc.invalidateQueries({ queryKey: [...CELLAR, "templates"] }),
  });
}

export function useFermentationMonitor() {
  return useQuery({
    queryKey: [...CELLAR, "monitor"],
    queryFn: () => fermentationApi.monitor(),
    staleTime: 60_000,
  });
}
