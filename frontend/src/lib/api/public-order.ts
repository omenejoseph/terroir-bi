import { api } from "@/lib/api/client";
import type { PublicCatalog, PublicOrderInput } from "@/lib/types";

/**
 * The public, token-authenticated self-service order flow. The token in the URL
 * is the credential (and selects the tenant) — no bearer auth. The shared `api`
 * client simply omits the Authorization header when there's no logged-in token.
 */
export const publicOrderApi = {
  catalog: (token: string) => api.get<PublicCatalog>(`/public/${token}/catalog`),
  placeOrder: (token: string, input: PublicOrderInput) =>
    api.post<{ order_number: string }>(`/public/${token}/orders`, input),
};
