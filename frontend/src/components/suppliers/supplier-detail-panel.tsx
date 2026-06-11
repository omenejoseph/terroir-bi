"use client";

import * as React from "react";
import { Pencil, Power, Receipt, Tags, Trash2, Wallet } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useDeleteSupplier, useSupplierStats, useUpdateSupplier } from "@/hooks/use-suppliers";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { Supplier } from "@/lib/types";
import { withCount } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { StatCard } from "@/components/dashboard/stat-card";
import { useConfirm } from "@/components/ui/confirm";
import { SupplierForm } from "@/components/suppliers/supplier-form";
import { SupplierPriceHistorySection } from "@/components/suppliers/supplier-price-history-section";
import { SupplierPriceListSection } from "@/components/suppliers/supplier-price-list-section";
import { SupplierPortalSection } from "@/components/suppliers/supplier-portal-section";

/**
 * The full supplier view — read-only details with Edit / Activate-Deactivate /
 * Delete actions (or the edit form), plus the price list. Shared by the inline
 * expand on the suppliers list and the detail page so both surfaces match.
 */
export function SupplierDetailPanel({
  supplier,
  onDeleted,
}: {
  supplier: Supplier;
  onDeleted?: () => void;
}) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const { moneyObject, number } = useFormatters();
  const confirm = useConfirm();
  const update = useUpdateSupplier();
  const remove = useDeleteSupplier();
  const statsQ = useSupplierStats(supplier.id);
  const stats = statsQ.data;

  const canManage = can("suppliers.manage");
  const canFinance = can("finance.view");
  const [editing, setEditing] = React.useState(false);
  const [tab, setTab] = React.useState<"details" | "price-list" | "history">("details");

  async function toggleActive() {
    const deactivating = supplier.is_active;
    const ok = await confirm({
      title: deactivating ? t("suppliers.deactivate.title") : t("suppliers.activate.title"),
      description: deactivating
        ? t("suppliers.deactivate.body", { name: supplier.company_name })
        : t("suppliers.activate.body", { name: supplier.company_name }),
      confirmLabel: deactivating ? t("suppliers.deactivate.action") : t("suppliers.activate.action"),
      tone: deactivating ? "danger" : "default",
    });
    if (!ok) return;
    await update.mutateAsync({ id: supplier.id, input: { is_active: !supplier.is_active } });
  }

  async function handleDelete() {
    const ok = await confirm({
      title: t("suppliers.delete.title"),
      description: t("suppliers.delete.body", { name: supplier.company_name }),
      confirmLabel: t("suppliers.delete.action"),
      tone: "danger",
    });
    if (!ok) return;
    await remove.mutateAsync(supplier.id);
    onDeleted?.();
  }

  return (
    <div className="space-y-4">
      <div className={`grid grid-cols-1 gap-3 ${canFinance ? "sm:grid-cols-3" : ""}`}>
        {canFinance && (
          <StatCard
            label={t("suppliers.stats.totalCosts")}
            value={moneyObject(stats?.total_costs ?? null)}
            icon={Wallet}
            accent="bg-emerald-500/10 text-emerald-500"
          />
        )}
        {canFinance && (
          <StatCard
            label={t("suppliers.stats.costEntries")}
            value={number(stats?.cost_entries ?? 0)}
            icon={Receipt}
            accent="bg-sky-500/10 text-sky-500"
            delayMs={50}
          />
        )}
        <StatCard
          label={t("suppliers.stats.priceItems")}
          value={number(stats?.price_items ?? supplier.price_items_count ?? 0)}
          icon={Tags}
          accent="bg-amber-500/10 text-amber-500"
          delayMs={100}
        />
      </div>

      <Tabs
        tabs={[
          { value: "details", label: t("suppliers.detailTabs.details") },
          {
            value: "price-list",
            label: withCount(t("suppliers.detailTabs.priceList"), supplier.price_items_count ?? undefined),
          },
          {
            value: "history",
            label: withCount(t("suppliers.detailTabs.history"), supplier.price_changes_count ?? undefined),
          },
        ]}
        value={tab}
        onChange={(v) => setTab(v as "details" | "price-list" | "history")}
      />

      {tab === "price-list" ? (
        <SupplierPriceListSection supplierId={supplier.id} />
      ) : tab === "history" ? (
        <SupplierPriceHistorySection supplierId={supplier.id} />
      ) : editing ? (
        <SupplierForm
          supplier={supplier}
          onSaved={() => setEditing(false)}
          onCancel={() => setEditing(false)}
        />
      ) : (
        <>
        <div className="space-y-4">
          <SupplierDetails supplier={supplier} />
          <div className="flex flex-wrap justify-end gap-2 border-t border-border pt-3">
            {canManage && (
              <Button variant="outline" size="sm" onClick={() => setEditing(true)}>
                <Pencil className="size-3.5" />
                {t("suppliers.form.edit")}
              </Button>
            )}
            {canManage && (
              <Button
                variant="outline"
                size="sm"
                className={
                  supplier.is_active
                    ? "text-destructive hover:bg-destructive/10 hover:text-destructive"
                    : undefined
                }
                onClick={toggleActive}
                disabled={update.isPending}
              >
                {update.isPending ? <Spinner /> : <Power className="size-3.5" />}
                {supplier.is_active
                  ? t("suppliers.deactivate.action")
                  : t("suppliers.activate.action")}
              </Button>
            )}
            {can("suppliers.delete") && (
              <Button
                variant="outline"
                size="sm"
                className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                onClick={handleDelete}
                disabled={remove.isPending}
              >
                {remove.isPending ? <Spinner /> : <Trash2 className="size-3.5" />}
                {t("suppliers.delete.action")}
              </Button>
            )}
          </div>
        </div>

        {canManage && <SupplierPortalSection supplier={supplier} />}
        </>
      )}
    </div>
  );
}

/** Read-only key details for a supplier (the non-editing view). */
function SupplierDetails({ supplier }: { supplier: Supplier }) {
  const { t } = useTranslation();
  const rows: { label: string; value: string | null }[] = [
    { label: t("suppliers.form.contactName"), value: supplier.contact_name },
    { label: t("suppliers.form.email"), value: supplier.email },
    { label: t("suppliers.form.phone"), value: supplier.phone },
    {
      label: t("suppliers.form.address"),
      value: [supplier.address, supplier.city, supplier.country].filter(Boolean).join(", ") || null,
    },
    { label: t("suppliers.form.taxId"), value: supplier.tax_id },
    { label: t("suppliers.form.bankAccount"), value: supplier.bank_account },
    { label: t("suppliers.form.paymentTerms"), value: supplier.payment_terms },
    { label: t("suppliers.form.notes"), value: supplier.notes },
  ].filter((r) => r.value);

  if (rows.length === 0) {
    return <p className="text-sm text-muted-foreground">{t("suppliers.noDetails")}</p>;
  }

  return (
    <dl className="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
      {rows.map((r) => (
        <div key={r.label} className="text-sm">
          <dt className="text-xs text-muted-foreground">{r.label}</dt>
          <dd className="font-medium">{r.value}</dd>
        </div>
      ))}
    </dl>
  );
}
