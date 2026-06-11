"use client";

import * as React from "react";
import Link from "next/link";
import { ArrowRight, Pencil, Trash2 } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useDeleteInflow, useUpdateInflowStatus } from "@/hooks/use-inflows";
import { useOrder } from "@/hooks/use-orders";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import { INFLOW_STATUSES, type Inflow, type InflowStatus } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { useConfirm } from "@/components/ui/confirm";
import { InflowForm } from "@/components/inflows/inflow-form";
import { InflowChangesSection } from "@/components/inflows/inflow-changes-section";

/** Expanded detail for an inflow row: fields, linked order, actions, history. */
export function InflowDetailPanel({ inflow, onDeleted }: { inflow: Inflow; onDeleted?: () => void }) {
  const { t } = useTranslation();
  const { moneyObject } = useFormatters();
  const { can } = useAuth();
  const confirm = useConfirm();
  const updateStatus = useUpdateInflowStatus();
  const remove = useDeleteInflow();
  const { data: order } = useOrder(inflow.order_id ?? undefined);
  const [editing, setEditing] = React.useState(false);

  const canManage = can("finance.manage");
  const canDelete = can("finance.delete");

  if (editing) {
    return <InflowForm inflow={inflow} onSaved={() => setEditing(false)} onCancel={() => setEditing(false)} />;
  }

  async function handleDelete() {
    const ok = await confirm({
      title: t("inflows.deleteTitle"),
      description: t("inflows.deleteBody"),
      confirmLabel: t("inflows.delete"),
      tone: "danger",
    });
    if (!ok) return;
    await remove.mutateAsync(inflow.id);
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
      <div className="divide-y divide-border/60">
        {row(t("inflows.form.category"), inflow.category)}
        {row(
          t("inflows.form.paymentMethod"),
          inflow.payment_method ? t(`inflows.paymentMethods.${inflow.payment_method}`) : null,
        )}
        {row(t("inflows.form.reference"), inflow.reference)}
        {row(t("inflows.form.notes"), inflow.notes)}
        {inflow.is_credit_note && row(t("inflows.detail.type"), t("inflows.creditNote"))}
      </div>

      {inflow.order_id && (
        <div className="rounded-md border border-border p-3">
          <p className="mb-1 text-xs font-medium text-muted-foreground">{t("inflows.linkedOrder.title")}</p>
          <div className="flex items-center justify-between gap-3">
            <div className="space-y-0.5">
              <p className="font-medium">{inflow.order_number ?? order?.order_number}</p>
              {order && (
                <p className="text-xs text-muted-foreground">
                  {t(`orders.status.${order.status}`)} · {moneyObject(order.total_amount)}
                </p>
              )}
            </div>
            <Link
              href={`/orders/${inflow.order_id}`}
              className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
            >
              {t("inflows.linkedOrder.viewOrder")}
              <ArrowRight className="size-4" />
            </Link>
          </div>
        </div>
      )}

      <div className="flex flex-wrap items-end gap-2">
        {canManage && (
          <div className="space-y-1">
            <Label htmlFor={`status-${inflow.id}`} className="text-xs text-muted-foreground">
              {t("inflows.colStatus")}
            </Label>
            <Select
              id={`status-${inflow.id}`}
              aria-label={t("inflows.markAs")}
              value={inflow.status}
              onChange={(e) => updateStatus.mutate({ id: inflow.id, status: e.target.value as InflowStatus })}
              className="h-9 w-36"
            >
              {INFLOW_STATUSES.map((s) => (
                <option key={s} value={s}>
                  {t(`inflows.status.${s}`)}
                </option>
              ))}
            </Select>
          </div>
        )}
        <div className="ml-auto flex items-center gap-2">
          {canManage && (
            <Button variant="outline" onClick={() => setEditing(true)}>
              <Pencil className="size-4" />
              {t("inflows.edit")}
            </Button>
          )}
          {canDelete && (
            <Button variant="ghost" onClick={handleDelete} className="text-muted-foreground hover:text-destructive">
              <Trash2 className="size-4" />
              {t("inflows.delete")}
            </Button>
          )}
        </div>
      </div>

      <InflowChangesSection inflowId={inflow.id} />
    </div>
  );
}
