"use client";

import * as React from "react";
import { Suspense } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { AlertTriangle, BarChart3, CheckCircle2, ChevronDown, Clock, FileText, Plus, X } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useInflows } from "@/hooks/use-inflows";
import { useCustomers } from "@/hooks/use-customers";
import { type DashboardPeriod, resolvePeriodWindow, toISODate } from "@/lib/dashboard-period";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { Inflow, InflowListSummary, InflowQuery, InflowStatus } from "@/lib/types";
import { INFLOW_STATUSES } from "@/lib/types";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { PeriodSelector } from "@/components/dashboard/period-selector";
import { InflowDetailPanel } from "@/components/inflows/inflow-detail-panel";

type Tab = "all" | InflowStatus;

const STATUS_VARIANT: Record<InflowStatus, "secondary" | "success"> = {
  PENDING: "secondary",
  RECEIVED: "success",
};

function InflowsView() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const router = useRouter();

  const searchParams = useSearchParams();
  const orderId = searchParams?.get("order_id") ?? "";

  const [tab, setTab] = React.useState<Tab>("all");
  const [customerId, setCustomerId] = React.useState("");
  const [period, setPeriod] = React.useState<DashboardPeriod>("all");
  const [customRange, setCustomRange] = React.useState<{ from?: string; to?: string }>({});
  const [search, setSearch] = React.useState("");
  const [debounced, setDebounced] = React.useState("");

  React.useEffect(() => {
    const id = setTimeout(() => setDebounced(search), 300);
    return () => clearTimeout(id);
  }, [search]);

  const window = resolvePeriodWindow(period, customRange.from, customRange.to);
  const range = {
    from: window.since ? toISODate(window.since) : undefined,
    to: window.until ? toISODate(window.until) : undefined,
  };

  const query: InflowQuery = {
    ...(debounced ? { search: debounced } : {}),
    ...(customerId ? { customer_id: customerId } : {}),
    ...(orderId ? { order_id: orderId } : {}),
    ...(range.from ? { date_from: range.from } : {}),
    ...(range.to ? { date_to: range.to } : {}),
    ...(tab === "all" ? {} : { status: tab }),
  };

  const { data, isLoading, isError, error } = useInflows(query);
  const customersQ = useCustomers();

  const inflows = data?.data ?? [];
  const customers = customersQ.data?.data ?? [];
  const canManage = can("finance.manage");

  const customerName = (id: string | null) =>
    id ? customers.find((c) => c.id === id)?.company_name ?? null : null;

  const tabs = [
    { value: "all", label: t("inflows.tabs.all") },
    ...INFLOW_STATUSES.map((s) => ({ value: s, label: t(`inflows.status.${s}`) })),
  ];

  return (
    <div className="space-y-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold tracking-tight">{t("inflows.title")}</h1>
          <p className="text-sm text-muted-foreground">
            {data?.meta
              ? t("inflows.subtitleCount", { count: data.meta.total })
              : t("inflows.subtitleDefault")}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={() => router.push("/inflows/analytics")} className="shrink-0">
            <BarChart3 className="size-4" />
            {t("inflows.analytics.trigger")}
          </Button>
          {canManage && (
            <Button onClick={() => router.push("/inflows/new")} className="shrink-0">
              <Plus className="size-4" />
              {t("inflows.add")}
            </Button>
          )}
        </div>
      </header>

      <Tabs tabs={tabs} value={tab} onChange={(v) => setTab(v as Tab)} />

      {/* Timeline filter — same chip + custom-range selector as the dashboard. */}
      <PeriodSelector
        period={period}
        customFrom={customRange.from}
        customTo={customRange.to}
        onChange={(next, from, to) => {
          setPeriod(next);
          setCustomRange({ from, to });
        }}
      />

      {!isLoading && !isError && data?.meta?.summary && inflows.length > 0 && (
        <InflowSummaryBar summary={data.meta.summary} />
      )}

      <div className="flex flex-wrap items-center gap-2">
        <Select
          aria-label={t("inflows.allCustomers")}
          value={customerId}
          onChange={(e) => setCustomerId(e.target.value)}
          className="sm:w-56"
        >
          <option value="">{t("inflows.allCustomers")}</option>
          {customers.map((c) => (
            <option key={c.id} value={c.id}>
              {c.company_name}
            </option>
          ))}
        </Select>

        <Input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder={t("inflows.search")}
          className="w-full sm:w-auto sm:max-w-xs"
        />
      </div>

      {orderId && (
        <div className="flex items-center justify-between gap-3 rounded-md border border-border bg-muted/40 px-3 py-2 text-sm">
          <span>{t("inflows.filteredByOrder", { order: inflows[0]?.order_number ?? orderId })}</span>
          <Link href="/inflows" className="inline-flex items-center gap-1 text-muted-foreground hover:text-foreground">
            <X className="size-3.5" />
            {t("inflows.clearFilter")}
          </Link>
        </div>
      )}

      {isLoading && (
        <div className="flex items-center justify-center py-16">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      )}

      {isError && (
        <Card>
          <CardContent className="py-8 text-center text-sm text-destructive">
            {error instanceof ApiError && error.status === 403
              ? t("inflows.errorForbidden")
              : t("inflows.errorGeneric")}
          </CardContent>
        </Card>
      )}

      {!isLoading && !isError && inflows.length === 0 && (
        <Card>
          <CardContent className="py-12 text-center text-sm text-muted-foreground">
            {t("inflows.empty")}
          </CardContent>
        </Card>
      )}

      {!isLoading && !isError && inflows.length > 0 && (
        <div className="space-y-2">
          {inflows.map((inflow) => (
            <InflowCard key={inflow.id} inflow={inflow} customerName={customerName(inflow.customer_id)} />
          ))}
        </div>
      )}
    </div>
  );
}

