"use client";

import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { Order } from "@/lib/types";
import { cn } from "@/lib/utils";
import { Card, CardContent } from "@/components/ui/card";

/**
 * Order-level P&L for the detail sidebar. Renders nothing unless the API returned
 * a `profitability` block (i.e. the viewer may see financials and the order isn't
 * a consignment). When any line lacks a cost it shows a "set costs for …" hint
 * instead of the numbers, so the margin is never computed from partial data.
 */
export function OrderProfitabilityCard({ order }: { order: Order }) {
  const { t } = useTranslation();
  const { moneyObject } = useFormatters();
  const p = order.profitability;

  if (!p) return null;

  return (
    <Card>
      <CardContent className="space-y-4 pt-6">
        <h2 className="text-sm font-semibold">{t("orders.profitability.title")}</h2>

        {!p.complete ? (
          <div className="space-y-1 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2.5 text-sm text-amber-700 dark:text-amber-300">
            <p className="font-medium">{t("orders.profitability.incomplete")}</p>
            <p className="text-xs">
              {t("orders.profitability.setCosts", { items: p.missing_cost_items.join(", ") })}
            </p>
          </div>
        ) : (
          <dl className="space-y-2 text-sm">
            <Row label={t("orders.profitability.revenue")}>{moneyObject(p.revenue)}</Row>
            <Row label={t("orders.profitability.cogs")}>{moneyObject(p.cogs)}</Row>
            {p.logistics && (
              <Row label={t("orders.profitability.logistics")}>{`-${moneyObject(p.logistics)}`}</Row>
            )}
            <div className="flex items-center justify-between border-t border-border pt-2">
              <dt className="font-medium">{t("orders.profitability.grossProfit")}</dt>
              <dd className={cn("font-semibold", profitTone(p.gross_profit.minor))}>
                {moneyObject(p.gross_profit)}
              </dd>
            </div>
            <Row label={t("orders.profitability.margin")}>
              <span className={cn("font-semibold", profitTone(Number(p.margin_percent)))}>
                {p.margin_percent}%
              </span>
            </Row>
          </dl>
        )}
      </CardContent>
    </Card>
  );
}

function profitTone(value: number): string {
  return value >= 0 ? "text-success" : "text-destructive";
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="font-medium">{children}</dd>
    </div>
  );
}
