"use client";

import * as React from "react";

import { cn } from "@/lib/utils";
import { Card } from "@/components/ui/card";

/**
 * Compact metric card: label + prominent value, with a small tinted icon in the
 * top-right. `accent` is a className for the icon tile (e.g.
 * "bg-rose-500/10 text-rose-500").
 */
export function StatCard({
  label,
  value,
  icon: Icon,
  accent,
  delayMs = 0,
}: {
  label: string;
  value: React.ReactNode;
  icon: React.ComponentType<{ className?: string }>;
  accent: string;
  delayMs?: number;
}) {
  return (
    <Card
      style={{ animationDelay: `${delayMs}ms` }}
      className={cn(
        "animate-fade-up border-border/60",
        "transition-all duration-300 ease-out hover:-translate-y-0.5 hover:shadow-md",
      )}
    >
      <div className="p-5">
        <div className="flex items-start justify-between gap-3">
          <p className="text-sm text-muted-foreground">{label}</p>
          <span className={cn("flex size-8 shrink-0 items-center justify-center rounded-lg", accent)}>
            <Icon className="size-4" />
          </span>
        </div>
        <p className="mt-3 text-2xl font-semibold tracking-tight tabular-nums">{value}</p>
      </div>
    </Card>
  );
}