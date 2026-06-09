"use client";

import * as React from "react";
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  Cell,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";

import { cn } from "@/lib/utils";
import { Card, CardContent } from "@/components/ui/card";

const AXIS_TICK = { fontSize: 11, fill: "var(--color-muted-foreground)" } as const;
const ANIM = 900;

/** Slick card wrapper with an animated entrance and a title row. */
export function ChartCard({
  title,
  subtitle,
  action,
  children,
  className,
  delayMs = 0,
}: {
  title: string;
  subtitle?: string;
  action?: React.ReactNode;
  children: React.ReactNode;
  className?: string;
  delayMs?: number;
}) {
  return (
    <Card
      style={{ animationDelay: `${delayMs}ms` }}
      className={cn("animate-fade-up border-border/60", className)}
    >
      <CardContent className="space-y-3 pt-6">
        <div className="flex items-start justify-between gap-2">
          <div className="space-y-0.5">
            <h3 className="text-sm font-semibold">{title}</h3>
            {subtitle && <p className="text-xs text-muted-foreground">{subtitle}</p>}
          </div>
          {action}
        </div>
        {children}
      </CardContent>
    </Card>
  );
}

function TooltipBox({
  active,
  payload,
  label,
  formatValue,
}: {
  active?: boolean;
  payload?: { value: number }[];
  label?: string;
  formatValue: (n: number) => string;
}) {
  if (!active || !payload?.length) return null;
  return (
    <div className="rounded-lg border border-border bg-popover px-3 py-2 text-xs shadow-md">
      {label && <p className="mb-0.5 font-medium">{label}</p>}
      <p className="tabular-nums text-muted-foreground">{formatValue(payload[0].value)}</p>
    </div>
  );
}

export function OrdersChart({
  data,
  formatValue,
}: {
  data: { label: string; value: number }[];
  formatValue: (n: number) => string;
}) {
  return (
    <div className="h-[240px] w-full">
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart data={data} margin={{ top: 6, right: 6, left: -18, bottom: 0 }}>
          <defs>
            <linearGradient id="ordersFill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="var(--color-primary)" stopOpacity={0.35} />
              <stop offset="100%" stopColor="var(--color-primary)" stopOpacity={0} />
            </linearGradient>
          </defs>
          <XAxis dataKey="label" tick={AXIS_TICK} axisLine={false} tickLine={false} minTickGap={24} />
          <YAxis tick={AXIS_TICK} axisLine={false} tickLine={false} width={32} allowDecimals={false} />
          <Tooltip content={<TooltipBox formatValue={formatValue} />} cursor={{ stroke: "var(--color-border)" }} />
          <Area
            type="monotone"
            dataKey="value"
            stroke="var(--color-primary)"
            strokeWidth={2}
            fill="url(#ordersFill)"
            animationDuration={ANIM}
            dot={false}
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  );
}

export function RevenueChart({
  data,
  formatValue,
  formatAxis,
}: {
  data: { label: string; value: number }[];
  formatValue: (n: number) => string;
  formatAxis: (n: number) => string;
}) {
  return (
    <div className="h-[240px] w-full">
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart data={data} margin={{ top: 6, right: 6, left: -6, bottom: 0 }}>
          <defs>
            <linearGradient id="revenueFill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="#10b981" stopOpacity={0.3} />
              <stop offset="100%" stopColor="#10b981" stopOpacity={0} />
            </linearGradient>
          </defs>
          <XAxis dataKey="label" tick={AXIS_TICK} axisLine={false} tickLine={false} minTickGap={32} />
          <YAxis
            tick={AXIS_TICK}
            axisLine={false}
            tickLine={false}
            width={48}
            tickFormatter={formatAxis}
          />
          <Tooltip content={<TooltipBox formatValue={formatValue} />} cursor={{ stroke: "var(--color-border)" }} />
          <Area
            type="monotone"
            dataKey="value"
            stroke="#10b981"
            strokeWidth={2}
            fill="url(#revenueFill)"
            animationDuration={ANIM}
            dot={false}
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  );
}

