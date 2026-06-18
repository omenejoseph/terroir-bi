import { api } from "@/lib/api/client";
import type {
  AdditionInput,
  AnalysisInput,
  AnalysisTrendPoint,
  BottlingInput,
  BulkCreateVesselsInput,
  EnologicalProduct,
  EnologicalProductInput,
  FermentationAlert,
  FermentationTemplate,
  FermentationTemplateInput,
  PaginationMeta,
  ProcessInput,
  ProtocolGenerateResult,
  TastingNoteInput,
  TransferInput,
  Vessel,
  VesselInput,
  VesselLayoutUpdate,
  WineLot,
  WineLotInput,
  WineLotQuery,
} from "@/lib/types";

/** Cellar vessels (incl. the cellar-map layout). Mirrors routes/api.php (vessels/*). */
export const vesselsApi = {
  /** GET /vessels — vessels with their lots (for the cellar map). */
  list: (params: { room?: string; active_only?: boolean } = {}) =>
    api.get<Vessel[]>("/vessels", params),

  get: (id: string) => api.get<Vessel>(`/vessels/${id}`),

  create: (input: VesselInput) => api.post<Vessel>("/vessels", input),

  /** POST /vessels/bulk — create a run of sequentially-named vessels. */
  bulkCreate: (input: BulkCreateVesselsInput) => api.post<Vessel[]>("/vessels/bulk", input),

  update: (id: string, input: Partial<VesselInput> & { status?: string; is_active?: boolean }) =>
    api.patch<Vessel>(`/vessels/${id}`, input),

  /** PATCH /vessels/layout — batch position/size/room (drag-and-drop persistence). */
  saveLayout: (updates: VesselLayoutUpdate[]) =>
    api.patch<void>("/vessels/layout", { updates }),

  /** POST /vessels/rename-room — rename a room across its vessels. */
  renameRoom: (from: string, to: string) =>
    api.post<{ updated: number }>("/vessels/rename-room", { from, to }),

  remove: (id: string) => api.delete<void>(`/vessels/${id}`),
};

/** Wine lots and their full lifecycle. Mirrors routes/api.php (wine-lots/*). */
export const wineLotsApi = {
  list: (query: WineLotQuery = {}): Promise<{ data: WineLot[]; meta?: PaginationMeta }> =>
    api.getPage<WineLot[]>("/wine-lots", {
      status: query.status,
      search: query.search,
      exclude_bottled: query.exclude_bottled,
      page: query.page,
    }),

  get: (id: string) => api.get<WineLot>(`/wine-lots/${id}`),

  create: (input: WineLotInput) => api.post<WineLot>("/wine-lots", input),

  update: (id: string, input: Partial<WineLotInput> & { status?: string }) =>
    api.patch<WineLot>(`/wine-lots/${id}`, input),

  assignVessel: (id: string, vesselId: string, volume: number) =>
    api.post<WineLot>(`/wine-lots/${id}/vessels`, { vessel_id: vesselId, volume }),

  unassignVessel: (id: string, vesselLotId: string) =>
    api.delete<WineLot>(`/wine-lots/${id}/vessels/${vesselLotId}`),

  adjustVolume: (id: string, delta: number, vesselId?: string | null) =>
    api.post<WineLot>(`/wine-lots/${id}/adjust-volume`, { delta, vessel_id: vesselId ?? null }),

  // Analyses.
  analysisTrend: (id: string) => api.get<AnalysisTrendPoint[]>(`/wine-lots/${id}/analyses/trend`),
  addAnalysis: (id: string, input: AnalysisInput) =>
    api.post<WineLot>(`/wine-lots/${id}/analyses`, input),
  updateAnalysis: (id: string, analysisId: string, input: AnalysisInput) =>
    api.patch<WineLot>(`/wine-lots/${id}/analyses/${analysisId}`, input),
  deleteAnalysis: (id: string, analysisId: string) =>
    api.delete<WineLot>(`/wine-lots/${id}/analyses/${analysisId}`),
  bulkAnalyses: (analyses: Array<AnalysisInput & { wine_lot_id: string }>) =>
    api.post<{ created: number }>("/wine-lots/analyses/bulk", { analyses }),

  // Additions / processes / tastings.
  addAddition: (id: string, input: AdditionInput) =>
    api.post<WineLot>(`/wine-lots/${id}/additions`, input),
  deleteAddition: (id: string, additionId: string) =>
    api.delete<WineLot>(`/wine-lots/${id}/additions/${additionId}`),
  addProcess: (id: string, input: ProcessInput) =>
    api.post<WineLot>(`/wine-lots/${id}/processes`, input),
  deleteProcess: (id: string, processId: string) =>
    api.delete<WineLot>(`/wine-lots/${id}/processes/${processId}`),
  addTasting: (id: string, input: TastingNoteInput) =>
    api.post<WineLot>(`/wine-lots/${id}/tasting-notes`, input),
  deleteTasting: (id: string, noteId: string) =>
    api.delete<WineLot>(`/wine-lots/${id}/tasting-notes/${noteId}`),

  // Transfers / bottling.
  addTransfer: (id: string, input: TransferInput) =>
    api.post<WineLot>(`/wine-lots/${id}/transfers`, input),
  deleteTransfer: (id: string, transferId: string) =>
    api.delete<WineLot>(`/wine-lots/${id}/transfers/${transferId}`),
  addBottling: (id: string, input: BottlingInput) =>
    api.post<WineLot>(`/wine-lots/${id}/bottlings`, input),
  deleteBottling: (id: string, bottlingId: string) =>
    api.delete<WineLot>(`/wine-lots/${id}/bottlings/${bottlingId}`),

  // Protocol.
  assignProtocol: (id: string, templateId: string | null) =>
    api.post<WineLot>(`/wine-lots/${id}/protocol`, { fermentation_template_id: templateId }),
  generateProtocol: (id: string) =>
    api.post<ProtocolGenerateResult>(`/wine-lots/${id}/protocol/generate`),
};

/** Enological products (additives). */
export const enologicalApi = {
  list: (params: { active_only?: boolean } = {}) =>
    api.get<EnologicalProduct[]>("/enological-products", params),
  create: (input: EnologicalProductInput) =>
    api.post<EnologicalProduct>("/enological-products", input),
  update: (id: string, input: Partial<EnologicalProductInput> & { is_active?: boolean }) =>
    api.patch<EnologicalProduct>(`/enological-products/${id}`, input),
  adjustStock: (id: string, delta: number) =>
    api.post<EnologicalProduct>(`/enological-products/${id}/adjust-stock`, { delta }),
  remove: (id: string) => api.delete<void>(`/enological-products/${id}`),
};

/** Fermentation protocol templates. */
export const fermentationTemplatesApi = {
  list: (params: { active_only?: boolean } = {}) =>
    api.get<FermentationTemplate[]>("/fermentation-templates", params),
  create: (input: FermentationTemplateInput) =>
    api.post<FermentationTemplate>("/fermentation-templates", input),
  update: (id: string, input: Partial<FermentationTemplateInput>) =>
    api.patch<FermentationTemplate>(`/fermentation-templates/${id}`, input),
  remove: (id: string) => api.delete<void>(`/fermentation-templates/${id}`),
};

/** Fermentation monitor (read-only alerts). */
export const fermentationApi = {
  monitor: () => api.get<FermentationAlert[]>("/cellar/fermentation-monitor"),
};
