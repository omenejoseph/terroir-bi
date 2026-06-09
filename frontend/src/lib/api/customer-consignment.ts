import { api } from "@/lib/api/client";
import type {
  CustomerConsignmentReturnInput,
  CustomerConsignmentSaleInput,
  CustomerConsignmentSummary,
  PlaceConsignmentInput,
} from "@/lib/types";

/** Customer-level (FIFO) consignment. Mirrors customers/{id}/consignment routes. */
export const customerConsignmentApi = {
  summary: (customerId: string) =>
    api.get<CustomerConsignmentSummary>(`/customers/${customerId}/consignment`),

  place: (customerId: string, input: PlaceConsignmentInput) =>
    api.post<{ order_number: string }>(`/customers/${customerId}/consignment/place`, input),

  sale: (customerId: string, input: CustomerConsignmentSaleInput) =>
    api.post<void>(`/customers/${customerId}/consignment/sale`, input),

  recordReturn: (customerId: string, input: CustomerConsignmentReturnInput) =>
    api.post<void>(`/customers/${customerId}/consignment/return`, input),
};
