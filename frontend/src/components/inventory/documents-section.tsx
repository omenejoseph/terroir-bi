"use client";

import * as React from "react";
import { FileText, Trash2 } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import {
  useDeleteInventoryDocument,
  useInventoryDocuments,
  useUploadInventoryDocument,
} from "@/hooks/use-inventory-media";
import { useTranslation } from "@/i18n/context";
import type { InventoryItem } from "@/lib/types";
import { Card, CardContent } from "@/components/ui/card";
import { Dropzone } from "@/components/ui/dropzone";
import { Spinner } from "@/components/ui/spinner";
import { useConfirm } from "@/components/ui/confirm";

const ACCEPT = [
  ".pdf",
  ".jpg",
  ".jpeg",
  ".png",
  ".webp",
  ".doc",
  ".docx",
  ".xls",
  ".xlsx",
  ".csv",
  ".txt",
].join(",");

function formatBytes(n: number): string {
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${Math.round(n / 1024)} KB`;
  return `${(n / (1024 * 1024)).toFixed(1)} MB`;
}

export function DocumentsSection({ item, canManage }: { item: InventoryItem; canManage: boolean }) {
  const { t } = useTranslation();
  const confirm = useConfirm();
  const listQ = useInventoryDocuments(item.id);
  const upload = useUploadInventoryDocument(item.id);
  const remove = useDeleteInventoryDocument(item.id);

  const [error, setError] = React.useState<string | null>(null);

  async function onFile(file: File) {
    setError(null);
    try {
      await upload.mutateAsync(file);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("inventory.documents.errorGeneric"));
    }
  }

  async function del(id: string, name: string) {
    const ok = await confirm({
      title: t("inventory.documents.deleteConfirmTitle"),
      description: t("inventory.documents.deleteConfirmBody", { name }),
      confirmLabel: t("inventory.documents.delete"),
      tone: "danger",
    });
    if (ok) await remove.mutateAsync(id);
  }

  const docs = listQ.data ?? [];

  return (
    <Card>
      <CardContent className="space-y-4 pt-6">
        <p className="text-sm text-muted-foreground">{t("inventory.documents.subtitle")}</p>

        {listQ.isLoading ? (
          <div className="flex justify-center py-6">
            <Spinner className="size-5 text-muted-foreground" />
          </div>
        ) : docs.length === 0 ? (
          <p className="py-2 text-sm text-muted-foreground">{t("inventory.documents.empty")}</p>
        ) : (
          <ul className="divide-y divide-border rounded-md border border-border">
            {docs.map((doc) => (
              <li key={doc.id} className="flex items-center justify-between gap-3 px-3 py-2.5">
                <a
                  href={doc.url}
                  target="_blank"
                  rel="noreferrer"
                  className="flex min-w-0 items-center gap-2 text-sm transition-colors hover:text-primary"
                >
                  <FileText className="size-4 shrink-0 text-muted-foreground" />
                  <span className="truncate font-medium">{doc.name}</span>
                </a>
                <div className="flex shrink-0 items-center gap-3">
                  <span className="text-xs tabular-nums text-muted-foreground">
                    {formatBytes(doc.size_bytes)}
                  </span>
                  {canManage && (
                    <button
                      type="button"
                      onClick={() => del(doc.id, doc.name)}
                      aria-label={t("inventory.documents.delete")}
                      className="text-muted-foreground transition-colors hover:text-destructive"
                    >
                      <Trash2 className="size-4" />
                    </button>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}

        {canManage && (
          <div className="border-t border-border pt-4">
            <Dropzone
              accept={ACCEPT}
              inputLabel={t("inventory.documents.fileLabel")}
              title={
                upload.isPending
                  ? t("inventory.documents.uploading")
                  : t("inventory.documents.dropzone")
              }
              hint={t("inventory.documents.dropzoneHint")}
              busy={upload.isPending}
              onFile={onFile}
            />
            {error && <p className="mt-2 text-sm text-destructive">{error}</p>}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
