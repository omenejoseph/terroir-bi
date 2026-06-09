import { api } from "@/lib/api/client";
import type { ArAging, CashFlow, OrderPayments, RecordPaymentInput } from "@/lib/types";

/** Cash-flow, A/R aging and order-payment endpoints. Mirrors routes/api.php. */
export const financeApi = {
  /** GET /cash-flow — historical + forecast + pending balances. */
  cashFlow: () => api.get<CashFlow>("/cash-flow"),

  /** GET /inflows/aging — A/R aging buckets + by-customer breakdown. */
  arAging: () => api.get<ArAging>("/inflows/aging"),

  /** GET /orders/{id}/payments — summary + payment history. */
  orderPayments: (orderId: string) => api.get<OrderPayments>(`/orders/${orderId}/payments`),

  /** POST /orders/{id}/payments — amount is integer minor units. */
  recordOrderPayment: (orderId: string, input: RecordPaymentInput) =>
    api.post<OrderPayments>(`/orders/${orderId}/payments`, input),
};
