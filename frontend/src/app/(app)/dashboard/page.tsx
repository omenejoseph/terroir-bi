"use client";

import * as React from "react";
import { AlertTriangle, Euro, ShoppingCart, Users } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useDashboard } from "@/hooks/use-dashboard";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { StatCard } from "@/components/dashboard/stat-card";
import {
  ChartCard,
  DonutChart,
  OrdersChart,
  RevenueChart,
  StockWatchChart,
  TopProductsChart,
} from "@/components/dashboard/charts";

const RANGES = ["7D", "30D", "90D", "1Y", "ALL"];

const STATUS_COLORS: Record<string, string> = {
  received: "var(--color-primary)",
  inProcess: "#d6a417",
  readyToShip: "#3b82f6",
  shipped: "#10b981",
};

const STATUS_VARIANT: Record<string, "default" | "secondary" | "success" | "outline"> = {
  received: "secondary",
  inProcess: "default",
  readyToShip: "outline",
  shipped: "success",
};

export default function DashboardPage() {
  const { user, tenants, activeTenantId } = useAuth();
  const { t } = useTranslation();
  const [range, setRange] = React.useState("30D");

  const { data, isLoading } = useDashboard(range);
  const activeTenant = tenants.find((x) => x.tenant_id === activeTenantId);

  // Locale + org currency aware; money fields are integer minor units.
  const { number: fmtNum, money, money2, moneyAxis } = useFormatters();
  const fmtNumber = { format: fmtNum };

  const rangeTabs = RANGES.map((r) => ({ value: r, label: r === "ALL" ? t("dashboard.range.all") : r }));

  return (
    <div className="space-y-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold tracking-tight">
            {t("dashboard.welcome", { name: user?.first_name ?? "" })}
          </h1>
          <p className="text-sm text-muted-foreground">
            {activeTenant ? activeTenant.name : t("dashboard.subtitle")}
          </p>
        </div>
        <Tabs tabs={rangeTabs} value={range} onChange={setRange} />
      </header>

      {isLoading || !data ? (
        <div className="flex items-center justify-center py-24">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      ) : (
        <>
          {/* Summary stats */}
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
              label={t("dashboard.stats.totalOrders")}
              value={fmtNumber.format(data.stats.total_orders)}
              icon={ShoppingCart}
              accent="bg-rose-500/10 text-rose-500"
              delayMs={80}
            />
            <StatCard
              label={t("dashboard.stats.customers")}
              value={fmtNumber.format(data.stats.customers)}
              icon={Users}
              accent="bg-violet-500/10 text-violet-500"
              delayMs={160}
            />
            <StatCard
              label={t("dashboard.stats.revenue")}
              value={money(data.stats.revenue)}
              icon={Euro}
              accent="bg-emerald-500/10 text-emerald-500"
              delayMs={240}
            />
            <StatCard
              label={t("dashboard.stats.lowStock")}
              value={fmtNumber.format(data.stats.low_stock)}
              icon={AlertTriangle}
              accent="bg-amber-500/10 text-amber-500"
              delayMs={320}
            />
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
              <CardContent className="space-y-3 pt-6">
                <div className="flex items-center justify-between">
                  <h3 className="text-sm font-semibold">{t("dashboard.recent.title")}</h3>
                  <button type="button" className="text-xs font-medium text-primary hover:underline">
                    {t("dashboard.recent.viewAll")}
                  </button>
                </div>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                      <tr>
                        <th className="py-2 pr-3 font-medium">{t("dashboard.recent.order")}</th>
                        <th className="py-2 pr-3 text-right font-medium">{t("dashboard.recent.items")}</th>
                        <th className="py-2 pr-3 text-right font-medium">{t("dashboard.recent.total")}</th>
                        <th className="py-2 pr-3 font-medium">{t("dashboard.recent.status")}</th>
                        <th className="py-2 font-medium">{t("dashboard.recent.date")}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.recent_orders.map((order) => (
                        <tr key={order.id} className="border-b border-border last:border-0">
                          <td className="py-2.5 pr-3">
                            <p className="font-medium">#{order.id}</p>
                            <p className="text-xs text-muted-foreground">{order.customer}</p>
                          </td>
                          <td className="py-2.5 pr-3 text-right tabular-nums">{order.items}</td>
                          <td className="py-2.5 pr-3 text-right tabular-nums">{money2(order.total)}</td>
                          <td className="py-2.5 pr-3">
                            <Badge variant={STATUS_VARIANT[order.status] ?? "secondary"}>
                              {t(`dashboard.status.${order.status}`)}
                            </Badge>
                          </td>
                          <td className="py-2.5 text-muted-foreground">{order.date}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </CardContent>
            </Card>
          </div>
        </>
      )}
    </div>
  );
}