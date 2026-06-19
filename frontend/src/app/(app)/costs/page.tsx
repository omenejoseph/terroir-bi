"use client";

import * as React from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { BarChart3, ChevronDown, Plus } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import {
  useCostAnalytics,
  useCostCategories,
  useCostGroupCounts,
  useCosts,
} from "@/hooks/use-costs";
import { useSuppliers } from "@/hooks/use-suppliers";
import { type DashboardPeriod, resolvePeriodWindow, toISODate } from "@/lib/dashboard-period";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { Cost, CostGroup, CostQuery, CostStatus } from "@/lib/types";
import { COST_STATUSES } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { cn } from "@/lib/utils";
import { MetaField } from "@/components/ui/meta-field";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { CostDetailPanel } from "@/components/costs/cost-detail-panel";
import { PeriodSelector } from "@/components/dashboard/period-selector";

type Tab = "all" | CostGroup;

const STATUS_VARIANT: Record<CostStatus, "default" | "secondary" | "success"> = {
  PENDING: "secondary",
  APPROVED: "default",
  PAID: "success",
};

export default function CostsPage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const router = useRouter();
  const { moneyObject } = useFormatters();

  const [tab, setTab] = React.useState<Tab>("all");
  const [category, setCategory] = React.useState("");
  const [status, setStatus] = React.useState<CostStatus | "">("");
  const [supplierId, setSupplierId] = React.useState("");
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

  // Filters shared by the list + the tab counts (counts omit the tab group itself).
  const filters: CostQuery = {
    ...(debounced ? { search: debounced } : {}),
    ...(category ? { category } : {}),
    ...(status ? { status } : {}),
    ...(supplierId ? { supplier_id: supplierId } : {}),
    ...(range.from ? { date_from: range.from } : {}),
    ...(range.to ? { date_to: range.to } : {}),
  };
  const query: CostQuery = { ...filters, ...(tab === "all" ? {} : { group: tab }) };

  const { data, isLoading, isError, error } = useCosts(query);
  const countsQ = useCostGroupCounts(filters);
  const counts = countsQ.data;
  const analyticsQ = useCostAnalytics();
  const categoriesQ = useCostCategories();
  const suppliersQ = useSuppliers({ per_page: 200 });

  const costs = data?.data ?? [];
  const canManage = can("finance.manage");
  const canViewSuppliers = can("suppliers.view");

  const tabs = (["all", "invoices", "payments", "others"] as const).map((g) => ({
    value: g,
    label: `${t(`costs.groups.${g}`)} (${counts?.[g] ?? 0})`,
  }));

  return (
    <div className="space-y-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold tracking-tight">{t("costs.title")}</h1>
          <p className="text-sm text-muted-foreground">{t("costs.subtitleDefault")}</p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={() => router.push("/costs/analytics")} className="shrink-0">
            <BarChart3 className="size-4" />
            {t("costs.analytics.trigger")}
          </Button>
          {canManage && (
            <Button onClick={() => router.push("/costs/new")} className="shrink-0">
              <Plus className="size-4" />
              {t("costs.add")}
            </Button>
          )}
        </div>
      </header>

      {/* Summary strip — invoiced / paid / unpaid split (mirrors the prototype). */}
      {analyticsQ.data && (
        <div className="grid gap-4 sm:grid-cols-3">
          <SummaryCard
            label={t("costs.summary.invoiced")}
            value={moneyObject(analyticsQ.data.invoiced.total)}
            sub={t("costs.summary.invoicedSub", {
              count: analyticsQ.data.invoiced.count,
              vat: moneyObject(analyticsQ.data.invoiced.vat),
            })}
          />
          <SummaryCard
            label={t("costs.summary.paid")}
            value={moneyObject(analyticsQ.data.paid.total)}
            valueClass="text-emerald-700 dark:text-emerald-400"
            sub={t("costs.summary.paidSub", { count: analyticsQ.data.paid.count })}
          />
          <SummaryCard
            label={t("costs.summary.unpaid")}
            value={moneyObject(analyticsQ.data.unpaid_invoices.total)}
            valueClass={analyticsQ.data.unpaid_invoices.total.minor > 0 ? "text-amber-600 dark:text-amber-400" : undefined}
            sub={t("costs.summary.unpaidSub", {
              count: analyticsQ.data.unpaid_invoices.count,
              overdue: analyticsQ.data.unpaid_invoices.overdue,
            })}
          />
        </div>
      )}

      <Tabs tabs={tabs} value={tab} onChange={(v) => setTab(v as Tab)} />

      <div className="flex flex-wrap items-center gap-2">
        <Select
          aria-label={t("costs.allCategories")}
          value={category}
          onChange={(e) => setCategory(e.target.value)}
          className="sm:w-44"
        >
          <option value="">{t("costs.allCategories")}</option>
          {(categoriesQ.data ?? []).map((c) => (
            <option key={c} value={c}>
              {c}
            </option>
          ))}
        </Select>

        <Select
          aria-label={t("costs.allStatuses")}
          value={status}
          onChange={(e) => setStatus(e.target.value as CostStatus | "")}
          className="sm:w-40"
        >
          <option value="">{t("costs.allStatuses")}</option>
          {COST_STATUSES.map((s) => (
            <option key={s} value={s}>
              {t(`costs.status.${s}`)}
            </option>
          ))}
        </Select>

        {canViewSuppliers && (
          <Select
            aria-label={t("costs.allSuppliers")}
            value={supplierId}
            onChange={(e) => setSupplierId(e.target.value)}
            className="sm:w-44"
          >
            <option value="">{t("costs.allSuppliers")}</option>
            {(suppliersQ.data?.data ?? []).map((s) => (
              <option key={s.id} value={s.id}>
                {s.company_name}
              </option>
            ))}
          </Select>
        )}

        <Input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder={t("costs.search")}
          className="w-full sm:w-auto sm:max-w-xs"
        />
      </div>

      {/* Period filter — same chip + custom-range selector as the dashboard. */}
      <PeriodSelector
        period={period}
        customFrom={customRange.from}
        customTo={customRange.to}
        onChange={(next, from, to) => {
          setPeriod(next);
          setCustomRange({ from, to });
        }}
      />

      {isLoading && (
        <div className="flex items-center justify-center py-16">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      )}

      {isError && (
        <Card>
          <CardContent className="py-8 text-center text-sm text-destructive">
            {error instanceof ApiError && error.status === 403
              ? t("costs.errorForbidden")
              : t("costs.errorGeneric")}
          </CardContent>
        </Card>
      )}

      {!isLoading && !isError && costs.length === 0 && (
        <Card>
          <CardContent className="py-12 text-center text-sm text-muted-foreground">
            {t("costs.empty")}
          </CardContent>
        </Card>
      )}

      {!isLoading && !isError && costs.length > 0 && (
        <div className="space-y-2">
          {costs.map((cost) => (
            <CostCard key={cost.id} cost={cost} />
          ))}
        </div>
      )}

    </div>
  );
}

