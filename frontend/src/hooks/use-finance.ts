"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { financeApi } from "@/lib/api/finance";
import type { RecordPaymentInput } from "@/lib/types";

export function useCashFlow() {
  return useQuery({
    queryKey: ["cash-flow"],
    queryFn: () => financeApi.cashFlow(),
  });
}

export function useArAging() {
  return useQuery({
    queryKey: ["ar-aging"],
    queryFn: () => financeApi.arAging(),
  });
}

export function useOrderPayments(orderId: string | undefined) {
  return useQuery({
    queryKey: ["orders", orderId, "payments"],
    queryFn: () => financeApi.orderPayments(orderId!),
    enabled: !!orderId,
  });
}

export function useRecordPayment(orderId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: RecordPaymentInput) => financeApi.recordOrderPayment(orderId, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["orders", orderId, "payments"] });
      void queryClient.invalidateQueries({ queryKey: ["ar-aging"] });
      void queryClient.invalidateQueries({ queryKey: ["cash-flow"] });
      void queryClient.invalidateQueries({ queryKey: ["dashboard"] });
    },
  });
}
