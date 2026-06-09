import { API_URL, DEFAULT_LOCALE, STORAGE_KEYS } from "@/lib/config";
import type { ApiErrorBody, ApiEnvelope, PaginationMeta } from "@/lib/types";

/**
 * The single HTTP boundary between the app and the Laravel API.
 *
 * - Injects the Bearer token + JSON headers on every request.
 * - Unwraps the { data, meta } envelope.
 * - Normalises errors into a typed ApiError (with field-level validation errors).
 *
 * Nothing else in the app calls fetch() directly — swapping transport, auth, or
 * base URL is a change confined to this file.
 */

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly errors?: Record<string, string[]>,
  ) {
    super(message);
    this.name = "ApiError";
  }

  /** First validation message for a field, if any. */
  fieldError(field: string): string | undefined {
    return this.errors?.[field]?.[0];
  }
}

function getToken(): string | null {
  if (typeof window === "undefined") return null;
  return window.localStorage.getItem(STORAGE_KEYS.token);
}

/** Active locale to advertise to the API via X-Locale (server reads this). */
function getLocale(): string {
  if (typeof window === "undefined") return DEFAULT_LOCALE;
  return window.localStorage.getItem(STORAGE_KEYS.locale) ?? DEFAULT_LOCALE;
}

interface RequestOptions extends Omit<RequestInit, "body"> {
  /** JSON body — serialised automatically. */
  body?: unknown;
  /** Query string params; null/undefined are skipped. */
  params?: Record<string, string | number | boolean | null | undefined>;
}

function buildUrl(path: string, params?: RequestOptions["params"]): string {
  const url = new URL(`${API_URL}${path.startsWith("/") ? path : `/${path}`}`);
  if (params) {
    for (const [key, value] of Object.entries(params)) {
      if (value !== null && value !== undefined) {
        url.searchParams.set(key, String(value));
      }
    }
  }
  return url.toString();
}

async function request<T>(path: string, options: RequestOptions = {}): Promise<{ data: T; meta?: PaginationMeta }> {
  const { body, params, headers, ...rest } = options;
  const token = getToken();

  const response = await fetch(buildUrl(path, params), {
    ...rest,
    headers: {
      Accept: "application/json",
      "X-Locale": getLocale(),
      ...(body !== undefined ? { "Content-Type": "application/json" } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...headers,
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  // 204 No Content (e.g. logout, delete).
  if (response.status === 204) {
    return { data: undefined as T };
  }

  const text = await response.text();
  const json = text ? (JSON.parse(text) as ApiEnvelope<T> & ApiErrorBody) : ({} as ApiErrorBody);

  if (!response.ok) {
    throw new ApiError(
      response.status,
      json.message ?? `Request failed (${response.status})`,
      json.errors,
    );
  }

  return { data: (json as ApiEnvelope<T>).data, meta: (json as ApiEnvelope<T>).meta };
}

/** Thin verb helpers. Use `.full` when you need the pagination `meta` too. */
export const api = {
  get: <T>(path: string, params?: RequestOptions["params"]) =>
    request<T>(path, { method: "GET", params }).then((r) => r.data),
  getPage: <T>(path: string, params?: RequestOptions["params"]) =>
    request<T>(path, { method: "GET", params }),
  post: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: "POST", body }).then((r) => r.data),
  patch: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: "PATCH", body }).then((r) => r.data),
  put: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: "PUT", body }).then((r) => r.data),
  delete: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: "DELETE", body }).then((r) => r.data),
};