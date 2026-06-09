"use client";

import * as React from "react";
import Link from "next/link";
import { ArrowLeft, ChevronDown, Plus, Trash2 } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import {
  useDeleteSupplierOrder,
  useSupplierOrders,
  useUpdateSupplierOrderStatus,
} from "@/hooks/use-suppliers";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { SupplierOrder, SupplierOrderQuery, SupplierOrderStatus } from "@/lib/types";
import { SUPPLIER_ORDER_STATUSES } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { useConfirm } from "@/components/ui/confirm";
import { SupplierOrderDialog } from "@/components/suppliers/supplier-order-dialog";

type StatusTab = "all" | SupplierOrderStatus;

const STATUS_VARIANT: Record<SupplierOrderStatus, "default" | "secondary" | "success" | "outline"> = {
  DRAFT: "secondary",
  SENT: "default",
  CONFIRMED: "outline",
  RECEIVED: "success",
  CANCELLED: "secondary",
};

export default function PurchaseOrdersPage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const [tab, setTab] = React.useState<StatusTab>("all");
  const [creating, setCreating] = React.useState(false);

  const query: SupplierOrderQuery = tab === "all" ? {} : { status: tab };
  const { data, isLoading, isError, error } = useSupplierOrders(query);
  const orders = data?.data ?? [];

  const tabs = [
    { value: "all", label: t("supplierOrders.tabs.all") },
    ...SUPPLIER_ORDER_STATUSES.map((s) => ({ value: s, label: t(`supplierOrders.status.${s}`) })),
  ];

  return (
    <div className="space-y-6">
      <Link
        href="/suppliers"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("supplierOrders.back")}
      </Link>

      <header className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold tracking-tight">{t("supplierOrders.title")}</h1>
          <p className="text-sm text-muted-foreground">{t("supplierOrders.subtitleDefault")}</p>
        </div>
        {can("suppliers.manage") && (
          <Button onClick={() => setCreating(true)} className="shrink-0">
            <Plus className="size-4" />
            {t("supplierOrders.add")}
          </Button>
        )}
      </header>

      <Tabs tabs={tabs} value={tab} onChange={(v) => setTab(v as StatusTab)} />

      {isLoading && (
        <div className="flex items-center justify-center py-16">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      )}

      {isError && (
        <Card>
          <CardContent className="py-8 text-center text-sm text-destructive">
            {error instanceof ApiError && error.status === 403
              ? t("supplierOrders.errorForbidden")
              : t("supplierOrders.errorGeneric")}
          </CardContent>
        </Card>
      )}

      {!isLoading && !isError && orders.length === 0 && (
        <Card>
          <CardContent className="py-12 text-center text-sm text-muted-foreground">
            {t("supplierOrders.empty")}
          </CardContent>
        </Card>
      )}

      {!isLoading && !isError && orders.length > 0 && (
        <div className="space-y-2">
          {orders.map((order) => (
            <OrderRow key={order.id} order={order} variant={STATUS_VARIANT[order.status]} />
          ))}
        </div>
      )}

      <SupplierOrderDialog open={creating} onOpenChange={setCreating} onCreated={() => setCreating(false)} />
    </div>
  );
}

const NEXT_STATUS: Partial<Record<SupplierOrderStatus, { next: SupplierOrderStatus; labelKey: string }>> = {
  DRAFT: { next: "SENT", labelKey: "supplierOrders.markSent" },
  SENT: { next: "CONFIRMED", labelKey: "supplierOrders.markConfirmed" },
  CONFIRMED: { next: "RECEIVED", labelKey: "supplierOrders.markReceived" },
};

function OrderRow({
  order,
  variant,
}: {
  order: SupplierOrder;
  variant: "default" | "secondary" | "success" | "outline";
}) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const { moneyObject, date } = useFormatters();
  const confirm = useConfirm();
  const updateStatus = useUpdateSupplierOrderStatus();
  const remove = useDeleteSupplierOrder();
  const [open, setOpen] = React.useState(false);

  const canManage = can("suppliers.manage");
  const step = NEXT_STATUS[order.status];
  const canDelete = order.status === "DRAFT" || order.status === "CANCELLED";

  async function advance() {
    if (!step) return;
    if (step.next === "RECEIVED") {
      const ok = await confirm({
        title: t("supplierOrders.receiveConfirmTitle"),
        description: t("supplierOrders.receiveConfirmBody"),
        confirmLabel: t("supplierOrders.markReceived"),
      });
      if (!ok) return;
    }
    await updateStatus.mutateAsync({ id: order.id, status: step.next });
  }

  async function handleDelete() {
    const ok = await confirm({
      title: t("supplierOrders.deleteTitle"),
      description: t("supplierOrders.deleteBody", { number: order.order_number }),
      confirmLabel: t("supplierOrders.delete"),
      tone: "danger",
    });
    if (!ok) return;
    await remove.mutateAsync(order.id);
  }

  return (
    <Card className="overflow-hidden">
      <button
        type="button"
        onClick={() => setOpen((p) => !p)}
        aria-expanded={open}
        className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/40"
      >
        <div className="min-w-0">
          <p className="truncate font-medium">{order.order_number}</p>
          <p className="truncate text-xs text-muted-foreground">
            {order.supplier?.company_name ?? "—"}
          </p>
        </div>
        <div className="flex shrink-0 items-center gap-3 text-sm">
          {order.expected_at && (
            <span className="hidden text-muted-foreground sm:inline">{date(order.expected_at)}</span>
          )}
          <span className="tabular-nums">{moneyObject(order.total_amount)}</span>
          <Badge variant={variant}>{t(`supplierOrders.status.${order.status}`)}</Badge>
          <ChevronDown
            className={`size-4 text-muted-foreground transition-transform duration-300 ${open ? "rotate-180" : ""}`}
          />
        </div>
      </button>

      <div
        className={`grid transition-all duration-300 ease-out ${
          open ? "grid-rows-[1fr] opacity-100" : "grid-rows-[0fr] opacity-0"
        }`}
      >
        <div className="overflow-hidden">
          <div className="space-y-4 border-t border-border px-4 py-4">
            <table className="w-full text-sm">
              <thead className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                  <th className="py-1.5 pr-3 font-medium">{t("supplierOrders.form.description")}</th>
                  <th className="py-1.5 pr-3 text-right font-medium">{t("supplierOrders.form.quantity")}</th>
                  <th className="py-1.5 text-right font-medium">{t("supplierOrders.colTotal")}</th>
                </tr>
              </thead>
              <tbody>
                {(order.items ?? []).map((item) => (
                  <tr key={item.id} className="border-b border-border last:border-0">
                    <td className="py-2 pr-3">{item.description}</td>
                    <td className="py-2 pr-3 text-right tabular-nums">{item.quantity}</td>
                    <td className="py-2 text-right tabular-nums">{moneyObject(item.total)}</td>
                  </tr>
                ))}
              </tbody>
            </table>

            {canManage && (
              <div className="flex flex-wrap justify-end gap-2 border-t border-border pt-3">
                {step && (
                  <Button size="sm" onClick={advance} disabled={updateStatus.isPending}>
                    {updateStatus.isPending ? <Spinner /> : null}
                    {t(step.labelKey)}
                  </Button>
                )}
                {canDelete && (
                  <Button
                    variant="outline"
                    size="sm"
                    className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                    onClick={handleDelete}
                    disabled={remove.isPending}
                  >
                    {remove.isPending ? <Spinner /> : <Trash2 className="size-3.5" />}
                    {t("supplierOrders.delete")}
                  </Button>
                )}
              </div>
            )}
          </div>
        </div>
      </div>
    </Card>
  );
}
