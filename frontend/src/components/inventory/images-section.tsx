"use client";

import * as React from "react";
import { Scissors, Trash2, Upload, X } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import {
  useDeleteInventoryImage,
  useInventoryImages,
  useRemoveBackground,
  useUploadInventoryImage,
} from "@/hooks/use-inventory-media";
import { processImage, type CropRect } from "@/lib/image";
import { useTranslation } from "@/i18n/context";
import type { InventoryItem } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Dropzone } from "@/components/ui/dropzone";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { useConfirm } from "@/components/ui/confirm";

const MAX_EDGES = [
  { value: 0, key: "original" },
  { value: 2048, key: "2048" },
  { value: 1024, key: "1024" },
] as const;

export function ImagesSection({ item, canManage }: { item: InventoryItem; canManage: boolean }) {
  const { t } = useTranslation();
  const confirm = useConfirm();
  const imagesQ = useInventoryImages(item.id);
  const upload = useUploadInventoryImage(item.id);
  const removeImage = useDeleteInventoryImage(item.id);
  const removeBg = useRemoveBackground();

  // Editor state: the file the user is preparing to upload.
  const [working, setWorking] = React.useState<Blob | null>(null);
  const [previewUrl, setPreviewUrl] = React.useState<string>("");
  const [crop, setCrop] = React.useState<CropRect | null>(null);
  const [maxEdge, setMaxEdge] = React.useState<number>(2048);
  const [alt, setAlt] = React.useState("");
  const [error, setError] = React.useState<string | null>(null);

  const imgRef = React.useRef<HTMLImageElement>(null);

  // Keep an object URL for the working blob, revoking the previous one.
  React.useEffect(() => {
    if (!working) {
      setPreviewUrl("");
      return;
    }
    const url = URL.createObjectURL(working);
    setPreviewUrl(url);
    return () => URL.revokeObjectURL(url);
  }, [working]);

  function reset() {
    setWorking(null);
    setCrop(null);
    setAlt("");
    setMaxEdge(2048);
    setError(null);
  }

  function pickFile(file: File) {
    setError(null);
    setCrop(null);
    setWorking(file);
  }

  async function handleRemoveBackground() {
    if (!working) return;
    setError(null);
    try {
      const cut = await removeBg.mutateAsync(working);
      setCrop(null);
      setWorking(cut);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("inventory.images.errorGeneric"));
    }
  }

  async function handleUpload() {
    if (!working) return;
    setError(null);
    try {
      const blob = await processImage(working, {
        crop: crop ?? undefined,
        maxEdge: maxEdge || Number.MAX_SAFE_INTEGER,
        type: "image/webp",
      });
      await upload.mutateAsync({ blob, alt: alt.trim() || null });
      reset();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("inventory.images.errorGeneric"));
    }
  }

  async function handleDelete(imageId: string) {
    const ok = await confirm({
      title: t("inventory.images.deleteConfirmTitle"),
      description: t("inventory.images.deleteConfirmBody"),
      confirmLabel: t("inventory.images.delete"),
      tone: "danger",
    });
    if (!ok) return;
    await removeImage.mutateAsync(imageId);
  }

  // Drag a crop rectangle over the preview (display px → natural px on apply).
  const dragStart = React.useRef<{ x: number; y: number } | null>(null);
  function onPointerDown(e: React.PointerEvent) {
    const rect = imgRef.current?.getBoundingClientRect();
    if (!rect) return;
    dragStart.current = { x: e.clientX - rect.left, y: e.clientY - rect.top };
  }
  function onPointerMove(e: React.PointerEvent) {
    const img = imgRef.current;
    const start = dragStart.current;
    if (!img || !start) return;
    const rect = img.getBoundingClientRect();
    if (!rect.width || !rect.height) return;
    const curX = e.clientX - rect.left;
    const curY = e.clientY - rect.top;
    const x = Math.max(0, Math.min(start.x, curX));
    const y = Math.max(0, Math.min(start.y, curY));
    const w = Math.min(rect.width, Math.abs(curX - start.x));
    const h = Math.min(rect.height, Math.abs(curY - start.y));
    if (w < 8 || h < 8) return; // ignore tiny drags (treated as a click)
    const scaleX = img.naturalWidth / rect.width;
    const scaleY = img.naturalHeight / rect.height;
    setCrop({ x: x * scaleX, y: y * scaleY, width: w * scaleX, height: h * scaleY });
  }
  function onPointerUp() {
    dragStart.current = null;
  }

  const images = imagesQ.data ?? [];
  const busy = upload.isPending || removeBg.isPending;

  return (
    <Card>
      <CardContent className="space-y-5 pt-6">
        <p className="text-sm text-muted-foreground">{t("inventory.images.subtitle")}</p>

        {/* Gallery */}
        {imagesQ.isLoading ? (
          <div className="flex justify-center py-6">
            <Spinner className="size-5 text-muted-foreground" />
          </div>
        ) : images.length === 0 ? (
          <p className="py-4 text-center text-sm text-muted-foreground">
            {t("inventory.images.empty")}
          </p>
        ) : (
          <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
            {images.map((image) => (
              <li
                key={image.id}
                className="group relative aspect-square overflow-hidden rounded-lg border border-border bg-muted/30"
              >
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img
                  src={image.url}
                  alt={image.alt ?? item.name}
                  className="size-full object-cover"
                />
                {canManage && (
                  <button
                    type="button"
                    aria-label={t("inventory.images.delete")}
                    onClick={() => handleDelete(image.id)}
                    className="absolute right-1.5 top-1.5 rounded-md bg-background/80 p-1 text-destructive opacity-0 shadow-sm transition-opacity hover:bg-background group-hover:opacity-100"
                  >
                    <Trash2 className="size-4" />
                  </button>
                )}
              </li>
            ))}
          </ul>
        )}

        {/* Uploader / editor */}
        {canManage && (
          <div className="border-t border-border pt-4">
            {!working ? (
              <Dropzone
                accept="image/jpeg,image/png,image/webp,image/gif"
                inputLabel={t("inventory.images.fileLabel")}
                title={t("inventory.images.dropzone")}
                hint={t("inventory.images.dropzoneHint")}
                onFile={pickFile}
              />
            ) : (
              <div className="space-y-4">
                <p className="text-xs text-muted-foreground">{t("inventory.images.cropHint")}</p>
                <div
                  className="relative inline-block max-w-full touch-none overflow-hidden rounded-lg border border-border"
                  onPointerDown={onPointerDown}
                  onPointerMove={onPointerMove}
                  onPointerUp={onPointerUp}
                  onPointerLeave={onPointerUp}
                >
                  {previewUrl && (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img
                      ref={imgRef}
                      src={previewUrl}
                      alt={t("inventory.images.previewAlt")}
                      className="block max-h-80 w-auto select-none"
                      draggable={false}
                    />
                  )}
                </div>

                {crop && (
                  <button
                    type="button"
                    onClick={() => setCrop(null)}
                    className="text-xs font-medium text-primary hover:underline"
                  >
                    {t("inventory.images.resetCrop")}
                  </button>
                )}

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <div className="space-y-1.5">
                    <Label htmlFor="img-size">{t("inventory.images.sizeLabel")}</Label>
                    <Select
                      id="img-size"
                      value={String(maxEdge)}
                      onChange={(e) => setMaxEdge(Number(e.target.value))}
                    >
                      {MAX_EDGES.map((m) => (
                        <option key={m.key} value={m.value}>
                          {t(`inventory.images.size.${m.key}`)}
                        </option>
                      ))}
                    </Select>
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="img-alt">{t("inventory.images.altLabel")}</Label>
                    <Input id="img-alt" value={alt} onChange={(e) => setAlt(e.target.value)} />
                  </div>
                </div>

                {error && <p className="text-sm text-destructive">{error}</p>}

                <div className="flex flex-wrap items-center justify-between gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={handleRemoveBackground}
                    disabled={busy}
                  >
                    {removeBg.isPending ? <Spinner /> : <Scissors className="size-4" />}
                    {t("inventory.images.removeBackground")}
                  </Button>
                  <div className="flex gap-2">
                    <Button type="button" variant="ghost" size="sm" onClick={reset} disabled={busy}>
                      <X className="size-4" />
                      {t("inventory.images.cancel")}
                    </Button>
                    <Button type="button" size="sm" onClick={handleUpload} disabled={busy}>
                      {upload.isPending ? <Spinner /> : <Upload className="size-4" />}
                      {t("inventory.images.upload")}
                    </Button>
                  </div>
                </div>
              </div>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
