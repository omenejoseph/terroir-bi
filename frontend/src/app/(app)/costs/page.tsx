"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { Plus, Trash2 } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import {
  useCostAnalytics,
  useCostCategories,
  useCostGroupCounts,
  useCosts,
  useDeleteCost,
  useUpdateCostStatus,
} from "@/hooks/use-costs";
import { useSuppliers } from "@/hooks/use-suppliers";
import { addMonths, endOfMonth, startOfMonth, startOfQuarter } from "@/lib/calendar";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { Cost, CostGroup, CostQuery, CostStatus } from "@/lib/types";
import { COST_STATUSES } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { useConfirm } from "@/components/ui/confirm";

type Tab = "all" | CostGroup;

const STATUS_VARIANT: Record<CostStatus, "default" | "secondary" | "success"> = {
  PENDING: "secondary",
  APPROVED: "default",
  PAID: "success",
};

const COST_PERIODS = ["all", "thisMonth", "lastMonth", "thisQuarter", "thisYear", "lastYear", "custom"] as const;
type CostPeriod = (typeof COST_PERIODS)[number];

const iso = (d: Date): string =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;

/** A preset period → an inclusive { from, to } date range (empty = no bound). */
function periodRange(p: CostPeriod, now = new Date()): { from?: string; to?: string } {
  switch (p) {
    case "thisMonth":
      return { from: iso(startOfMonth(now)), to: iso(endOfMonth(now)) };
    case "lastMonth": {
      const lm = addMonths(now, -1);
      return { from: iso(startOfMonth(lm)), to: iso(endOfMonth(lm)) };
    }
    case "thisQuarter":
      return { from: iso(startOfQuarter(now)), to: iso(now) };
    case "thisYear":
      return { from: iso(new Date(now.getFullYear(), 0, 1)), to: iso(now) };
    case "lastYear":
      return {
        from: iso(new Date(now.getFullYear() - 1, 0, 1)),
        to: iso(new Date(now.getFullYear() - 1, 11, 31)),
      };
    default:
      return {};
  }
}

