import { api, ApiError } from "@/lib/api/client";
import { API_URL, STORAGE_KEYS } from "@/lib/config";
import type { PresignInput, PresignResult } from "@/lib/types";

function authHeaders(): Record<string, string> {
  const token = typeof window !== "undefined" ? window.localStorage.getItem(STORAGE_KEYS.token) : null;
  return token ? { Authorization: `Bearer ${token}` } : {};
}

export const uploadsApi = {
  /** POST /uploads/presign — get a direct-to-bucket PUT target. */
  presign: (input: PresignInput) => api.post<PresignResult>("/uploads/presign", input),

  /**
   * POST /uploads/remove-background — proxy an image through the background
   * remover (key stays server-side) and get the cut-out PNG back as a Blob.
   */
  removeBackground: async (file: Blob, filename = "image.png"): Promise<Blob> => {
    const form = new FormData();
    form.append("image", file, filename);

    const res = await fetch(`${API_URL}/uploads/remove-background`, {
      method: "POST",
      headers: { Accept: "image/png, application/json", ...authHeaders() },
      body: form,
    });

    if (!res.ok) {
      let message = "Background removal failed.";
      let errors: Record<string, string[]> | undefined;
      try {
        const body = (await res.json()) as { message?: string; errors?: Record<string, string[]> };
        message = body.message ?? message;
        errors = body.errors;
      } catch {
        /* non-JSON error body */
      }
      throw new ApiError(res.status, message, errors);
    }

    return res.blob();
  },
};

/** Upload a blob straight to the bucket using a presigned PUT target. */
export async function putToBucket(presign: PresignResult, blob: Blob): Promise<void> {
  const res = await fetch(presign.url, {
    method: presign.method,
    headers: presign.headers,
    body: blob,
  });
  if (!res.ok) {
    throw new ApiError(res.status, "Upload to storage failed.");
  }
}
