"use client";

import * as React from "react";
import Link from "next/link";
import { ArrowLeft } from "lucide-react";

import { useInventorySpend } from "@/hooks/use-inventory";
import { addDays } from "@/lib/calendar";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { SpendProduct } from "@/lib/types";
import { Card, CardContent } from "@/components/ui/card";
import { DashboardBodySkeleton } from "@/components/skeletons";
import { ChartCard, DailyBarChart, Sparkline } from "@/components/dashboard/charts";
import {
  presetRange,
  SpendRangeSelector,
  type SpendPreset,
  type SpendRange,
} from "@/components/inventory/spend-range";
import { cn } from "@/lib/utils";

interface Group {
  group: string | null;
  subs: { subcategory: string | null; products: SpendProduct[] }[];
}

function groupProducts(products: SpendProduct[]): Group[] {
  const groups = new Map<string | null, Map<string | null, SpendProduct[]>>();
  for (const p of products) {
    const g = p.group ?? null;
    const s = p.subcategory ?? null;
    if (!groups.has(g)) groups.set(g, new Map());
    const subs = groups.get(g)!;
    if (!subs.has(s)) subs.set(s, []);
    subs.get(s)!.push(p);
  }
  return [...groups.entries()].map(([group, subs]) => ({
    group,
    subs: [...subs.entries()].map(([subcategory, ps]) => ({ subcategory, products: ps })),
  }));
}

