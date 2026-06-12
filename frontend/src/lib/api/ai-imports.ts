import { api } from "@/lib/api/client";
import type {
  AiImport,
  AiImportLine,
  CreateAiImportInput,
  UpdateAiImportLineInput,
} from "@/lib/types";

/** AI data-entry endpoints. Mirrors routes/api.php (ai-imports). */
export const aiImportsApi = {
  /** GET /ai-imports — recent imports with line counts. */
  list: () => api.get<AiImport[]>("/ai-imports"),

  /** GET /ai-imports/{id} — import with its proposed lines. */
  get: (id: string) => api.get<AiImport>(`/ai-imports/${id}`),

  /** POST /ai-imports — create from an uploaded object key; extraction is queued. */
  create: (input: CreateAiImportInput) => api.post<AiImport>("/ai-imports", input),

  /** PATCH /ai-imports/{id}/lines/{line} — approve / reject / edit a line. */
  updateLine: (importId: string, lineId: string, input: UpdateAiImportLineInput) =>
    api.patch<AiImportLine>(`/ai-imports/${importId}/lines/${lineId}`, input),

  /** POST /ai-imports/{id}/commit — commit all approved/edited lines; returns the refreshed import. */
  commit: (id: string) => api.post<AiImport>(`/ai-imports/${id}/commit`, {}),

  /** DELETE /ai-imports/{id}. */
  remove: (id: string) => api.delete<void>(`/ai-imports/${id}`),
};