export default function CostsPage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const router = useRouter();
  const { moneyObject, date } = useFormatters();
  const confirm = useConfirm();

  const [tab, setTab] = React.useState<Tab>("all");
  const [category, setCategory] = React.useState("");
  const [status, setStatus] = React.useState<CostStatus | "">("");
  const [supplierId, setSupplierId] = React.useState("");
  const [period, setPeriod] = React.useState<CostPeriod>("all");
  const [customFrom, setCustomFrom] = React.useState("");
  const [customTo, setCustomTo] = React.useState("");
  const [search, setSearch] = React.useState("");
  const [debounced, setDebounced] = React.useState("");

  React.useEffect(() => {
    const id = setTimeout(() => setDebounced(search), 300);
    return () => clearTimeout(id);
  }, [search]);

  const range =
    period === "custom" ? { from: customFrom || undefined, to: customTo || undefined } : periodRange(period);

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
  const suppliersQ = useSuppliers();
  const updateStatus = useUpdateCostStatus();
  const remove = useDeleteCost();

  const costs = data?.data ?? [];
  const canManage = can("finance.manage");
  const canDelete = can("finance.delete");
  const canViewSuppliers = can("suppliers.view");

  const tabs = (["all", "invoices", "payments", "others"] as const).map((g) => ({
    value: g,
    label: `${t(`costs.groups.${g}`)} (${counts?.[g] ?? 0})`,
  }));

  async function handleDelete(cost: Cost) {
    const ok = await confirm({
      title: t("costs.deleteTitle"),
      description: t("costs.deleteBody"),
      confirmLabel: t("costs.delete"),
      tone: "danger",
    });
    if (!ok) return;
    await remove.mutateAsync(cost.id);
  }

  return (
    <div className="space-y-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold tracking-tight">{t("costs.title")}</h1>
          <p className="text-sm text-muted-foreground">{t("costs.subtitleDefault")}</p>
        </div>
        {canManage && (
          <Button onClick={() => router.push("/costs/new")} className="shrink-0">
            <Plus className="size-4" />
            {t("costs.add")}
          </Button>
        )}
      </header>

      {/* Analytics strip */}
      {analyticsQ.data && (
        <div className="grid gap-4 sm:grid-cols-2">
          <Card>
            <CardContent className="pt-6">
              <p className="text-sm text-muted-foreground">{t("costs.totalSpend")}</p>
              <p className="mt-1 text-2xl font-semibold tabular-nums">
                {moneyObject(analyticsQ.data.total_spend)}
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <p className="text-sm text-muted-foreground">{t("costs.unpaid")}</p>
              <p className="mt-1 text-2xl font-semibold tabular-nums">
                {moneyObject(analyticsQ.data.unpaid)}
              </p>
            </CardContent>
          </Card>
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

        <Select
          aria-label={t("costs.period.label")}
          value={period}
          onChange={(e) => setPeriod(e.target.value as CostPeriod)}
          className="sm:w-40"
        >
          {COST_PERIODS.map((p) => (
            <option key={p} value={p}>
              {t(`costs.period.${p}`)}
            </option>
          ))}
        </Select>

        {period === "custom" && (
          <>
            <Input
              type="date"
              aria-label={t("costs.period.from")}
              value={customFrom}
              onChange={(e) => setCustomFrom(e.target.value)}
              className="sm:w-40"
            />
            <Input
              type="date"
              aria-label={t("costs.period.to")}
              value={customTo}
              onChange={(e) => setCustomTo(e.target.value)}
              className="sm:w-40"
            />
          </>
        )}

        <Input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder={t("costs.search")}
          className="sm:max-w-xs"
        />
      </div>

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
        <Card>
          <CardContent className="overflow-x-auto pt-6">
            <table className="w-full text-sm">
              <thead className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                  <th className="py-2 pr-3 font-medium">{t("costs.colDate")}</th>
                  <th className="py-2 pr-3 font-medium">{t("costs.colCategory")}</th>
                  <th className="py-2 pr-3 font-medium">{t("costs.colSupplier")}</th>
                  <th className="py-2 pr-3 text-right font-medium">{t("costs.colTotal")}</th>
                  <th className="py-2 pr-3 font-medium">{t("costs.form.status")}</th>
                  <th className="py-2 font-medium" />
                </tr>
              </thead>
              <tbody>
                {costs.map((cost) => (
                  <tr key={cost.id} className="border-b border-border last:border-0">
                    <td className="py-2.5 pr-3 text-muted-foreground">{date(cost.date)}</td>
                    <td className="py-2.5 pr-3">
                      <p className="font-medium">{cost.category}</p>
                      {cost.description && (
                        <p className="text-xs text-muted-foreground">{cost.description}</p>
                      )}
                    </td>
                    <td className="py-2.5 pr-3 text-muted-foreground">
                      {cost.supplier?.company_name ?? t("costs.noSupplier")}
                    </td>
                    <td className="py-2.5 pr-3 text-right tabular-nums">
                      {moneyObject(cost.total_amount)}
                    </td>
                    <td className="py-2.5 pr-3">
                      {canManage ? (
                        <Select
                          aria-label={t("costs.markAs")}
                          value={cost.status}
                          onChange={(e) =>
                            updateStatus.mutate({ id: cost.id, status: e.target.value as CostStatus })
                          }
                          className="h-8 w-32"
                        >
                          {COST_STATUSES.map((s) => (
                            <option key={s} value={s}>
                              {t(`costs.status.${s}`)}
                            </option>
                          ))}
                        </Select>
                      ) : (
                        <Badge variant={STATUS_VARIANT[cost.status]}>
                          {t(`costs.status.${cost.status}`)}
                        </Badge>
                      )}
                    </td>
                    <td className="py-2.5 text-right">
                      {canDelete && (
                        <button
                          type="button"
                          aria-label={t("costs.delete")}
                          onClick={() => handleDelete(cost)}
                          className="text-muted-foreground hover:text-destructive"
                        >
                          <Trash2 className="size-4" />
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </CardContent>
        </Card>
      )}

    </div>
  );
}

