"use client";

import * as React from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useOrder } from "@/hooks/use-orders";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { OrderStatus } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { OrderItemsSection } from "@/components/orders/order-items-section";
import { OrderHistorySection } from "@/components/orders/order-history-section";
import { OrderCommentsSection } from "@/components/orders/order-comments-section";
import { OrderDetailsCard } from "@/components/orders/order-details-card";
import { OrderConsignmentSection } from "@/components/orders/order-consignment-section";
import { OrderPaymentsSection } from "@/components/orders/order-payments-section";
import { OrderInflowsCard } from "@/components/orders/order-inflows-card";
import { OrderStatusUpdater } from "@/components/orders/order-status-updater";
import { OrderCustomerCard } from "@/components/orders/order-customer-card";

type DetailTab = "history" | "comments" | "consignment" | "payments";

const STATUS_VARIANT: Record<OrderStatus, "info" | "warning" | "purple" | "success"> = {
  RECEIVED: "info",
  IN_PROCESS: "warning",
  READY_TO_SHIP: "purple",
  SHIPPED: "success",
};

export default function OrderDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id;
  const { t } = useTranslation();
  const { can } = useAuth();
  const { moneyObject, date } = useFormatters();
  const canManage = can("orders.manage");
  const canViewFinance = can("finance.view");

  const [tab, setTab] = React.useState<DetailTab>("history");
  const orderQ = useOrder(id);
  const order = orderQ.data;

  const tabs = [
    { value: "history", label: t("orders.tabs.history") },
    { value: "comments", label: t("orders.tabs.comments") },
    ...(order?.is_consignment ? [{ value: "consignment", label: t("orders.tabs.consignment") }] : []),
    ...(canViewFinance ? [{ value: "payments", label: t("orders.tabs.payments") }] : []),
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
          <header className="space-y-1">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="text-2xl font-semibold tracking-tight">{order.order_number}</h1>
              <Badge variant={STATUS_VARIANT[order.status]}>{t(`orders.status.${order.status}`)}</Badge>
              {order.is_backorder && <Badge variant="outline">{t("orders.backorderBadge")}</Badge>}
              {order.is_consignment && <Badge variant="outline">{t("orders.consignmentBadge")}</Badge>}
            </div>
            <p className="text-sm text-muted-foreground">
              {order.customer?.company_name ?? t("orders.noCustomer")} ·{" "}
              <span className="font-semibold text-foreground">{moneyObject(order.total_amount)}</span>
              {order.created_at ? ` · ${date(order.created_at)}` : ""}
            </p>
            {order.created_by && (
              <p className="text-xs text-muted-foreground">
                {t("orders.createdBy", { name: order.created_by.name })}
              </p>
            )}
          </header>

          <div className="grid gap-6 lg:grid-cols-3">
            {/* Items lead, with the supporting detail tabs beneath. */}
            <div className="space-y-6 lg:col-span-2">
              <OrderItemsSection order={order} canManage={canManage} />

              <div className="space-y-4">
                <Tabs tabs={tabs} value={tab} onChange={(v) => setTab(v as DetailTab)} />
                {tab === "history" && <OrderHistorySection order={order} />}
                {tab === "comments" && <OrderCommentsSection order={order} />}
                {tab === "consignment" && order.is_consignment && (
                  <OrderConsignmentSection order={order} canManage={canManage} />
                )}
                {tab === "payments" && canViewFinance && <OrderPaymentsSection orderId={order.id} />}
              </div>
            </div>

            {/* Sidebar: prominent status control, the customer, and order details. */}
            <aside className="space-y-6">
              <OrderStatusUpdater order={order} canManage={canManage} />
              <OrderCustomerCard order={order} />
              <OrderDetailsCard order={order} canManage={canManage} />
              {canViewFinance && <OrderInflowsCard orderId={order.id} />}
            </aside>
          </div>
        </>
      )}
    </div>
  );
}
