"use client";

import * as React from "react";
import { DollarSign, Download, FileText, Paperclip, Pencil, Trash2 } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import {
  useAddCostAttachment,
  useCostAttachments,
  useDeleteCost,
  useDeleteCostAttachment,
  useUpdateCostStatus,
} from "@/hooks/use-costs";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import { COST_STATUSES, type Cost, type CostStatus } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { useConfirm } from "@/components/ui/confirm";
import { CostForm } from "@/components/costs/cost-form";

/** Human file size, e.g. "1.2 MB". */
function fileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/** Expanded detail for a cost row: tabbed into Details / Items / Attachments. */
export function CostDetailPanel({ cost, onDeleted }: { cost: Cost; onDeleted?: () => void }) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const { moneyObject, money2, number } = useFormatters();
  const confirm = useConfirm();
  const updateStatus = useUpdateCostStatus();
  const remove = useDeleteCost();
  const addAttachment = useAddCostAttachment(cost.id);
  const deleteAttachment = useDeleteCostAttachment(cost.id);
  const fileRef = React.useRef<HTMLInputElement>(null);
  const [editing, setEditing] = React.useState(false);
  const [tab, setTab] = React.useState<"details" | "items" | "attachments">("details");
  // Presigned read URLs are fetched lazily when the Attachments tab is open.
  const attachmentsQ = useCostAttachments(cost.id, tab === "attachments");

  const items = cost.items ?? [];
  const attachments = cost.attachments ?? [];

  const vatMinor = cost.vat_amount?.minor ?? 0;
  const hasVat = vatMinor > 0;
  const netMinor = cost.total_amount.minor - vatMinor;

  const canManage = can("finance.manage");
  const canDelete = can("finance.delete");

  if (editing) {
    return <CostForm cost={cost} onSaved={() => setEditing(false)} onCancel={() => setEditing(false)} />;
  }

  async function handleDelete() {
    const ok = await confirm({
      title: t("costs.deleteTitle"),
      description: t("costs.deleteBody"),
      confirmLabel: t("costs.delete"),
      tone: "danger",
    });
    if (!ok) return;
    await remove.mutateAsync(cost.id);
    onDeleted?.();
  }

  const row = (label: string, value: React.ReactNode) =>
    value ? (
      <div className="flex justify-between gap-3 py-1.5 text-sm">
        <span className="text-muted-foreground">{label}</span>
        <span className="text-right">{value}</span>
      </div>
    ) : null;

  return (
    <div className="space-y-4">
      {/* Summary cards — amount (with PDV/Net breakdown), item count, attachment count. */}
      <div className="grid grid-cols-3 gap-2 sm:gap-3">
        <div className="rounded-lg border border-border/60 p-3">
          <div className="flex items-center justify-between gap-2 text-xs font-medium text-muted-foreground">
            <span>{hasVat ? t("costs.detailCards.amountInclVat") : t("costs.detailCards.amount")}</span>
            <DollarSign className="hidden size-4 shrink-0 sm:block" />
          </div>
          <div className="mt-1 text-lg font-bold tabular-nums sm:text-xl">{moneyObject(cost.total_amount)}</div>
          {hasVat && (
            <p className="mt-0.5 text-[10px] text-muted-foreground sm:text-xs">
              {t("costs.detailCards.vatNet", { vat: money2(vatMinor), net: money2(netMinor) })}
            </p>
          )}
        </div>
        <div className="rounded-lg border border-border/60 p-3">
          <div className="flex items-center justify-between gap-2 text-xs font-medium text-muted-foreground">
            <span>{t("costs.detailCards.items")}</span>
            <FileText className="hidden size-4 shrink-0 sm:block" />
          </div>
          <div className="mt-1 text-lg font-bold tabular-nums sm:text-xl">{number(items.length)}</div>
        </div>
        <div className="rounded-lg border border-border/60 p-3">
          <div className="flex items-center justify-between gap-2 text-xs font-medium text-muted-foreground">
            <span>{t("costs.detailCards.attachments")}</span>
            <Paperclip className="hidden size-4 shrink-0 sm:block" />
          </div>
          <div className="mt-1 text-lg font-bold tabular-nums sm:text-xl">{number(attachments.length)}</div>
        </div>
      </div>

      <Tabs
        tabs={[
          { value: "details", label: t("costs.detailTabs.details") },
          { value: "items", label: `${t("costs.detailTabs.items")} (${items.length})` },
          { value: "attachments", label: `${t("costs.detailTabs.attachments")} (${attachments.length})` },
        ]}
        value={tab}
        onChange={(v) => setTab(v as "details" | "items" | "attachments")}
      />

      {tab === "details" && (
        <div className="divide-y divide-border/60">
          {row(t("costs.colCategory"), cost.category)}
          {row(t("costs.colSupplier"), cost.supplier?.company_name)}
          {row(
            t("costs.form.paymentMethod"),
            cost.payment_method ? t(`costs.paymentMethods.${cost.payment_method}`) : null,
          )}
          {row(t("costs.colReference"), cost.reference)}
          {row(t("costs.form.description"), cost.description)}
          {row(t("costs.form.notes"), cost.notes)}
          {row(t("costs.createdBy"), cost.created_by?.name)}
        </div>
      )}

      {tab === "items" &&
        (items.length > 0 ? (
          <ul className="divide-y divide-border/60 rounded-lg border border-border/60">
            {items.map((it) => (
              <li key={it.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                <span className="min-w-0 truncate">{it.description}</span>
                <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                  {number(Number(it.quantity))} × {moneyObject(it.unit_price)}
                </span>
                <span className="w-24 shrink-0 text-right font-medium tabular-nums">{moneyObject(it.total)}</span>
              </li>
            ))}
          </ul>
        ) : (
          <p className="py-4 text-center text-sm text-muted-foreground">{t("costs.noLineItems")}</p>
        ))}

      {tab === "attachments" && (
        <div className="space-y-3">
          {attachments.length === 0 ? (
            <p className="py-4 text-center text-sm text-muted-foreground">{t("costs.noAttachments")}</p>
          ) : attachmentsQ.isLoading || !attachmentsQ.data ? (
            <div className="flex justify-center py-4">
              <Spinner className="size-5 text-muted-foreground" />
            </div>
          ) : (
            <ul className="divide-y divide-border/60 rounded-lg border border-border/60">
              {attachmentsQ.data.map((a) => (
                <li key={a.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                  {/* Filename opens the file in a new tab (view). */}
                  <a
                    href={a.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="min-w-0 truncate font-medium hover:underline"
                  >
                    {a.filename}
                  </a>
                  <span className="flex shrink-0 items-center gap-3">
                    <span className="text-xs text-muted-foreground tabular-nums">{fileSize(a.size_bytes)}</span>
                    <a
                      href={a.url}
                      download={a.filename}
                      className="text-muted-foreground transition-colors hover:text-foreground"
                      aria-label={t("costs.downloadAttachment")}
                    >
                      <Download className="size-3.5" />
                    </a>
                    {canManage && (
                      <button
                        type="button"
                        onClick={() => deleteAttachment.mutate(a.id)}
                        className="text-muted-foreground transition-colors hover:text-destructive"
                        aria-label={t("costs.delete")}
                      >
                        <Trash2 className="size-3.5" />
                      </button>
                    )}
                  </span>
                </li>
              ))}
            </ul>
          )}

          {canManage && (
            <div>
              <input
                ref={fileRef}
                type="file"
                accept="image/jpeg,image/png,image/webp,application/pdf"
                className="hidden"
                onChange={async (e) => {
                  const file = e.target.files?.[0];
                  if (file) await addAttachment.mutateAsync(file).catch(() => {});
                  if (fileRef.current) fileRef.current.value = "";
                }}
              />
              <Button
                variant="outline"
                size="sm"
                onClick={() => fileRef.current?.click()}
                disabled={addAttachment.isPending}
              >
                {addAttachment.isPending ? <Spinner className="size-4" /> : <Paperclip className="size-4" />}
                {t("costs.addAttachment")}
              </Button>
            </div>
          )}
        </div>
      )}

      <div className="flex flex-wrap items-end gap-2">
        {canManage && (
          <div className="space-y-1">
            <Label htmlFor={`cost-status-${cost.id}`} className="text-xs text-muted-foreground">
              {t("costs.form.status")}
            </Label>
            <Select
              id={`cost-status-${cost.id}`}
              aria-label={t("costs.markAs")}
              value={cost.status}
              onChange={(e) => updateStatus.mutate({ id: cost.id, status: e.target.value as CostStatus })}
              className="h-9 w-36"
            >
              {COST_STATUSES.map((s) => (
                <option key={s} value={s}>
                  {t(`costs.status.${s}`)}
                </option>
              ))}
            </Select>
          </div>
        )}
        <div className="ml-auto flex items-center gap-2">
          {canManage && (
            <Button variant="outline" onClick={() => setEditing(true)}>
              <Pencil className="size-4" />
              {t("costs.edit")}
            </Button>
          )}
          {canDelete && (
            <Button
              variant="ghost"
              onClick={handleDelete}
              className="text-muted-foreground hover:text-destructive"
            >
              <Trash2 className="size-4" />
              {t("costs.delete")}
            </Button>
          )}
        </div>
      </div>
    </div>
  );
}
