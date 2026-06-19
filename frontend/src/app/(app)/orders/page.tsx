"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { BarChart3, Plus } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useOrders } from "@/hooks/use-orders";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import { ORDER_STATUSES, type Order, type OrderQuery, type OrderStatus } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { MetaField } from "@/components/ui/meta-field";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { OrderItemsPreview } from "@/components/orders/order-items-preview";
import { DemandForecast } from "@/components/orders/demand-forecast";

type StatusTab = OrderStatus | "ALL";

const STATUS_VARIANT: Record<OrderStatus, "info" | "warning" | "purple" | "success"> = {
  RECEIVED: "info",
  IN_PROCESS: "warning",
  READY_TO_SHIP: "purple",
  SHIPPED: "success",
};

export default function OrdersPage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const router = useRouter();
  const { money, date } = useFormatters();

  const canFinancials = can("financials.view");
  const [view, setView] = React.useState<"orders" | "forecast">("orders");
  const [tab, setTab] = React.useState<StatusTab>("ALL");
  const [hideShipped, setHideShipped] = React.useState(false);
  const [search, setSearch] = React.useState("");
  const [debounced, setDebounced] = React.useState("");
  const [page, setPage] = React.useState(1);

  React.useEffect(() => {
    const id = setTimeout(() => setDebounced(search), 300);
    return () => clearTimeout(id);
  }, [search]);

  // Reset to page 1 when filters change.
  React.useEffect(() => setPage(1), [tab, hideShipped, debounced]);

  const query: OrderQuery = {
    ...(debounced ? { search: debounced } : {}),
    ...(tab === "ALL" ? {} : { status: tab }),
    ...(hideShipped ? { hide_shipped: true } : {}),
    page,
  };
  const { data, isLoading, isError, error } = useOrders(query);
  const orders = data?.data ?? [];
  const meta = data?.meta;

  const tabs = [
    { value: "ALL", label: t("orders.tabs.all") },
    ...ORDER_STATUSES.map((s) => ({ value: s, label: t(`orders.status.${s}`) })),
  ];

  return (
    <div className="space-y-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold tracking-tight">{t("orders.title")}</h1>
          <p className="text-sm text-muted-foreground">
            {meta ? t("orders.subtitleCount", { count: meta.total }) : t("orders.subtitleDefault")}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {view === "orders" && (
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={t("orders.search")}
              className="w-full sm:w-auto sm:max-w-xs"
            />
          )}
          {can("financials.view") && (
            <Button variant="outline" onClick={() => router.push("/orders/analytics")} className="shrink-0">
              <BarChart3 className="size-4" />
              {t("orders.analytics.title")}
            </Button>
          )}
          {can("orders.manage") && (
            <Button onClick={() => router.push("/orders/new")} className="shrink-0">
              <Plus className="size-4" />
              {t("orders.add")}
            </Button>
          )}
        </div>
      </header>

      {canFinancials && (
        <Tabs
          tabs={[
            { value: "orders", label: t("orders.viewTabs.orders") },
            { value: "forecast", label: t("orders.viewTabs.forecast") },
          ]}
          value={view}
          onChange={(v) => setView(v as "orders" | "forecast")}
        />
      )}

      {view === "forecast" ? (
        <DemandForecast />
      ) : (
        <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Tabs tabs={tabs} value={tab} onChange={(v) => setTab(v as StatusTab)} />
        <label className="flex items-center gap-2 text-sm text-muted-foreground">
          <Checkbox checked={hideShipped} onChange={(e) => setHideShipped(e.target.checked)} />
          {t("orders.hideShipped")}
        </label>
      </div>

      {isLoading && (
        <div className="flex items-center justify-center py-16">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      )}

      {isError && (
        <Card>
          <CardContent className="py-8 text-center text-sm text-destructive">
            {error instanceof ApiError && error.status === 403
              ? t("orders.errorForbidden")
              : t("orders.errorGeneric")}
          </CardContent>
        </Card>
      )}

      {!isLoading && !isError && orders.length === 0 && (
        <Card>
          <CardContent className="py-12 text-center text-sm text-muted-foreground">
            {t("orders.empty")}
          </CardContent>
        </Card>
      )}

      {!isLoading && !isError && orders.length > 0 && (
        <div className="space-y-2">
          {orders.map((order) => (
            <OrderRow
              key={order.id}
              order={order}
              money={money}
              date={date}
              onSelect={() => router.push(`/orders/${order.id}`)}
            />
          ))}
        </div>
      )}

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm">
          <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            {t("orders.prev")}
          </Button>
          <span className="text-muted-foreground">
            {t("orders.pageOf", { page: meta.current_page, total: meta.last_page })}
          </span>
          <Button
            variant="outline"
            size="sm"
            disabled={page >= meta.last_page}
            onClick={() => setPage((p) => p + 1)}
          >
            {t("orders.next")}
          </Button>
        </div>
      )}
        </>
      )}
    </div>
  );
}

function OrderRow({
  order,
  money,
  date,
  onSelect,
}: {
  order: Order;
  money: (minor: number) => string;
  date: (value: string) => string;
  onSelect: () => void;
}) {
  const { t } = useTranslation();
  return (
    <Card className="overflow-hidden">
      <button
        type="button"
        onClick={onSelect}
        className="flex w-full items-start justify-between gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/40"
      >
        <div className="min-w-0 flex-1">
          <p className="truncate font-medium">{order.order_number}</p>
          {/* Key fields as labelled values, consistent with the other list cards. */}
          <div className="mt-1 flex flex-wrap gap-x-4 gap-y-0.5">
            <MetaField label={t("orders.card.customer")}>
              {order.customer?.company_name ?? t("orders.noCustomer")}
            </MetaField>
            <MetaField label={t("orders.card.total")}>
              {money(order.total_amount.minor)}
              <span className="ml-1 text-[10px] font-normal text-muted-foreground">{t("orders.exclVat")}</span>
            </MetaField>
            {order.created_at && (
              <MetaField label={t("orders.card.date")}>{date(order.created_at)}</MetaField>
            )}
          </div>
          <OrderItemsPreview items={order.items} group max={Infinity} className="mt-1.5" />
          {order.created_by && (
            <p className="mt-1.5 text-xs text-muted-foreground">{order.created_by.name}</p>
          )}
        </div>
        <div className="flex shrink-0 items-center gap-2 self-center">
          {order.is_backorder && <Badge variant="outline">{t("orders.backorderBadge")}</Badge>}
          {order.is_consignment && <Badge variant="outline">{t("orders.consignmentBadge")}</Badge>}
          <Badge variant={STATUS_VARIANT[order.status]}>{t(`orders.status.${order.status}`)}</Badge>
        </div>
      </button>
    </Card>
  );
}
