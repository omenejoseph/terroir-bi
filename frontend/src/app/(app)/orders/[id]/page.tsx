"use client";

import * as React from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useOrder, useUpdateOrderStatus } from "@/hooks/use-orders";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import { ORDER_STATUSES, type OrderStatus } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { useConfirm } from "@/components/ui/confirm";
import { OrderItemsSection } from "@/components/orders/order-items-section";
import { OrderHistorySection } from "@/components/orders/order-history-section";
import { OrderCommentsSection } from "@/components/orders/order-comments-section";
import { OrderDetailsCard } from "@/components/orders/order-details-card";
import { OrderConsignmentSection } from "@/components/orders/order-consignment-section";
import { OrderPaymentsSection } from "@/components/orders/order-payments-section";

type DetailTab = "items" | "history" | "comments" | "consignment" | "payments";

const STATUS_VARIANT: Record<OrderStatus, "secondary" | "outline" | "success"> = {
  RECEIVED: "secondary",
  IN_PROCESS: "outline",
  READY_TO_SHIP: "outline",
  SHIPPED: "success",
};

export default function OrderDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id;
  const { t } = useTranslation();
  const { can } = useAuth();
  const { moneyObject, date } = useFormatters();
  const confirm = useConfirm();
  const canManage = can("orders.manage");

  const [tab, setTab] = React.useState<DetailTab>("items");
  const orderQ = useOrder(id);
  const order = orderQ.data;
  const updateStatus = useUpdateOrderStatus(id ?? "");

  const [nextStatus, setNextStatus] = React.useState<OrderStatus | "">("");
  const [statusNote, setStatusNote] = React.useState("");

  async function applyStatus() {
    if (!nextStatus || !order) return;
    const ok = await confirm({
      title: t("orders.statusChange.confirmTitle"),
      description: t("orders.statusChange.confirmBody", {
        order: order.order_number,
        status: t(`orders.status.${nextStatus}`),
      }),
    });
    if (!ok) return;
    await updateStatus.mutateAsync({ status: nextStatus, note: statusNote.trim() || null });
    setNextStatus("");
    setStatusNote("");
  }

  const tabs = [
    { value: "items", label: t("orders.tabs.items") },
    { value: "history", label: t("orders.tabs.history") },
    { value: "comments", label: t("orders.tabs.comments") },
    ...(order?.is_consignment ? [{ value: "consignment", label: t("orders.tabs.consignment") }] : []),
    ...(can("finance.view") ? [{ value: "payments", label: t("orders.tabs.payments") }] : []),
  ];

  return (
    <div className="space-y-6">
      <Link
        href="/orders"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("orders.back")}
      </Link>

      {orderQ.isLoading ? (
        <div className="flex justify-center py-16">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      ) : orderQ.isError || !order ? (
        <Card>
          <CardContent className="py-12 text-center text-sm text-muted-foreground">
            {t("orders.notFound")}
          </CardContent>
        </Card>
      ) : (
        <>
          <header className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div className="space-y-1">
              <div className="flex flex-wrap items-center gap-2">
                <h1 className="text-2xl font-semibold tracking-tight">{order.order_number}</h1>
                <Badge variant={STATUS_VARIANT[order.status]}>{t(`orders.status.${order.status}`)}</Badge>
                {order.is_backorder && <Badge variant="outline">{t("orders.backorderBadge")}</Badge>}
                {order.is_consignment && <Badge variant="outline">{t("orders.consignmentBadge")}</Badge>}
              </div>
              <p className="text-sm text-muted-foreground">
                {order.customer?.company_name ?? t("orders.noCustomer")} · {moneyObject(order.total_amount)}
                {order.created_at ? ` · ${date(order.created_at)}` : ""}
              </p>
              {order.created_by && (
                <p className="text-xs text-muted-foreground">
                  {t("orders.createdBy", { name: order.created_by.name })}
                </p>
              )}
            </div>

            {canManage && (
              <div className="flex flex-wrap items-end gap-2">
                <Select
                  value={nextStatus}
                  onChange={(e) => setNextStatus(e.target.value as OrderStatus)}
                  aria-label={t("orders.statusChange.label")}
                  className="w-40"
                >
                  <option value="">{t("orders.statusChange.label")}</option>
                  {ORDER_STATUSES.map((s) => (
                    <option key={s} value={s}>
                      {t(`orders.status.${s}`)}
                    </option>
                  ))}
                </Select>
                <Input
                  value={statusNote}
                  onChange={(e) => setStatusNote(e.target.value)}
                  placeholder={t("orders.statusChange.note")}
                  className="w-44"
                />
                <Button
                  type="button"
                  size="sm"
                  onClick={applyStatus}
                  disabled={!nextStatus || updateStatus.isPending}
                >
                  {updateStatus.isPending && <Spinner />}
                  {t("orders.statusChange.action")}
                </Button>
              </div>
            )}
          </header>

          <OrderDetailsCard order={order} canManage={canManage} />

          <Tabs tabs={tabs} value={tab} onChange={(v) => setTab(v as DetailTab)} />

          {tab === "items" && <OrderItemsSection order={order} canManage={canManage} />}
          {tab === "history" && <OrderHistorySection order={order} />}
          {tab === "comments" && <OrderCommentsSection order={order} />}
          {tab === "consignment" && order.is_consignment && (
            <OrderConsignmentSection order={order} canManage={canManage} />
          )}
          {tab === "payments" && can("finance.view") && <OrderPaymentsSection orderId={order.id} />}
        </>
      )}
    </div>
  );
}