export default function InventorySpendPage() {
  const { t } = useTranslation();
  const { number, moneyObject, date } = useFormatters();

  const [range, setRange] = React.useState<SpendRange>(() => presetRange("mtd"));
  const [activeKey, setActiveKey] = React.useState<SpendPreset | "custom">("mtd");
  const { data, isLoading } = useInventorySpend(range.from, range.to);

  function onRangeChange(next: SpendRange, key: SpendPreset | "custom") {
    setRange(next);
    setActiveKey(key);
  }

  const grouped = React.useMemo(() => groupProducts(data?.per_product ?? []), [data]);
  const dailyData = (data?.daily ?? []).map((d) => {
    const [, m, day] = d.date.split("-");
    return { label: `${day}.${m}`, value: d.units };
  });

  return (
    <div className="space-y-6">
      <Link
        href="/inventory"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("inventory.page.back")}
      </Link>

      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("inventory.spend.title")}</h1>
        <p className="text-sm text-muted-foreground">{t("inventory.spend.subtitle")}</p>
      </div>

      <SpendRangeSelector value={range} activeKey={activeKey} onChange={onRangeChange} />

      {isLoading || !data ? (
        <DashboardBodySkeleton />
      ) : (
        <>
          {/* Summary cards with prev-period comparison */}
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <DeltaCard
              label={t("inventory.spend.summary.unitsExited")}
              value={number(data.summary.units_exited)}
              cur={data.summary.units_exited}
              prev={data.previous.units_exited}
              prevText={number(data.previous.units_exited)}
            />
            <DeltaCard
              label={t("inventory.spend.summary.movements")}
              value={number(data.summary.movements)}
              cur={data.summary.movements}
              prev={data.previous.movements}
              prevText={number(data.previous.movements)}
            />
            <DeltaCard
              label={t("inventory.spend.summary.costValue")}
              value={moneyObject(data.summary.cost_value)}
              cur={data.summary.cost_value.minor}
              prev={data.previous.cost_value.minor}
              prevText={moneyObject(data.previous.cost_value)}
            />
            <DeltaCard
              label={t("inventory.spend.summary.revenue")}
              value={moneyObject(data.summary.revenue)}
              cur={data.summary.revenue.minor}
              prev={data.previous.revenue.minor}
              prevText={moneyObject(data.previous.revenue)}
            />
            <DeltaCard
              label={t("inventory.spend.summary.distinctSkus")}
              value={number(data.summary.distinct_skus)}
            />
          </div>

          {/* Daily exit volume */}
          <ChartCard
            title={t("inventory.spend.daily.title")}
            subtitle={t("inventory.spend.daily.total", { count: number(data.summary.units_exited) })}
          >
            <DailyBarChart data={dailyData} formatValue={(n) => number(n)} />
          </ChartCard>

          {/* Runout forecast */}
          <ChartCard title={t("inventory.spend.runout.title")} subtitle={t("inventory.spend.runout.subtitle")}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="border-b border-border text-left text-xs uppercase text-muted-foreground">
                  <tr>
                    <th className="py-2 pr-3 font-medium">{t("inventory.spend.cols.product")}</th>
                    <th className="py-2 pr-3 text-right font-medium">{t("inventory.spend.cols.stock")}</th>
                    <th className="py-2 pr-3 text-right font-medium">{t("inventory.spend.cols.perDay")}</th>
                    <th className="py-2 pr-3 text-right font-medium">{t("inventory.spend.cols.daysLeft")}</th>
                    <th className="py-2 text-right font-medium">{t("inventory.spend.cols.runout")}</th>
                  </tr>
                </thead>
                <tbody>
                  {grouped.map((g) => (
                    <GroupRows key={g.group ?? "__none__"} group={g} cols={5}>
                      {(p) => (
                        <tr key={p.id} className="border-b border-border/60 last:border-0">
                          <td className="py-1.5 pr-3">{productLabel(p, t("inventory.spend.cols.noVintage"))}</td>
                          <td className="py-1.5 pr-3 text-right tabular-nums">{number(p.on_hand)}</td>
                          <td className="py-1.5 pr-3 text-right tabular-nums">
                            {p.units_exited > 0 ? p.velocity_per_day : t("inventory.spend.noExit")}
                          </td>
                          <td className={cn("py-1.5 pr-3 text-right tabular-nums", urgency(p.days_left))}>
                            {p.days_left ?? "—"}
                          </td>
                          <td className="py-1.5 text-right tabular-nums text-muted-foreground">
                            {p.days_left != null ? date(addDays(new Date(), p.days_left)) : "—"}
                          </td>
                        </tr>
                      )}
                    </GroupRows>
                  ))}
                </tbody>
              </table>
            </div>
          </ChartCard>

          {/* Per-product exit */}
          <ChartCard
            title={t("inventory.spend.products.title")}
            subtitle={t("inventory.spend.products.subtitle")}
          >
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="border-b border-border text-left text-xs uppercase text-muted-foreground">
                  <tr>
                    <th className="py-2 pr-3 font-medium">{t("inventory.spend.cols.product")}</th>
                    <th className="py-2 pr-3 font-medium">{t("inventory.spend.cols.sku")}</th>
                    <th className="py-2 pr-3 text-right font-medium">{t("inventory.spend.cols.exited")}</th>
                    <th className="py-2 pr-3 text-right font-medium">{t("inventory.spend.cols.onHand")}</th>
                    <th className="py-2 pr-3 text-right font-medium">{t("inventory.spend.cols.perDay")}</th>
                    <th className="py-2 pr-3 text-right font-medium">{t("inventory.spend.cols.daysLeft")}</th>
                    <th className="py-2 pr-3 text-right font-medium">{t("inventory.spend.cols.cost")}</th>
                    <th className="py-2 pr-3 text-right font-medium">{t("inventory.spend.cols.revenue")}</th>
                    <th className="py-2 pr-3 text-right font-medium">{t("inventory.spend.cols.delta")}</th>
                    <th className="py-2 font-medium">{t("inventory.spend.cols.trend")}</th>
                  </tr>
                </thead>
                <tbody>
                  {grouped.map((g) => (
                    <GroupRows key={g.group ?? "__none__"} group={g} cols={10}>
                      {(p) => {
                        const delta = p.units_exited - p.prev_units_exited;
                        return (
                          <tr key={p.id} className="border-b border-border/60 last:border-0">
                            <td className="py-1.5 pr-3">{p.name}</td>
                            <td className="py-1.5 pr-3 text-muted-foreground">{p.sku}</td>
                            <td className="py-1.5 pr-3 text-right tabular-nums">
                              {p.units_exited > 0 ? number(p.units_exited) : "—"}
                            </td>
                            <td className="py-1.5 pr-3 text-right tabular-nums">{number(p.on_hand)}</td>
                            <td className="py-1.5 pr-3 text-right tabular-nums">
                              {p.units_exited > 0 ? p.velocity_per_day : "—"}
                            </td>
                            <td className={cn("py-1.5 pr-3 text-right tabular-nums", urgency(p.days_left))}>
                              {p.days_left ?? "—"}
                            </td>
                            <td className="py-1.5 pr-3 text-right tabular-nums">
                              {p.cost_of_exits ? moneyObject(p.cost_of_exits) : "—"}
                            </td>
                            <td className="py-1.5 pr-3 text-right tabular-nums">
                              {p.revenue ? moneyObject(p.revenue) : "—"}
                            </td>
                            <td className="py-1.5 pr-3 text-right tabular-nums">
                              {delta === 0 ? "—" : (
                                <span className={delta > 0 ? "text-emerald-600" : "text-destructive"}>
                                  {delta > 0 ? "+" : ""}
                                  {number(delta)}
                                </span>
                              )}
                            </td>
                            <td className="py-1.5">
                              <Sparkline data={p.daily} />
                            </td>
                          </tr>
                        );
                      }}
                    </GroupRows>
                  ))}
                </tbody>
              </table>
            </div>
          </ChartCard>
        </>
      )}
    </div>
  );
}

