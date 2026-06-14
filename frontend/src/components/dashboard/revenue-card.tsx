"use client";

import * as React from "react";
import { ArrowDown, ArrowUp } from "lucide-react";

import { cn } from "@/lib/utils";
import { useTranslation } from "@/i18n/context";
import { Card } from "@/components/ui/card";

/**
 * Revenue summary tile: a label, a prominent amount, and either a comparison row
 * (percentage delta + "vs <previous>") or a plain caption. Pass `comparisonValue`
 * to render the comparison; `deltaPct` of null shows "—" (no prior-period base).
 */
export function RevenueCard({
  label,
  value,
  comparisonValue,
  deltaPct,
  caption,
  delayMs = 0,
}: {
  label: string;
  value: string;
  comparisonValue?: string;
  deltaPct?: number | null;
  caption?: string;
  delayMs?: number;
}) {
  const { t } = useTranslation();
  const showComparison = comparisonValue !== undefined;
  const up = deltaPct != null && deltaPct >= 0;
  const down = deltaPct != null && deltaPct < 0;

  return (
    <Card
      style={{ animationDelay: `${delayMs}ms` }}
      className={cn(
        "animate-fade-up border-border/60",
        "transition-all duration-300 ease-out hover:-translate-y-0.5 hover:shadow-md",
      )}
    >
      <div className="p-5">
        <p className="text-sm text-muted-foreground">{label}</p>
        <p className="mt-2 text-2xl font-semibold tracking-tight tabular-nums">{value}</p>
        {showComparison ? (
          <div className="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
            <span
              className={cn(
                "inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 font-medium",
                up && "bg-success/10 text-success",
                down && "bg-destructive/10 text-destructive",
                deltaPct == null && "bg-muted text-muted-foreground",
              )}
            >
              {up && <ArrowUp className="size-3" />}
              {down && <ArrowDown className="size-3" />}
              {deltaPct != null ? `${deltaPct > 0 ? "+" : ""}${deltaPct.toFixed(1)}%` : "—"}
            </span>
            <span className="text-muted-foreground">
              {t("dashboard.summary.vs")} {comparisonValue}
            </span>
          </div>
        ) : caption ? (
          <p className="mt-1.5 text-xs text-muted-foreground">{caption}</p>
        ) : null}
      </div>
    </Card>
  );
}
