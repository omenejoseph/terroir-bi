"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { settingsApi } from "@/lib/api/settings";
import type { OrganizationSettingsInput } from "@/lib/types";

export function useSettings() {
  return useQuery({
    queryKey: ["settings"],
    queryFn: () => settingsApi.get(),
    staleTime: 60_000,
  });
}

export function useUpdateSettings() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: OrganizationSettingsInput) => settingsApi.update(input),
    onSuccess: (data) => queryClient.setQueryData(["settings"], data),
  });
}