function productLabel(p: SpendProduct, noVintage: string): React.ReactNode {
  return (
    <span>
      {p.name}
      <span className="text-muted-foreground"> · {p.vintage ?? noVintage}</span>
    </span>
  );
}

function urgency(daysLeft: number | null): string {
  if (daysLeft == null) return "";
  if (daysLeft <= 7) return "font-medium text-destructive";
  if (daysLeft <= 30) return "text-amber-600";
  return "";
}

/** Renders a group header, subcategory subheaders, and the product rows under them. */
function GroupRows({
  group,
  cols,
  children,
}: {
  group: Group;
  cols: number;
  children: (product: SpendProduct) => React.ReactNode;
}) {
  const { t } = useTranslation();
  return (
    <>
      <tr className="bg-muted/40">
        <td colSpan={cols} className="px-1 py-1.5 text-xs font-semibold uppercase tracking-wide">
          {group.group ?? t("inventory.spend.ungrouped")}
        </td>
      </tr>
      {group.subs.map((sub) => (
        <React.Fragment key={sub.subcategory ?? "__none__"}>
          {sub.subcategory && (
            <tr>
              <td colSpan={cols} className="px-1 pt-1.5 text-xs font-medium text-muted-foreground">
                {sub.subcategory}
              </td>
            </tr>
          )}
          {sub.products.map((p) => children(p))}
        </React.Fragment>
      ))}
    </>
  );
}

function DeltaCard({
  label,
  value,
  cur,
  prev,
  prevText,
}: {
  label: string;
  value: React.ReactNode;
  cur?: number;
  prev?: number;
  prevText?: string;
}) {
  const { t } = useTranslation();
  const hasPrev = prev != null && cur != null;
  const noPrior = hasPrev && prev === 0;
  const deltaPct = hasPrev && prev !== 0 ? ((cur - prev) / prev) * 100 : null;

  return (
    <Card className="animate-fade-up border-border/60">
      <CardContent className="space-y-1 pt-5">
        <p className="text-xs uppercase tracking-wide text-muted-foreground">{label}</p>
        <p className="text-2xl font-semibold tabular-nums">{value}</p>
        {hasPrev && (
          <div className="text-xs text-muted-foreground">
            {noPrior ? (
              <span>{t("inventory.spend.noPrior")}</span>
            ) : deltaPct != null ? (
              <span className={deltaPct >= 0 ? "text-emerald-600" : "text-destructive"}>
                {deltaPct >= 0 ? "▲" : "▼"} {Math.abs(deltaPct).toFixed(0)}%
              </span>
            ) : null}
            <span className="block">{t("inventory.spend.prev", { value: prevText ?? "" })}</span>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
