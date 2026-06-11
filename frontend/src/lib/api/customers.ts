import { api } from "@/lib/api/client";
import type {
  Customer,
  CustomerAnalytics,
  MergeCustomersInput,
  MergePreview,
  CustomerCustomPrice,
  CustomerInput,
  CustomerOrderAnalytics,
  CustomerQuery,
  Money,
  PaginationMeta,
  PricingTier,
  PricingTierInput,
} from "@/lib/types";

/** Customer + pricing-tier endpoints. Mirrors routes/api.php. */
export const customersApi = {
  /** GET /customers — paginated, filterable. */
  list: (query: CustomerQuery = {}): Promise<{ data: Customer[]; meta?: PaginationMeta }> =>
    api.getPage<Customer[]>("/customers", {
      search: query.search,
      is_active: query.is_active,
      pricing_tier_id: query.pricing_tier_id,
    }),

  /** GET /customers/{id}. */
  get: (id: string) => api.get<Customer>(`/customers/${id}`),

  /** POST /customers — requires customers.manage. */
  create: (input: CustomerInput) => api.post<Customer>("/customers", input),

  /** PATCH /customers/{id} — requires customers.manage. */
  update: (id: string, input: Partial<CustomerInput>) =>
    api.patch<Customer>(`/customers/${id}`, input),

  /** DELETE /customers/{id} — requires customers.delete. */
  delete: (id: string) => api.delete<void>(`/customers/${id}`),

  /** GET /customers/analytics — tenant-wide customer analytics. */
  analytics: () => api.get<CustomerAnalytics>("/customers/analytics"),

  /** GET /customers/{id}/resolved-prices — per-bottle price for each item (custom/tier/rebate/default). */
  resolvedPrices: (customerId: string, itemIds: string[]) =>
    api.get<Record<string, Money>>(`/customers/${customerId}/resolved-prices`, {
      item_ids: itemIds.join(","),
    }),

  /** POST /customers/merge/preview — what would move (requires customers.manage). */
  mergePreview: (input: MergeCustomersInput) =>
    api.post<MergePreview>("/customers/merge/preview", input),

  /** POST /customers/merge — apply the merge (requires customers.delete). */
  merge: (input: MergeCustomersInput) => api.post<MergePreview>("/customers/merge", input),

  /** GET /pricing-tiers — for the tier picker. */
  pricingTiers: () => api.get<PricingTier[]>("/pricing-tiers"),

  /** POST /pricing-tiers — requires pricing.manage. */
  createPricingTier: (input: PricingTierInput) => api.post<PricingTier>("/pricing-tiers", input),

  /** GET /customers/{id}/order-analytics — requires financials.view. */
  orderAnalytics: (id: string) =>
    api.get<CustomerOrderAnalytics>(`/customers/${id}/order-analytics`),

  /** GET /customers/{id}/custom-prices — the customer's negotiated prices. */
  customPrices: (id: string) => api.get<CustomerCustomPrice[]>(`/customers/${id}/custom-prices`),

  /** PUT /inventory-items/{item}/customer-price/{customer} — requires pricing.manage. */
  setCustomPrice: (itemId: string, customerId: string, minor: number) =>
    api.put<Money>(`/inventory-items/${itemId}/customer-price/${customerId}`, { price: minor }),

  /** DELETE /inventory-items/{item}/customer-price/{customer}. */
  removeCustomPrice: (itemId: string, customerId: string) =>
    api.delete<void>(`/inventory-items/${itemId}/customer-price/${customerId}`),

  /** Self-service order token (requires customers.tokens). */
  orderToken: (id: string) =>
    api.get<{ order_token: string | null }>(`/customers/${id}/order-token`),
  generateToken: (id: string) =>
    api.post<Customer & { order_token: string }>(`/customers/${id}/order-token`),
  revokeToken: (id: string) => api.delete<Customer>(`/customers/${id}/order-token`),
};