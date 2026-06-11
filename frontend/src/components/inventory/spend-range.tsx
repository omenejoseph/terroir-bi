"use client";

import * as React from "react";

import { addDays, addMonths, endOfMonth, startOfMonth, startOfQuarter } from "@/lib/calendar";
import { useTranslation } from "@/i18n/context";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

export interface SpendRange {
  from: string;
  to: string;
}

const PRESETS = [
  "today",
  "yesterday",
  "mtd",
  "qtd",
  "ytd",
  "thisMonth",
  "lastMonth",
  "lastYear",
  "all",
] as const;

export type SpendPreset = (typeof PRESETS)[number];

function iso(d: Date): string {
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${d.getFullYear()}-${m}-${day}`;
}

export function presetRange(key: SpendPreset, now = new Date()): SpendRange {
  switch (key) {
    case "today":
      return { from: iso(now), to: iso(now) };
    case "yesterday": {
      const y = addDays(now, -1);
      return { from: iso(y), to: iso(y) };
    }
    case "mtd":
      return { from: iso(startOfMonth(now)), to: iso(now) };
    case "qtd":
      return { from: iso(startOfQuarter(now)), to: iso(now) };
    case "ytd":
      return { from: iso(new Date(now.getFullYear(), 0, 1)), to: iso(now) };
    case "thisMonth":
      return { from: iso(startOfMonth(now)), to: iso(endOfMonth(now)) };
    case "lastMonth": {
      const lm = addMonths(now, -1);
      return { from: iso(startOfMonth(lm)), to: iso(endOfMonth(lm)) };
    }
    case "lastYear":
      return {
        from: iso(new Date(now.getFullYear() - 1, 0, 1)),
        to: iso(new Date(now.getFullYear() - 1, 11, 31)),
      };
    case "all":
      return { from: "2000-01-01", to: iso(now) };
  }
}

export function SpendRangeSelector({
  value,
  activeKey,
  onChange,
}: {
  value: SpendRange;
  activeKey: SpendPreset | "custom";
  onChange: (range: SpendRange, key: SpendPreset | "custom") => void;
}) {
  const { t } = useTranslation();
  const chip = (active: boolean) =>
    cn(
      "rounded-full border px-3 py-1 text-xs font-medium transition-colors",
      active
        ? "border-primary bg-primary/10 text-primary"
        : "border-border text-muted-foreground hover:text-foreground",
    );

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap gap-1.5">
        {PRESETS.map((p) => (
          <button
            key={p}
            type="button"
            aria-pressed={activeKey === p}
            onClick={() => onChange(presetRange(p), p)}
            className={chip(activeKey === p)}
          >
            {t(`inventory.spend.range.${p}`)}
          </button>
        ))}
        <button
          type="button"
          aria-pressed={activeKey === "custom"}
          onClick={() => onChange(value, "custom")}
          className={chip(activeKey === "custom")}
        >
          {t("inventory.spend.range.custom")}
        </button>
      </div>

      {activeKey === "custom" && (
        <div className="flex items-center gap-2">
          <Input
            type="date"
            aria-label={t("inventory.spend.range.from")}
            value={value.from}
            onChange={(e) => onChange({ ...value, from: e.target.value }, "custom")}
            className="w-auto"
          />
          <span className="text-muted-foreground">→</span>
          <Input
            type="date"
            aria-label={t("inventory.spend.range.to")}
            value={value.to}
            onChange={(e) => onChange({ ...value, to: e.target.value }, "custom")}
            className="w-auto"
          />
        </div>
      )}
    </div>
  );
}
