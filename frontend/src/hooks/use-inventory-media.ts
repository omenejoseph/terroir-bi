"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { inventoryApi } from "@/lib/api/inventory";
import { putToBucket, uploadsApi } from "@/lib/api/uploads";
import { extensionForType } from "@/lib/image";

export function useInventoryImages(itemId: string, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: ["inventory", "images", itemId],
    queryFn: () => inventoryApi.images(itemId),
    enabled: (options?.enabled ?? true) && !!itemId,
  });
}

/** Orchestrates the full upload: presign → PUT to bucket → attach. */
export function useUploadInventoryImage(itemId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({ blob, alt }: { blob: Blob; alt?: string | null }) => {
      const contentType = blob.type || "image/webp";
      const presign = await uploadsApi.presign({
        purpose: "inventory_image",
        filename: `image.${extensionForType(contentType)}`,
        content_type: contentType,
        size: blob.size,
      });
      await putToBucket(presign, blob);
      return inventoryApi.attachImage(itemId, {
        key: presign.key,
        content_type: presign.content_type,
        alt: alt ?? null,
      });
    },
    onSuccess: () =>
      void queryClient.invalidateQueries({ queryKey: ["inventory", "images", itemId] }),
  });
}

export function useDeleteInventoryImage(itemId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (imageId: string) => inventoryApi.deleteImage(itemId, imageId),
    onSuccess: () =>
      void queryClient.invalidateQueries({ queryKey: ["inventory", "images", itemId] }),
  });
}

/** Proxy an original image through the background remover; returns the cut-out Blob. */
export function useRemoveBackground() {
  return useMutation({
    mutationFn: (file: Blob) => uploadsApi.removeBackground(file),
  });
}

export function useInventoryDocuments(itemId: string, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: ["inventory", "documents", itemId],
    queryFn: () => inventoryApi.documents(itemId),
    enabled: (options?.enabled ?? true) && !!itemId,
  });
}

/** Orchestrates the full document upload: presign → PUT to bucket → attach. */
export function useUploadInventoryDocument(itemId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (file: File) => {
      const contentType = file.type || "application/octet-stream";
      const presign = await uploadsApi.presign({
        purpose: "inventory_document",
        filename: file.name,
        content_type: contentType,
        size: file.size,
      });
      await putToBucket(presign, file);
      return inventoryApi.attachDocument(itemId, {
        key: presign.key,
        name: file.name,
        content_type: presign.content_type,
      });
    },
    onSuccess: () =>
      void queryClient.invalidateQueries({ queryKey: ["inventory", "documents", itemId] }),
  });
}

export function useDeleteInventoryDocument(itemId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (documentId: string) => inventoryApi.deleteDocument(itemId, documentId),
    onSuccess: () =>
      void queryClient.invalidateQueries({ queryKey: ["inventory", "documents", itemId] }),
  });
}
