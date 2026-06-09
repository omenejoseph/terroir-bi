"use client";

import { useMutation, useQuery } from "@tanstack/react-query";

import { publicOrderApi } from "@/lib/api/public-order";
import type { PublicOrderInput } from "@/lib/types";

export function usePublicCatalog(token: string | undefined) {
  return useQuery({
    queryKey: ["public-catalog", token],
    queryFn: () => publicOrderApi.catalog(token!),
    enabled: !!token,
    retry: false,
  });
}

export function usePlacePublicOrder(token: string) {
  return useMutation({
    mutationFn: (input: PublicOrderInput) => publicOrderApi.placeOrder(token, input),
  });
}
