"use client";

import * as React from "react";
import Link from "next/link";

import { useAuth } from "@/lib/auth/context";
import { useDashboard } from "@/hooks/use-dashboard";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { DashboardPeriod } from "@/lib/dashboard-period";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { PeriodSelector } from "@/components/dashboard/period-selector";
import { ReorderRadar } from "@/components/dashboard/reorder-radar";
import { RevenueCard } from "@/components/dashboard/revenue-card";
import { RevenueChannels } from "@/components/dashboard/revenue-channels";
import { KeyRatios } from "@/components/dashboard/key-ratios";
import { OrderItemsPreview } from "@/components/orders/order-items-preview";
import {
  ChartCard,
  DonutChart,
  OrdersChart,
  RevenueChart,
  StockWatchChart,
  TopProductsChart,
} from "@/components/dashboard/charts";

/** Percentage change vs. the prior period; null when there's no comparable base. */
function pctChange(current: number, previous: number | null): number | null {
  if (previous == null || previous === 0) return null;
  return Math.round(((current - previous) / previous) * 1000) / 10;
}

const STATUS_COLORS: Record<string, string> = {
  received: "#3b82f6",
  inProcess: "#f59e0b",
  readyToShip: "#a855f7",
  shipped: "#10b981",
};

const STATUS_VARIANT: Record<string, "info" | "warning" | "purple" | "success"> = {
  received: "info",
  inProcess: "warning",
  readyToShip: "purple",
  shipped: "success",
};

