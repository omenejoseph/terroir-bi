"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { customerConsignmentApi } from "@/lib/api/customer-consignment";
import type {
  CustomerConsignmentReturnInput,
  CustomerConsignmentSaleInput,
  PlaceConsignmentInput,
} from "@/lib/types";

export function useCustomerConsignment(customerId: string | undefined) {
  return useQuery({
    queryKey: ["customers", "consignment", customerId],
    queryFn: () => customerConsignmentApi.summary(customerId!),
    enabled: !!customerId,
  });
}

function useInvalidator(customerId: string) {
  const queryClient = useQueryClient();
  return () =>
    void queryClient.invalidateQueries({ queryKey: ["customers", "consignment", customerId] });
}

export function usePlaceConsignment(customerId: string) {
  const invalidate = useInvalidator(customerId);
  return useMutation({
    mutationFn: (input: PlaceConsignmentInput) => customerConsignmentApi.place(customerId, input),
    onSuccess: invalidate,
  });
}

export function useCustomerConsignmentSale(customerId: string) {
  const invalidate = useInvalidator(customerId);
  return useMutation({
    mutationFn: (input: CustomerConsignmentSaleInput) =>
      customerConsignmentApi.sale(customerId, input),
    onSuccess: invalidate,
  });
}

export function useCustomerConsignmentReturn(customerId: string) {
  const invalidate = useInvalidator(customerId);
  return useMutation({
    mutationFn: (input: CustomerConsignmentReturnInput) =>
      customerConsignmentApi.recordReturn(customerId, input),
    onSuccess: invalidate,
  });
}
