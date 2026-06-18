import { api } from "@/lib/api/client";
import type {
  GrapeContract,
  GrapeContractInput,
  GrowerPerformance,
  HarvestEntryInput,
  HarvestPlan,
  HarvestPlanInput,
  IntakeBooking,
  Parcel,
  ParcelInput,
  RecordIntakeInput,
} from "@/lib/types";

/** Vineyard parcels + agronomy. Mirrors routes/api.php (vineyard-parcels/*). */
export const parcelsApi = {
  list: () => api.get<Parcel[]>("/vineyard-parcels"),
  get: (id: string) => api.get<Parcel>(`/vineyard-parcels/${id}`),
  create: (input: ParcelInput) => api.post<Parcel>("/vineyard-parcels", input),
  update: (id: string, input: Partial<ParcelInput> & { is_active?: boolean }) =>
    api.patch<Parcel>(`/vineyard-parcels/${id}`, input),
  remove: (id: string) => api.delete<void>(`/vineyard-parcels/${id}`),
  addMaturity: (id: string, input: Record<string, unknown>) =>
    api.post<Parcel>(`/vineyard-parcels/${id}/maturity-samples`, input),
  addPhenology: (id: string, input: Record<string, unknown>) =>
    api.post<Parcel>(`/vineyard-parcels/${id}/phenology-logs`, input),
  addCropEstimate: (id: string, input: Record<string, unknown>) =>
    api.post<Parcel>(`/vineyard-parcels/${id}/crop-estimates`, input),
  addApplication: (id: string, input: Record<string, unknown>) =>
    api.post<Parcel>(`/vineyard-parcels/${id}/applications`, input),
};

export const grapeContractsApi = {
  list: (season?: string) => api.get<GrapeContract[]>("/grape-contracts", { season }),
  create: (input: GrapeContractInput) => api.post<GrapeContract>("/grape-contracts", input),
  update: (id: string, input: Partial<GrapeContractInput> & { delivered_kg?: number }) =>
    api.patch<GrapeContract>(`/grape-contracts/${id}`, input),
  remove: (id: string) => api.delete<void>(`/grape-contracts/${id}`),
  performance: (supplierId: string) => api.get<GrowerPerformance>(`/grape-contracts/performance/${supplierId}`),
};

export const harvestPlansApi = {
  list: () => api.get<HarvestPlan[]>("/harvest-plans"),
  get: (id: string) => api.get<HarvestPlan>(`/harvest-plans/${id}`),
  create: (input: HarvestPlanInput) => api.post<HarvestPlan>("/harvest-plans", input),
  update: (id: string, input: Partial<HarvestPlanInput>) => api.patch<HarvestPlan>(`/harvest-plans/${id}`, input),
  remove: (id: string) => api.delete<void>(`/harvest-plans/${id}`),
  addEntry: (id: string, input: HarvestEntryInput) => api.post<HarvestPlan>(`/harvest-plans/${id}/entries`, input),
  removeEntry: (id: string, entryId: string) => api.delete<HarvestPlan>(`/harvest-plans/${id}/entries/${entryId}`),
  recordIntake: (id: string, entryId: string, input: RecordIntakeInput) =>
    api.post<HarvestPlan>(`/harvest-plans/${id}/entries/${entryId}/intake`, input),
};

export const intakeBookingsApi = {
  list: (harvestPlanId?: string) => api.get<IntakeBooking[]>("/intake-bookings", { harvest_plan_id: harvestPlanId }),
  create: (input: Record<string, unknown>) => api.post<IntakeBooking>("/intake-bookings", input),
  remove: (id: string) => api.delete<void>(`/intake-bookings/${id}`),
};