export default function DashboardPage() {
  const { user, tenants, activeTenantId } = useAuth();
  const { t } = useTranslation();
  const [period, setPeriod] = React.useState<DashboardPeriod>("mtd");
  const [customRange, setCustomRange] = React.useState<{ from?: string; to?: string }>({});

  const { data, isLoading, isFetching } = useDashboard({ period, from: customRange.from, to: customRange.to });
  const activeTenant = tenants.find((x) => x.tenant_id === activeTenantId);

  // Locale + org currency aware; money fields are integer minor units.
  const { number: fmtNum, money2, moneyAxis } = useFormatters();
  const fmtNumber = { format: fmtNum };

  const handlePeriodChange = (next: DashboardPeriod, from?: string, to?: string) => {
    setPeriod(next);
    setCustomRange(next === "custom" ? { from, to } : {});
  };

  return (
    <div className="space-y-6">
      <header className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">
          {t("dashboard.welcome", { name: user?.first_name ?? "" })}
        </h1>
        <p className="text-sm text-muted-foreground">
          {activeTenant ? activeTenant.name : t("dashboard.subtitle")}
        </p>
      </header>

      {isLoading || !data ? (
        <div className="flex items-center justify-center py-24">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      ) : (
        <>
          {/* Revenue summary */}
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <RevenueCard
              label={t("dashboard.summary.today")}
              value={money2(data.revenue_summary.today.current)}
              comparisonValue={money2(data.revenue_summary.today.previous ?? 0)}
              deltaPct={pctChange(data.revenue_summary.today.current, data.revenue_summary.today.previous)}
              hint={t("dashboard.summary.periodHint")}
              delayMs={80}
            />
            <RevenueCard
              label={t("dashboard.summary.monthToDate")}
              value={money2(data.revenue_summary.mtd.current)}
              comparisonValue={money2(data.revenue_summary.mtd.previous ?? 0)}
              deltaPct={pctChange(data.revenue_summary.mtd.current, data.revenue_summary.mtd.previous)}
              hint={t("dashboard.summary.periodHint")}
              delayMs={160}
            />
            <RevenueCard
              label={t("dashboard.summary.yearToDate")}
              value={money2(data.revenue_summary.ytd.current)}
              comparisonValue={money2(data.revenue_summary.ytd.previous ?? 0)}
              deltaPct={pctChange(data.revenue_summary.ytd.current, data.revenue_summary.ytd.previous)}
              hint={t("dashboard.summary.periodHint")}
              delayMs={240}
            />
            <RevenueCard
              label={t("dashboard.summary.total")}
              value={money2(data.revenue_summary.total.current)}
              caption={t("dashboard.summary.allSources")}
              hint={t("dashboard.summary.totalHint")}
              delayMs={320}
            />
          </div>

          {/* Period filter — applies to everything below; the summary cards above
              are always-on (today / MTD / YTD / total) and don't move with it. */}
          <div className="flex flex-wrap items-start justify-between gap-2 border-t border-border/60 pt-4">
            <PeriodSelector
              period={period}
              customFrom={customRange.from}
              customTo={customRange.to}
              onChange={handlePeriodChange}
            />
            {isFetching && <Spinner className="size-3.5 text-muted-foreground" />}
          </div>

          {/* Key financial ratios */}
          <KeyRatios data={data.key_ratios} delayMs={90} />

          {/* Revenue by channel + reorder radar */}
          <div className="grid gap-4 lg:grid-cols-3">
            <RevenueChannels data={data.revenue_by_channel} delayMs={100} />
            <div className="lg:col-span-2">
              <ReorderRadar delayMs={120} />
            </div>
          </div>

          {/* Orders + status */}
          <div className="grid gap-4 lg:grid-cols-3">
            <ChartCard
              title={t("dashboard.orders.title")}
              subtitle={t("dashboard.orders.subtitle")}
              delayMs={120}
              className="lg:col-span-2"
            >
              <OrdersChart data={data.orders} formatValue={(n) => fmtNumber.format(n)} />
            </ChartCard>

            <ChartCard title={t("dashboard.status.title")} delayMs={200}>
              <DonutChart
                data={data.order_status.map((s) => ({
                  key: s.key,
                  value: s.value,
                  color: STATUS_COLORS[s.key] ?? "var(--color-muted)",
                }))}
                centerValue={fmtNumber.format(data.stats.total_orders)}
                centerLabel={t("dashboard.orders.center")}
              />
              <ul className="mt-2 space-y-1.5">
                {data.order_status.map((s) => (
                  <li key={s.key} className="flex items-center justify-between text-sm">
                    <span className="flex items-center gap-2">
                      <span className="size-2.5 rounded-full" style={{ backgroundColor: STATUS_COLORS[s.key] }} />
                      {t(`dashboard.status.${s.key}`)}
                    </span>
                    <span className="font-medium tabular-nums">{s.value}</span>
                  </li>
                ))}
              </ul>
            </ChartCard>
          </div>

          {/* Revenue + top products */}
          <div className="grid gap-4 lg:grid-cols-3">
            <ChartCard
              title={t("dashboard.revenue.title")}
              subtitle={t("dashboard.revenue.profitHint")}
              delayMs={160}
              className="lg:col-span-2"
            >
              <RevenueChart data={data.revenue} formatValue={money2} formatAxis={moneyAxis} />
            </ChartCard>

            <ChartCard title={t("dashboard.topProducts")} delayMs={240}>
              <TopProductsChart data={data.top_products} formatValue={(n) => fmtNumber.format(n)} />
            </ChartCard>
          </div>

          {/* Stock watch + recent orders */}
          <div className="grid gap-4 lg:grid-cols-3">
            <ChartCard title={t("dashboard.stockWatch")} delayMs={200}>
              <StockWatchChart
                data={data.stock_watch.map((s) => ({
                  name: s.name,
                  stock: Number(s.stock),
                  min: Number(s.min),
                }))}
              />
            </ChartCard>

            <Card
              style={{ animationDelay: "280ms" }}
              className="animate-fade-up border-border/60 lg:col-span-2"
            >
              <CardContent className="space-y-1 pt-6">
                <div className="flex items-center justify-between pb-2">
                  <h3 className="text-sm font-semibold">{t("dashboard.recent.title")}</h3>
                  <Link href="/orders" className="text-xs font-medium text-primary hover:underline">
                    {t("dashboard.recent.viewAll")}
                  </Link>
                </div>
                <ul className="divide-y divide-border">
                  {data.recent_orders.map((order) => (
                    <li key={order.id}>
                      <Link
                        href={`/orders/${order.id}`}
                        className="-mx-2 flex items-start justify-between gap-3 rounded-md px-2 py-2.5 transition-colors hover:bg-muted/40"
                      >
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-medium">
                            {order.order_number}
                            <span className="ml-2 font-normal text-muted-foreground">{order.customer}</span>
                          </p>
                          <OrderItemsPreview items={order.items} className="mt-1" />
                        </div>
                        <div className="flex shrink-0 flex-col items-end gap-1 text-sm">
                          <span className="font-semibold tabular-nums">{money2(order.total)}</span>
                          <Badge variant={STATUS_VARIANT[order.status] ?? "secondary"}>
                            {t(`dashboard.status.${order.status}`)}
                          </Badge>
                          <span className="text-xs text-muted-foreground">
                            {[order.date, order.created_by].filter(Boolean).join(" · ")}
                          </span>
                        </div>
                      </Link>
                    </li>
                  ))}
                </ul>
              </CardContent>
            </Card>
          </div>
        </>
      )}
    </div>
  );
}