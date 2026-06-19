"use client";

import Link from "next/link";
import { CalendarClock, Target, TrendingUp, Activity } from "lucide-react";

import { useCustomerOrderAnalytics } from "@/hooks/use-customers";
import { useOrders } from "@/hooks/use-orders";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { OrderStatus } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { StatCard } from "@/components/dashboard/stat-card";
import { ChartCard, RevenueChart } from "@/components/dashboard/charts";
import { OrderItemsPreview } from "@/components/orders/order-items-preview";

const STATUS_VARIANT: Record<OrderStatus, "info" | "warning" | "purple" | "success"> = {
  RECEIVED: "info",
  IN_PROCESS: "warning",
  READY_TO_SHIP: "purple",
  SHIPPED: "success",
};

export function CustomerOrdersSection({
  customerId,
  canViewFinancials,
}: {
  customerId: string;
  canViewFinancials: boolean;
}) {
  const { t } = useTranslation();
  const { moneyObject, money2, moneyAxis, date, number, monthShort } = useFormatters();
  const analyticsQ = useCustomerOrderAnalytics(customerId, canViewFinancials);
  const ordersQ = useOrders({ customer_id: customerId });

  const a = analyticsQ.data;
  const orders = ordersQ.data?.data ?? [];

  const yoy = a ? Number(a.yoy_growth_percent) : 0;
  const yoyLabel = a ? `${yoy > 0 ? "+" : ""}${number(yoy)}%` : "—";

  return (
    <div className="space-y-6">
      {canViewFinancials && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            label={t("customers.orders.yoyGrowth")}
            value={analyticsQ.isLoading ? "…" : yoyLabel}
            icon={TrendingUp}
            accent="bg-emerald-500/10 text-emerald-500"
          />
          <StatCard
            label={t("customers.orders.annualProjection")}
            value={analyticsQ.isLoading ? "…" : moneyObject(a?.annual_projection ?? null)}
            icon={Target}
            accent="bg-sky-500/10 text-sky-500"
            delayMs={50}
          />
          <StatCard
            label={t("customers.orders.expectedNext")}
            value={
              analyticsQ.isLoading
                ? "…"
                : a?.expected_next_order_date
                  ? date(a.expected_next_order_date)
                  : t("customers.orders.never")
            }
            icon={CalendarClock}
            accent="bg-amber-500/10 text-amber-500"
            delayMs={100}
          />
          <StatCard
            label={t("customers.orders.nextQuarter")}
            value={analyticsQ.isLoading ? "…" : moneyObject(a?.next_quarter_projection ?? null)}
            icon={Activity}
            accent="bg-violet-500/10 text-violet-500"
            delayMs={150}
          />
        </div>
      )}

      {canViewFinancials &&
        a?.monthly_revenue &&
        a.monthly_revenue.some((m) => m.revenue.minor > 0) && (
          <ChartCard title={t("customers.orders.revenueTrend")}>
            <RevenueChart
              data={a.monthly_revenue.map((m) => ({
                label: monthShort(`${m.month}-01`),
                value: m.revenue.minor,
              }))}
              formatValue={money2}
              formatAxis={moneyAxis}
            />
          </ChartCard>
        )}

      {canViewFinancials && a?.expected_next_3m && a.expected_next_3m.length > 0 && (
        <Card>
          <CardContent className="pt-6">
            <div className="mb-3">
              <h3 className="text-sm font-semibold">{t("customers.orders.next3mTitle")}</h3>
              <p className="text-xs text-muted-foreground">{t("customers.orders.next3mSubtitle")}</p>
            </div>
            <ul className="divide-y divide-border">
              {a.expected_next_3m.map((m) => (
                <li key={m.month} className="flex items-center justify-between gap-3 py-2 text-sm">
                  <span className="font-medium">{monthShort(`${m.month}-01`)}</span>
                  <span className="flex items-center gap-3">
                    <span className="text-xs text-muted-foreground">
                      {t("customers.orders.lastYearShort")} {moneyObject(m.last_year)}
                    </span>
                    <span className="font-semibold tabular-nums">{moneyObject(m.expected)}</span>
                  </span>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      )}

      <Card>
        <CardContent className="pt-6">
          <h3 className="mb-3 text-sm font-semibold">{t("customers.orders.historyTitle")}</h3>
          {ordersQ.isLoading ? (
            <div className="flex justify-center py-8">
              <Spinner className="size-5 text-muted-foreground" />
            </div>
          ) : orders.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t("customers.orders.historyEmpty")}</p>
          ) : (
            <ul className="divide-y divide-border">
              {orders.map((order) => (
                <li key={order.id}>
                  <Link
                    href={`/orders/${order.id}`}
                    className="-mx-2 flex items-start justify-between gap-3 rounded-md px-2 py-2.5 transition-colors hover:bg-muted/40"
                  >
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium">{order.order_number}</p>
                      {order.created_at && (
                        <p className="truncate text-xs text-muted-foreground">
                          {date(order.created_at)}
                        </p>
                      )}
                      <OrderItemsPreview items={order.items} className="mt-1" />
                    </div>
                    <div className="flex shrink-0 flex-col items-end gap-1">
                      <Badge variant={STATUS_VARIANT[order.status]}>
                        {t(`orders.status.${order.status}`)}
                      </Badge>
                      <span className="text-sm font-semibold tabular-nums">
                        {moneyObject(order.total_amount)}
                      </span>
                    </div>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
