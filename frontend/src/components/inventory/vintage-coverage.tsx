"use client";

import Link from "next/link";
import { AlertTriangle, CheckCircle2, GitCompareArrows } from "lucide-react";

import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import { cn } from "@/lib/utils";
import type { VintageCoverage } from "@/lib/types";
import { Card, CardContent } from "@/components/ui/card";

/** Tone for a vintage's days-of-stock-left (red < 14d, amber < 30d, else neutral). */
function tone(days: number | null): { dot: string; text: string } {
  if (days === null) return { dot: "bg-muted-foreground/40", text: "text-muted-foreground" };
  if (days < 14) return { dot: "bg-red-500", text: "font-semibold text-red-600 dark:text-red-400" };
  if (days < 30) return { dot: "bg-amber-500", text: "font-medium text-amber-600 dark:text-amber-400" };
  return { dot: "bg-emerald-500", text: "font-medium text-foreground" };
}

/**
 * Vintage-transition coverage for the item detail Stock tab. Shows every in-stock
 * vintage of a wine with its own runway, the blended combined coverage, and a
 * FIFO transition read. Rendered only for multi-vintage wines (data is null
 * otherwise).
 */
export function VintageCoverageWidget({ data }: { data: VintageCoverage }) {
  const { t } = useTranslation();
  const { number } = useFormatters();
  const maxStock = Math.max(1, ...data.vintages.map((v) => v.stock_bottles));

  return (
    <Card className="border-border/60 bg-gradient-to-br from-background to-muted/30">
      <CardContent className="space-y-3 pt-6">
        <div className="space-y-0.5">
          <h2 className="flex items-center gap-2 text-sm font-semibold">
            <GitCompareArrows className="size-4 text-muted-foreground" />
            {t("inventory.vintage.title")}
          </h2>
          <p className="text-xs text-muted-foreground">
            {t("inventory.vintage.across", { wine: data.wine_name, n: data.vintages.length })}
            {data.combined_days_left != null && ` · ${t("inventory.vintage.combined", { days: Math.round(data.combined_days_left) })}`}
          </p>
        </div>

        <div className="space-y-2">
          {data.vintages.map((v) => {
            const tn = tone(v.days_left);
            return (
              <Link
                key={v.inventory_item_id}
                href={`/inventory/${v.inventory_item_id}`}
                className={cn(
                  "-mx-2 flex items-center gap-3 rounded-md px-2 py-1.5 transition-colors hover:bg-muted/40",
                  v.is_current && "bg-muted/40",
                )}
              >
                <span className={cn("size-2 shrink-0 rounded-full", tn.dot)} aria-hidden />
                <div className="w-20 shrink-0">
                  <span className="text-sm font-medium tabular-nums">{v.vintage || t("inventory.vintage.nv")}</span>
                  {v.is_current && <span className="ml-1 text-[10px] text-muted-foreground">({t("inventory.vintage.this")})</span>}
                </div>
                <div className="min-w-0 flex-1">
                  <div className="h-2 overflow-hidden rounded-full bg-muted">
                    <div className="h-full rounded-full bg-foreground/70" style={{ width: `${Math.round((v.stock_bottles / maxStock) * 100)}%` }} />
                  </div>
                </div>
                <div className="w-24 shrink-0 text-right">
                  <span className="text-sm tabular-nums">{number(v.stock_bottles)}</span>
                  <span className="ml-1 text-[10px] text-muted-foreground">{data.display_unit}</span>
                </div>
                <div className="w-14 shrink-0 text-right">
                  <span className={cn("text-xs tabular-nums", tn.text)}>{v.days_left === null ? "—" : `${Math.round(v.days_left)}d`}</span>
                </div>
              </Link>
            );
          })}
        </div>

        <div className="border-t border-border pt-3">
          {data.gap_risk ? (
            <div className="flex items-start gap-2 text-xs text-red-600 dark:text-red-400">
              <AlertTriangle className="mt-0.5 size-4 shrink-0" />
              <span>{t("inventory.vintage.gapRisk", { vintage: data.selling_vintage || t("inventory.vintage.current"), days: Math.round(data.selling_days_left ?? 0) })}</span>
            </div>
          ) : data.successor_vintage && data.selling_vintage && data.selling_days_left != null && data.selling_days_left < 60 ? (
            <div className="flex items-start gap-2 text-xs text-emerald-600 dark:text-emerald-400">
              <CheckCircle2 className="mt-0.5 size-4 shrink-0" />
              <span>{t("inventory.vintage.ready", { successor: data.successor_vintage, selling: data.selling_vintage, days: Math.round(data.selling_days_left) })}</span>
            </div>
          ) : (
            <p className="text-xs text-muted-foreground">{t("inventory.vintage.velocity", { days: data.window_days })}</p>
          )}
        </div>
      </CardContent>
    </Card>
  );
}