export default function InflowsPage() {
  return (
    <Suspense fallback={null}>
      <InflowsView />
    </Suspense>
  );
}

/** Invoiced / Collected / Pending totals over the full filtered set. */
function InflowSummaryBar({ summary }: { summary: InflowListSummary }) {
  const { t } = useTranslation();
  const { moneyObject } = useFormatters();

  return (
    <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
      <div className="rounded-lg border border-border/60 bg-muted/30 px-3 py-2">
        <div className="mb-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
          <FileText className="size-3.5" />
          {t("inflows.summary.invoiced")}
        </div>
        <div className="text-sm font-semibold tabular-nums">{moneyObject(summary.invoiced.total)}</div>
        <div className="text-[10px] text-muted-foreground">
          {t("inflows.summary.nInvoices", { count: summary.invoiced.count })}
        </div>
      </div>
      <div className="rounded-lg border border-border/60 bg-muted/30 px-3 py-2">
        <div className="mb-0.5 flex items-center gap-1.5 text-xs text-emerald-600">
          <CheckCircle2 className="size-3.5" />
          {t("inflows.summary.collected")}
        </div>
        <div className="text-sm font-semibold tabular-nums text-emerald-600">{moneyObject(summary.collected.total)}</div>
        <div className="text-[10px] text-muted-foreground">
          {t("inflows.summary.nPaid", { count: summary.collected.count })}
        </div>
      </div>
      <div className="rounded-lg border border-border/60 bg-muted/30 px-3 py-2">
        <div className="mb-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
          <Clock className="size-3.5" />
          {t("inflows.summary.pending")}
        </div>
        <div className="text-sm font-semibold tabular-nums">{moneyObject(summary.pending.total)}</div>
        <div className="text-[10px] text-muted-foreground">
          {t("inflows.summary.nUnpaid", { count: summary.pending.count })}
          {summary.pending.overdue > 0 && (
            <span className="font-medium text-destructive">
              {" · "}
              {t("inflows.summary.nOverdue", { count: summary.pending.overdue })}
            </span>
          )}
        </div>
      </div>
    </div>
  );
}

/** Due-date state for an unpaid inflow: overdue, due within 3 days, or neither. */
function dueState(inflow: Inflow): "overdue" | "due-soon" | null {
  if (!inflow.due_date || inflow.status === "RECEIVED") return null;
  const due = new Date(inflow.due_date);
  due.setHours(0, 0, 0, 0);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  if (due < today) return "overdue";
  const soon = new Date(today);
  soon.setDate(soon.getDate() + 3);
  return due <= soon ? "due-soon" : null;
}

function InflowCard({ inflow, customerName }: { inflow: Inflow; customerName: string | null }) {
  const { t } = useTranslation();
  const { moneyObject, date } = useFormatters();
  const [open, setOpen] = React.useState(false);

  const received = inflow.status === "RECEIVED";
  const due = dueState(inflow);

  return (
    <Card className="overflow-hidden">
      <button
        type="button"
        onClick={() => setOpen((prev) => !prev)}
        aria-expanded={open}
        className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/40"
      >
        <div className="min-w-0">
          <p className="flex items-center gap-2 truncate font-medium">
            {inflow.category ?? inflow.reference ?? t("inflows.noSource")}
            {inflow.is_credit_note && <Badge variant="outline">{t("inflows.creditNote")}</Badge>}
          </p>
          <p className="truncate text-xs text-muted-foreground">
            {date(inflow.date)}
            {customerName ? ` · ${customerName}` : ""}
            {inflow.order_number ? ` · ${inflow.order_number}` : ""}
            {inflow.due_date && (
              <span className={due === "overdue" ? "text-destructive" : due === "due-soon" ? "text-amber-600" : ""}>
                {" · "}
                {t("inflows.summary.due", { date: date(inflow.due_date) })}
              </span>
            )}
          </p>
        </div>
        <div className="flex shrink-0 items-center gap-2 text-sm">
          {due === "overdue" && (
            <span className="hidden items-center gap-0.5 text-xs font-medium text-destructive sm:flex">
              <AlertTriangle className="size-3" />
              {t("inflows.summary.overdue")}
            </span>
          )}
          {due === "due-soon" && (
            <span className="hidden items-center gap-0.5 text-xs font-medium text-amber-600 sm:flex">
              <Clock className="size-3" />
              {t("inflows.summary.dueSoon")}
            </span>
          )}
          <span
            className={cn(
              "font-bold tabular-nums",
              inflow.is_credit_note
                ? "text-muted-foreground line-through"
                : received
                  ? "text-emerald-600"
                  : due === "overdue"
                    ? "text-destructive"
                    : "",
            )}
          >
            {moneyObject(inflow.amount)}
          </span>
          <Badge variant={STATUS_VARIANT[inflow.status]}>{t(`inflows.status.${inflow.status}`)}</Badge>
          <ChevronDown
            className={`size-4 text-muted-foreground transition-transform duration-300 ${open ? "rotate-180" : ""}`}
          />
        </div>
      </button>

      <div
        className={`grid transition-all duration-300 ease-out ${
          open ? "grid-rows-[1fr] opacity-100" : "grid-rows-[0fr] opacity-0"
        }`}
      >
        <div className="overflow-hidden">
          <div className="border-t border-border px-4 py-4">
            {open && <InflowDetailPanel inflow={inflow} onDeleted={() => setOpen(false)} />}
          </div>
        </div>
      </div>
    </Card>
  );
}
