"use client";

import * as React from "react";
import Link from "next/link";
import {
  AlertTriangle,
  ArrowLeft,
  DollarSign,
  FlaskConical,
  Layers,
  Package,
  ShoppingCart,
  Wine,
  XCircle,
} from "lucide-react";

import { useInventoryAnalytics } from "@/hooks/use-dashboard";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import { cn } from "@/lib/utils";
import type { InventoryExitMetrics, Money } from "@/lib/types";
import { Card, CardContent } from "@/components/ui/card";
import { DashboardBodySkeleton } from "@/components/skeletons";
import {
  ChartCard,
  DonutChart,
  MovementsLineChart,
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
          {/* Row 1 — core KPIs */}
          <div className="grid grid-cols-2 gap-2 md:gap-3 lg:grid-cols-4">
            <Kpi
              label={t("inventory.analytics.summary.totalProducts")}
              icon={Package}
              value={number(data.summary.total_active)}
              sub={t("inventory.analytics.summary.activeItems")}
            />
            <Kpi
              label={t("inventory.analytics.summary.lowStock")}
              icon={AlertTriangle}
              value={number(data.summary.low_stock)}
              valueClass={data.summary.low_stock > 0 ? "text-orange-500" : "text-green-600"}
              border={data.summary.low_stock > 0 ? "border-orange-300 dark:border-orange-700" : undefined}
              sub={
                data.summary.low_stock > 0
                  ? t("inventory.analytics.summary.belowMin")
                  : t("inventory.analytics.summary.allStocked")
              }
            />
            <Kpi
              label={t("inventory.analytics.summary.saleValue")}
              icon={DollarSign}
              value={moneyObject(data.summary.sale_value)}
              valueClass="text-emerald-700 dark:text-emerald-400"
              sub={
                <>
                  <span className="block">
                    {t("inventory.analytics.summary.withPrice", { count: data.summary.priced_count })}
                  </span>
                  <span className="mt-1.5 block border-t border-border/50 pt-1.5">
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
            <Kpi
              label={t("inventory.analytics.summary.outOfStock")}
              icon={XCircle}
              value={number(data.summary.out_of_stock)}
              valueClass={data.summary.out_of_stock > 0 ? "text-red-600" : "text-green-600"}
              border={data.summary.out_of_stock > 0 ? "border-red-300 dark:border-red-700" : undefined}
              sub={
                data.summary.out_of_stock > 0
                  ? t("inventory.analytics.summary.atZero")
                  : t("inventory.analytics.summary.allInStock")
              }
            />
          </div>

          {/* Row 2 — by category */}
          <div className="grid grid-cols-2 gap-2 md:gap-3 lg:grid-cols-4">
            <Kpi
              label={t("inventory.category.FINISHED")}
              icon={Wine}
              value={number(data.summary.by_category.FINISHED)}
              sub={t("inventory.analytics.products")}
            />
            <Kpi
              label={t("inventory.category.SEMI_FINISHED")}
              icon={FlaskConical}
              value={number(data.summary.by_category.SEMI_FINISHED)}
              sub={t("inventory.analytics.products")}
            />
            <Kpi
              label={t("inventory.category.RAW_MATERIAL")}
              icon={Layers}
              value={number(data.summary.by_category.RAW_MATERIAL)}
              sub={t("inventory.analytics.products")}
            />
            <Kpi
              label={t("inventory.analytics.summary.forSale")}
              icon={ShoppingCart}
              value={number(data.summary.for_sale)}
              sub={t("inventory.analytics.products")}
            />
          </div>

          {/* Warehouse exit — portfolio */}
          <ChartCard
            title={t("inventory.analytics.portfolio.title")}
            subtitle={t("inventory.analytics.portfolio.subtitle")}
          >
            <div className="space-y-4">
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

              {/* Internal / own-channel exits (only when present) */}
              {data.portfolio_exits.internal && (
                <div className="border-t border-border pt-3">
                  <ExitColumn
                    heading={t("inventory.analytics.portfolio.internal")}
                    metrics={data.portfolio_exits.internal}
                    internal
                  />
                  <p className="mt-1 text-[11px] text-amber-600 dark:text-amber-400">
                    {t("inventory.analytics.portfolio.internalNote")}
                  </p>
                </div>
              )}

              {/* Exit by channel */}
              {data.portfolio_exits.channels.length > 0 && (
                <ChannelBreakdown channels={data.portfolio_exits.channels} />
              )}
            </div>
          </ChartCard>

          {/* 12-month movements */}
          <ChartCard title={t("inventory.analytics.charts.movements")}>
            <MovementsLineChart
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

function Kpi({
  label,
  icon: Icon,
  value,
  valueClass,
  sub,
  border,
}: {
  label: string;
  icon: React.ComponentType<{ className?: string }>;
  value: React.ReactNode;
  valueClass?: string;
  sub?: React.ReactNode;
  border?: string;
}) {
  return (
    <Card className={cn("animate-fade-up border-border/60", border)}>
      <CardContent className="p-3 md:p-4">
        <div className="flex items-center justify-between gap-2 pb-1">
          <p className="text-[10px] font-medium text-muted-foreground sm:text-xs">{label}</p>
          <Icon className="size-3.5 shrink-0 text-muted-foreground" />
        </div>
        <p className={cn("text-xl font-bold tabular-nums md:text-2xl", valueClass)}>{value}</p>
        {sub && <div className="text-[10px] text-muted-foreground">{sub}</div>}
      </CardContent>
    </Card>
  );
}

function ExitColumn({
  heading,
  metrics,
  external = false,
  internal = false,
}: {
  heading: string;
  metrics: InventoryExitMetrics;
  external?: boolean;
  internal?: boolean;
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
      {external && (
        <>
          <Metric label={t("inventory.analytics.portfolio.meanMargin")} value={pctOr(metrics.mean_margin_percent)} />
          <Metric label={t("inventory.analytics.portfolio.meanPrice")} value={moneyOr(metrics.mean_price)} />
          <Metric label={t("inventory.analytics.portfolio.offTarget")} value={pctOr(metrics.off_target_percent)} />
        </>
      )}
      {!external && !internal && (
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

/** Margin % → tone (healthy / warning / bleeding), matching the prototype. */
function marginTone(pct: string | null): string {
  if (pct == null) return "text-muted-foreground";
  const v = Number(pct);
  if (v >= 35) return "text-emerald-600 dark:text-emerald-400";
  if (v >= 20) return "text-amber-600 dark:text-amber-400";
  return "text-red-600 dark:text-red-400";
}

/** Warehouse exits broken down by channel (sales / production / manual). */
function ChannelBreakdown({
  channels,
}: {
  channels: { key: string; units: number; revenue: Money | null; margin_percent: string | null; share_percent: string }[];
}) {
  const { t } = useTranslation();
  const { number, moneyObject } = useFormatters();
  const maxUnits = Math.max(1, ...channels.map((c) => c.units));

  return (
    <div className="border-t border-border pt-3">
      <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
        {t("inventory.analytics.portfolio.channelTitle")}
      </p>
      <table className="w-full text-sm">
        <thead className="border-b border-border text-left text-xs text-muted-foreground">
          <tr>
            <th className="py-1.5 pr-3 font-medium">{t("inventory.analytics.portfolio.colChannel")}</th>
            <th className="py-1.5 pr-3 text-right font-medium">{t("inventory.analytics.portfolio.colQty")}</th>
            <th className="hidden py-1.5 pr-3 text-right font-medium sm:table-cell">{t("inventory.analytics.portfolio.revenue")}</th>
            <th className="py-1.5 text-right font-medium">{t("inventory.analytics.portfolio.colMargin")}</th>
          </tr>
        </thead>
        <tbody>
          {channels.map((c) => (
            <tr key={c.key} className="border-b border-border/60 last:border-0">
              <td className="py-2 pr-3">
                <span className="font-medium">{t(`inventory.stock.channel.${c.key}`)}</span>
                <div className="mt-1 h-1 w-full max-w-[160px] overflow-hidden rounded-full bg-muted">
                  <div className="h-full rounded-full bg-rose-800" style={{ width: `${(c.units / maxUnits) * 100}%` }} />
                </div>
                <p className="mt-0.5 text-[10px] text-muted-foreground">
                  {t("inventory.analytics.portfolio.shareOfUnits", { pct: c.share_percent })}
                </p>
              </td>
              <td className="py-2 pr-3 text-right tabular-nums">{number(c.units)}</td>
              <td className="hidden py-2 pr-3 text-right tabular-nums text-muted-foreground sm:table-cell">
                {c.revenue ? moneyObject(c.revenue) : "—"}
              </td>
              <td className="py-2 text-right">
                <span className={cn("font-semibold tabular-nums", marginTone(c.margin_percent))}>
                  {c.margin_percent != null ? `${c.margin_percent}%` : "—"}
                </span>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
