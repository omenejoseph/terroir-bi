"use client";

import * as React from "react";
import Link from "next/link";
import { ArrowLeft, Boxes, Euro, Percent, Receipt, TrendingUp, Wallet } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useOrderAnalytics } from "@/hooks/use-orders";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { StatCard } from "@/components/dashboard/stat-card";

const RANGES = ["30D", "90D", "1Y", "ALL"] as const;
type Range = (typeof RANGES)[number];

const PERIOD: Record<Range, string> = { "30D": "30d", "90D": "90d", "1Y": "1y", ALL: "all" };

export default function OrderAnalyticsPage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const { moneyObject, number } = useFormatters();
  const [range, setRange] = React.useState<Range>("30D");

  const allowed = can("financials.view");
  const { data, isLoading } = useOrderAnalytics({ period: PERIOD[range] }, allowed);

  if (!allowed) {
    return (
      <Card>
        <CardContent className="py-12 text-center text-sm text-muted-foreground">
          {t("orders.analytics.forbidden")}
        </CardContent>
      </Card>
    );
  }

  const rangeTabs = RANGES.map((r) => ({ value: r, label: t(`orders.analytics.range.${r}`) }));

  return (
    <div className="space-y-6">
      <Link
        href="/orders"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("orders.back")}
      </Link>

      <header className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold tracking-tight">{t("orders.analytics.title")}</h1>
          <p className="text-sm text-muted-foreground">{t("orders.analytics.subtitle")}</p>
        </div>
        <Tabs tabs={rangeTabs} value={range} onChange={(v) => setRange(v as Range)} />
      </header>

      {isLoading || !data ? (
        <div className="flex justify-center py-16">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      ) : (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <StatCard label={t("orders.analytics.revenue")} value={moneyObject(data.revenue)} icon={Euro} accent="bg-emerald-500/10 text-emerald-600" />
            <StatCard label={t("orders.analytics.cogs")} value={moneyObject(data.cogs)} icon={Wallet} accent="bg-amber-500/10 text-amber-600" />
            <StatCard label={t("orders.analytics.grossProfit")} value={moneyObject(data.gross_profit)} icon={TrendingUp} accent="bg-primary/10 text-primary" />
            <StatCard label={t("orders.analytics.margin")} value={`${data.margin_percent}%`} icon={Percent} accent="bg-blue-500/10 text-blue-600" />
            <StatCard label={t("orders.analytics.orderCount")} value={number(data.order_count)} icon={Receipt} accent="bg-violet-500/10 text-violet-600" />
            <StatCard label={t("orders.analytics.avgOrderValue")} value={moneyObject(data.avg_order_value)} icon={Boxes} accent="bg-rose-500/10 text-rose-600" />
          </div>

          {data.items_with_unknown_cost > 0 && (
            <p className="text-sm text-amber-600">
              {t("orders.analytics.unknownCost", { count: data.items_with_unknown_cost })}
            </p>
          )}

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <RankTable
              title={t("orders.analytics.topCustomers")}
              cols={[t("orders.analytics.colCustomer"), t("orders.analytics.colRevenue")]}
              rows={data.top_customers.map((c) => [c.company_name ?? "—", moneyObject(c.revenue)])}
              empty={t("orders.analytics.empty")}
            />
            <RankTable
              title={t("orders.analytics.topProducts")}
              cols={[t("orders.analytics.colProduct"), t("orders.analytics.colQuantity"), t("orders.analytics.colRevenue")]}
              rows={data.top_products.map((p) => [p.name ?? "—", number(p.quantity), moneyObject(p.revenue)])}
              empty={t("orders.analytics.empty")}
            />
          </div>

          <RankTable
            title={t("orders.analytics.lowMargin")}
            cols={[t("orders.analytics.colOrder"), t("orders.analytics.colRevenue"), t("orders.analytics.colMargin")]}
            rows={data.low_margin_orders.map((o) => [o.order_number, moneyObject(o.revenue), `${o.margin_percent}%`])}
            empty={t("orders.analytics.empty")}
          />
        </>
      )}
    </div>
  );
}

function RankTable({
  title,
  cols,
  rows,
  empty,
}: {
  title: string;
  cols: string[];
  rows: React.ReactNode[][];
  empty: string;
}) {
  return (
    <Card>
      <CardContent className="pt-6">
        <h2 className="mb-3 text-sm font-semibold">{title}</h2>
        {rows.length === 0 ? (
          <p className="py-4 text-center text-sm text-muted-foreground">{empty}</p>
        ) : (
          <table className="w-full text-sm">
            <thead className="border-b border-border text-left text-muted-foreground">
              <tr>
                {cols.map((c, i) => (
                  <th key={i} className={i === 0 ? "py-2 pr-3 font-medium" : "py-2 pr-3 text-right font-medium"}>
                    {c}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((row, ri) => (
                <tr key={ri} className="border-b border-border last:border-0">
                  {row.map((cell, ci) => (
                    <td key={ci} className={ci === 0 ? "py-2 pr-3" : "py-2 pr-3 text-right tabular-nums"}>
                      {cell}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </CardContent>
    </Card>
  );
}