/** A cost row that expands into its detail panel (view / edit / status / delete). */
function CostCard({ cost }: { cost: Cost }) {
  const { t } = useTranslation();
  const { moneyObject, date } = useFormatters();
  const [open, setOpen] = React.useState(false);
  const dash = "—";
  const overdue =
    cost.status !== "PAID" && cost.due_date != null && new Date(cost.due_date) < new Date(new Date().toDateString());

  return (
    <Card className="overflow-hidden">
      <div
        role="button"
        tabIndex={0}
        onClick={() => setOpen((prev) => !prev)}
        onKeyDown={(e) => {
          if (e.key === "Enter" || e.key === " ") {
            e.preventDefault();
            setOpen((prev) => !prev);
          }
        }}
        aria-expanded={open}
        className="flex w-full cursor-pointer items-center justify-between gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/40"
      >
        <div className="min-w-0 flex-1">
          {/* Labelled columns mirroring the prototype: Type · Date · Description · Supplier · Amount · Due. */}
          <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
            <MetaField label={t("costs.field.type")}>{cost.category}</MetaField>
            <MetaField label={t("costs.field.date")}>{date(cost.date)}</MetaField>
            <MetaField label={t("costs.field.description")}>{cost.description || dash}</MetaField>
            <MetaField label={t("costs.field.supplier")}>
              {cost.supplier ? (
                <Link
                  href={`/suppliers/${cost.supplier.id}`}
                  onClick={(e) => e.stopPropagation()}
                  className="hover:underline"
                >
                  {cost.supplier.company_name}
                </Link>
              ) : (
                dash
              )}
            </MetaField>
            <MetaField label={t("costs.field.amount")}>{moneyObject(cost.total_amount)}</MetaField>
            <MetaField label={t("costs.field.due")}>
              <span className={overdue ? "text-red-600 dark:text-red-400" : undefined}>
                {cost.due_date ? date(cost.due_date) : dash}
              </span>
            </MetaField>
          </div>
        </div>
        <div className="flex shrink-0 items-center gap-2 text-sm">
          <Badge variant={STATUS_VARIANT[cost.status]}>{t(`costs.status.${cost.status}`)}</Badge>
          <ChevronDown
            className={`size-4 text-muted-foreground transition-transform duration-300 ${open ? "rotate-180" : ""}`}
          />
        </div>
      </div>

      <div
        className={`grid transition-all duration-300 ease-out ${
          open ? "grid-rows-[1fr] opacity-100" : "grid-rows-[0fr] opacity-0"
        }`}
      >
        <div className="overflow-hidden">
          <div className="border-t border-border px-4 py-4">
            {open && <CostDetailPanel cost={cost} onDeleted={() => setOpen(false)} />}
          </div>
        </div>
      </div>
    </Card>
  );
}

/** A summary tile for the invoiced / paid / unpaid strip. */
function SummaryCard({ label, value, sub, valueClass }: { label: string; value: string; sub: string; valueClass?: string }) {
  return (
    <Card>
      <CardContent className="pt-6">
        <p className="text-sm text-muted-foreground">{label}</p>
        <p className={cn("mt-1 text-2xl font-semibold tabular-nums", valueClass)}>{value}</p>
        <p className="mt-1 text-xs text-muted-foreground">{sub}</p>
      </CardContent>
    </Card>
  );
}

