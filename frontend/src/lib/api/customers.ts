import { api } from "@/lib/api/client";
import type {
  Customer,
  CustomerInput,
  CustomerQuery,
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

  /** GET /pricing-tiers — for the tier picker. */
  pricingTiers: () => api.get<PricingTier[]>("/pricing-tiers"),

  /** POST /pricing-tiers — requires pricing.manage. */
  createPricingTier: (input: PricingTierInput) => api.post<PricingTier>("/pricing-tiers", input),
};