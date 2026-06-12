"use client";

import * as React from "react";
import { Pencil, Trash2 } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useDeleteCost, useUpdateCostStatus } from "@/hooks/use-costs";
import { useTranslation } from "@/i18n/context";
import { COST_STATUSES, type Cost, type CostStatus } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { useConfirm } from "@/components/ui/confirm";
import { CostForm } from "@/components/costs/cost-form";

/** Expanded detail for a cost row: fields, status, edit (incl. supplier), delete. */
export function CostDetailPanel({ cost, onDeleted }: { cost: Cost; onDeleted?: () => void }) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const confirm = useConfirm();
  const updateStatus = useUpdateCostStatus();
  const remove = useDeleteCost();
  const [editing, setEditing] = React.useState(false);

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
      </div>

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
