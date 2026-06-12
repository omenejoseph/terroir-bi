"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { aiImportsApi } from "@/lib/api/ai-imports";
import { putToBucket, uploadsApi } from "@/lib/api/uploads";
import type { AiImportType, UpdateAiImportLineInput } from "@/lib/types";

const KEY = ["ai-imports"] as const;

export function useAiImports() {
  return useQuery({
    queryKey: KEY,
    queryFn: aiImportsApi.list,
    // Keep the list live while any import is still extracting.
    refetchInterval: (query) => {
      const data = query.state.data;
      const busy = Array.isArray(data) && data.some((i) => i.status === "uploaded" || i.status === "processing");
      return busy ? 4000 : false;
    },
  });
}

export function useAiImport(id: string) {
  return useQuery({
    queryKey: [...KEY, id],
    queryFn: () => aiImportsApi.get(id),
    enabled: !!id,
    // Poll while the document is still being extracted.
    refetchInterval: (query) => {
      const status = query.state.data?.status;
      return status === "uploaded" || status === "processing" ? 2000 : false;
    },
  });
}

/** Full upload flow: presign → PUT to bucket → create import (queues extraction). */
export function useUploadAiImport() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({ type, file }: { type: AiImportType; file: File }) => {
      const contentType = file.type || "application/octet-stream";
      const presign = await uploadsApi.presign({
        purpose: "ai_import",
        filename: file.name,
        content_type: contentType,
        size: file.size,
      });
      await putToBucket(presign, file);
      return aiImportsApi.create({
        type,
        object_key: presign.key,
        filename: file.name,
        mime: contentType,
      });
    },
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: KEY }),
  });
}

export function useUpdateAiImportLine(importId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ lineId, input }: { lineId: string; input: UpdateAiImportLineInput }) =>
      aiImportsApi.updateLine(importId, lineId, input),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: [...KEY, importId] }),
  });
}

export function useCommitAiImport(importId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => aiImportsApi.commit(importId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: [...KEY, importId] });
      void queryClient.invalidateQueries({ queryKey: KEY });
    },
  });
}

export function useDeleteAiImport() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => aiImportsApi.remove(id),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: KEY }),
  });
}
