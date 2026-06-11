"use client";

import * as React from "react";
import Link from "next/link";
import { ArrowLeft } from "lucide-react";

import { useInventoryAnalytics } from "@/hooks/use-dashboard";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { InventoryExitMetrics, Money } from "@/lib/types";
import { Card, CardContent } from "@/components/ui/card";
import { DashboardBodySkeleton } from "@/components/skeletons";
import {
  ChartCard,
  DonutChart,
  MovementsBarChart,
  TopProductsChart,
} from "@/components/dashboard/charts";

const CATEGORY_COLORS: Record<string, string> = {
  FINISHED: "#7c3aed",
  SEMI_FINISHED: "#0ea5e9",
  RAW_MATERIAL: "#f59e0b",
};

export default function InventoryAnalyticsPage() {
  const { t } = useTranslation();
  const { number, money, moneyObject, monthShort } = useFormatters();
  const { data, isLoading } = useInventoryAnalytics();

  return (
    <div className="space-y-6">
      <Link
        href="/inventory"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("inventory.page.back")}
      </Link>

      <h1 className="text-2xl font-semibold tracking-tight">{t("inventory.analytics.title")}</h1>

      {isLoading || !data ? (
        <DashboardBodySkeleton />
      ) : (
        <>
          {/* Summary cards */}
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <SummaryCard
              label={t("inventory.analytics.summary.totalProducts")}
              value={number(data.summary.total_active)}
              sub={t("inventory.analytics.summary.activeItems")}
            />
            <SummaryCard
              label={t("inventory.analytics.summary.lowStock")}
              value={number(data.summary.low_stock)}
              sub={t("inventory.analytics.summary.belowMin")}
            />
            <SummaryCard
              label={t("inventory.analytics.summary.saleValue")}
              value={moneyObject(data.summary.sale_value)}
              sub={
                <>
                  <span className="block">
                    {t("inventory.analytics.summary.withPrice", { count: data.summary.priced_count })}
                  </span>
                  <span className="block">
                    {t("inventory.analytics.summary.production", {
                      value: moneyObject(data.summary.production_value),
                    })}
                  </span>
                  <span className="block">
                    {t("inventory.analytics.summary.margin", { pct: data.summary.margin_percent })}
                  </span>
                </>
              }
            />
            <SummaryCard
              label={t("inventory.analytics.summary.outOfStock")}
              value={number(data.summary.out_of_stock)}
              sub={
                data.summary.out_of_stock > 0
                  ? t("inventory.analytics.summary.needsAttention")
                  : t("inventory.analytics.summary.allInStock")
              }
            />
            <SummaryCard
              label={t("inventory.category.FINISHED")}
              value={number(data.summary.by_category.FINISHED)}
              sub={t("inventory.analytics.products")}
            />
            <SummaryCard
              label={t("inventory.category.SEMI_FINISHED")}
              value={number(data.summary.by_category.SEMI_FINISHED)}
              sub={t("inventory.analytics.products")}
            />
            <SummaryCard
              label={t("inventory.category.RAW_MATERIAL")}
              value={number(data.summary.by_category.RAW_MATERIAL)}
              sub={t("inventory.analytics.products")}
            />
            <SummaryCard
              label={t("inventory.analytics.summary.forSale")}
              value={number(data.summary.for_sale)}
              sub={t("inventory.analytics.products")}
            />
          </div>

          {/* Warehouse exit — portfolio */}
          <ChartCard
            title={t("inventory.analytics.portfolio.title")}
            subtitle={t("inventory.analytics.portfolio.subtitle")}
          >
            <div className="grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2">
              <ExitColumn
                heading={t("inventory.analytics.portfolio.external")}
                metrics={data.portfolio_exits.external}
                external
              />
              <ExitColumn
                heading={t("inventory.analytics.portfolio.blended")}
                metrics={data.portfolio_exits.blended}
              />
            </div>
          </ChartCard>

          {/* 12-month movements */}
          <ChartCard title={t("inventory.analytics.charts.movements")}>
            <MovementsBarChart
              data={data.movements_12m.map((m) => ({
                month: monthShort(`${m.month}-01`),
                in: m.in,
                out: m.out,
              }))}
            />
          </ChartCard>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {/* Stock value by category */}
            <ChartCard title={t("inventory.analytics.charts.stockValue")}>
              <DonutChart
                data={data.value.categories.map((c) => ({
                  key: t(`inventory.category.${c.category}`),
                  value: c.value,
                  color: CATEGORY_COLORS[c.category] ?? "#94a3b8",
                }))}
                centerValue={money(data.value.total)}
                centerLabel={t("inventory.analytics.charts.total")}
              />
              <ul className="space-y-1 text-sm">
                {data.value.categories.map((c) => (
                  <li key={c.category} className="flex items-center justify-between">
                    <span className="flex items-center gap-2">
                      <span
                        className="size-2.5 rounded-full"
                        style={{ background: CATEGORY_COLORS[c.category] ?? "#94a3b8" }}
                      />
                      {t(`inventory.category.${c.category}`)}
                    </span>
                    <span className="tabular-nums text-muted-foreground">{money(c.value)}</span>
                  </li>
                ))}
              </ul>
            </ChartCard>

            {/* Top products by value */}
            <ChartCard title={t("inventory.analytics.charts.topProducts")}>
              <TopProductsChart
                data={data.top_products.map((p) => ({ name: p.name, value: p.value }))}
                formatValue={(n) => money(n)}
              />
            </ChartCard>
          </div>

          {/* Items by group */}
          <ChartCard title={t("inventory.analytics.charts.byGroup")}>
            <ul className="divide-y divide-border">
              {data.by_group.map((g) => (
                <li
                  key={g.group ?? "__none__"}
                  className="flex items-center justify-between py-2 text-sm"
                >
                  <span>{g.group ?? t("inventory.analytics.charts.ungrouped")}</span>
                  <span className="tabular-nums font-medium">{number(g.count)}</span>
                </li>
              ))}
            </ul>
          </ChartCard>
        </>
      )}
    </div>
  );
}

