import { api } from "@/lib/api/client";

/**
 * Tenant-managed translation overrides. GET /translations?lang=xx returns the
 * overrides for a locale. Shape is left loose because the backend keys its own
 * namespaces (auth.*, iam.*, …); we coerce to a flat key→value map so admins can
 * also override any frontend UI key by matching its dot-path.
 */
export const translationsApi = {
  overrides: async (locale: string): Promise<Record<string, string>> => {
    const raw = await api.get<unknown>("/translations", { lang: locale });
    return flatten(raw);
  },
};

/** Flatten a possibly-nested override payload into dot-path → string. */
function flatten(value: unknown, prefix = ""): Record<string, string> {
  const out: Record<string, string> = {};
  if (value === null || typeof value !== "object") return out;

  for (const [key, val] of Object.entries(value as Record<string, unknown>)) {
    const path = prefix ? `${prefix}.${key}` : key;
    if (val !== null && typeof val === "object") {
      Object.assign(out, flatten(val, path));
    } else if (val !== null && val !== undefined) {
      out[path] = String(val);
    }
  }
  return out;
}