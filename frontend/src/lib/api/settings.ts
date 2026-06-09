import { api } from "@/lib/api/client";
import type { OrganizationSettings, OrganizationSettingsInput } from "@/lib/types";

/** Organisation settings. Mirrors routes/api.php (settings). */
export const settingsApi = {
  /** GET /settings — current org settings (any member). */
  get: () => api.get<OrganizationSettings>("/settings"),

  /** PATCH /settings — update org settings (ADMIN). Currency is read-only. */
  update: (input: OrganizationSettingsInput) =>
    api.patch<OrganizationSettings>("/settings", input),
};