function SummaryCard({
  label,
  value,
  sub,
}: {
  label: string;
  value: React.ReactNode;
  sub?: React.ReactNode;
}) {
  return (
    <Card className="animate-fade-up border-border/60">
      <CardContent className="space-y-1 pt-5">
        <p className="text-xs uppercase tracking-wide text-muted-foreground">{label}</p>
        <p className="text-2xl font-semibold tabular-nums">{value}</p>
        {sub && <div className="text-xs text-muted-foreground">{sub}</div>}
      </CardContent>
    </Card>
  );
}

function ExitColumn({
  heading,
  metrics,
  external = false,
}: {
  heading: string;
  metrics: InventoryExitMetrics;
  external?: boolean;
}) {
  const { t } = useTranslation();
  const { number, moneyObject } = useFormatters();
  const dash = "—";
  const moneyOr = (m: Money | null | undefined) => (m ? moneyObject(m) : dash);
  const pctOr = (p: string | null | undefined) => (p != null ? `${p}%` : dash);

  return (
    <div>
      <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
        {heading}
      </p>
      <Metric
        label={t("inventory.analytics.portfolio.unitsExited")}
        value={t("inventory.analytics.portfolio.bottles", { count: number(metrics.units_exited) })}
      />
      <Metric label={t("inventory.analytics.portfolio.costOfExits")} value={moneyOr(metrics.cost_of_exits)} />
      <Metric label={t("inventory.analytics.portfolio.revenue")} value={moneyOr(metrics.revenue_realized)} />
      {external ? (
        <>
          <Metric label={t("inventory.analytics.portfolio.meanMargin")} value={pctOr(metrics.mean_margin_percent)} />
          <Metric label={t("inventory.analytics.portfolio.meanPrice")} value={moneyOr(metrics.mean_price)} />
          <Metric label={t("inventory.analytics.portfolio.offTarget")} value={pctOr(metrics.off_target_percent)} />
        </>
      ) : (
        <Metric
          label={t("inventory.analytics.portfolio.velocity")}
          value={t("inventory.analytics.portfolio.velocityValue", {
            count: metrics.velocity_per_day ?? "0.0",
          })}
        />
      )}
    </div>
  );
}

function Metric({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-2 border-b border-border/60 py-1.5 text-sm last:border-0">
      <span className="text-muted-foreground">{label}</span>
      <span className="font-medium tabular-nums">{value}</span>
    </div>
  );
}
