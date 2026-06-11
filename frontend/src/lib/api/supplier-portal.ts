import { api } from "@/lib/api/client";
import type { PriceImportResult, SupplierOrder, SupplierPortal } from "@/lib/types";

/**
 * The public, token-authenticated supplier portal. The token in the URL is the
 * credential (and selects the tenant) — no bearer auth. The shared `api` client
 * omits the Authorization header when there's no logged-in token.
 */
export const supplierPortalApi = {
  get: (token: string) => api.get<SupplierPortal>(`/public/supplier/${token}`),

  importPriceItems: (token: string, items: { description: string; unit_price: number; unit: string | null }[]) =>
    api.post<PriceImportResult>(`/public/supplier/${token}/price-items/import`, { items }),

  confirmOrder: (token: string, orderId: string) =>
    api.patch<SupplierOrder>(`/public/supplier/${token}/orders/${orderId}/confirm`, {}),
};