export function TopProductsChart({
  data,
  formatValue,
}: {
  data: { name: string; value: number }[];
  formatValue: (n: number) => string;
}) {
  return (
    <div className="h-[240px] w-full">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart layout="vertical" data={data} margin={{ top: 0, right: 8, left: 0, bottom: 0 }}>
          <XAxis type="number" tick={AXIS_TICK} axisLine={false} tickLine={false} allowDecimals={false} />
          <YAxis
            type="category"
            dataKey="name"
            tick={AXIS_TICK}
            axisLine={false}
            tickLine={false}
            width={110}
            tickFormatter={(v: string) => (v.length > 16 ? `${v.slice(0, 15)}…` : v)}
          />
          <Tooltip content={<TooltipBox formatValue={formatValue} />} cursor={{ fill: "var(--color-muted)" }} />
          <Bar dataKey="value" fill="var(--color-primary)" radius={[0, 6, 6, 0]} animationDuration={ANIM} />
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}

export function StockWatchChart({
  data,
}: {
  data: { name: string; stock: number; min: number }[];
}) {
  return (
    <div className="h-[240px] w-full">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart layout="vertical" data={data} margin={{ top: 0, right: 8, left: 0, bottom: 0 }}>
          <XAxis type="number" tick={AXIS_TICK} axisLine={false} tickLine={false} allowDecimals={false} />
          <YAxis
            type="category"
            dataKey="name"
            tick={AXIS_TICK}
            axisLine={false}
            tickLine={false}
            width={110}
            tickFormatter={(v: string) => (v.length > 16 ? `${v.slice(0, 15)}…` : v)}
          />
          <Tooltip content={<TooltipBox formatValue={(n) => String(n)} />} cursor={{ fill: "var(--color-muted)" }} />
          <Bar dataKey="stock" radius={[0, 6, 6, 0]} animationDuration={ANIM}>
            {data.map((d) => (
              <Cell key={d.name} fill={d.stock < d.min ? "var(--color-destructive)" : "var(--color-primary)"} />
            ))}
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}

export function DonutChart({
  data,
  centerValue,
  centerLabel,
}: {
  data: { key: string; value: number; color: string }[];
  centerValue: React.ReactNode;
  centerLabel: string;
}) {
  return (
    <div className="relative h-[200px] w-full">
      <ResponsiveContainer width="100%" height="100%">
        <PieChart>
          <Pie
            data={data}
            dataKey="value"
            nameKey="key"
            innerRadius={62}
            outerRadius={88}
            paddingAngle={2}
            stroke="none"
            animationDuration={ANIM}
          >
            {data.map((d) => (
              <Cell key={d.key} fill={d.color} />
            ))}
          </Pie>
        </PieChart>
      </ResponsiveContainer>
      <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
        <span className="text-xl font-semibold tabular-nums">{centerValue}</span>
        <span className="text-xs text-muted-foreground">{centerLabel}</span>
      </div>
    </div>
  );
}

/** Horizontal bars for low-stock items, coloured by severity (below vs approaching). */
export function LowStockChart({
  below,
  approaching,
}: {
  below: { name: string; stock: string }[];
  approaching: { name: string; stock: string }[];
}) {
  const data = [
    ...below.map((d) => ({ name: d.name, stock: Number(d.stock), kind: "below" as const })),
    ...approaching.map((d) => ({ name: d.name, stock: Number(d.stock), kind: "approaching" as const })),
  ];

  return (
    <div className="h-[220px] w-full">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart layout="vertical" data={data} margin={{ top: 0, right: 8, left: 0, bottom: 0 }}>
          <XAxis type="number" tick={AXIS_TICK} axisLine={false} tickLine={false} allowDecimals={false} />
          <YAxis
            type="category"
            dataKey="name"
            tick={AXIS_TICK}
            axisLine={false}
            tickLine={false}
            width={110}
            tickFormatter={(v: string) => (v.length > 16 ? `${v.slice(0, 15)}…` : v)}
          />
          <Tooltip content={<TooltipBox formatValue={(n) => String(n)} />} cursor={{ fill: "var(--color-muted)" }} />
          <Bar dataKey="stock" radius={[0, 6, 6, 0]} animationDuration={ANIM}>
            {data.map((d, i) => (
              <Cell key={`${d.name}-${i}`} fill={d.kind === "below" ? "var(--color-destructive)" : "#d6a417"} />
            ))}
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}