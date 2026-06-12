"use client";

import * as React from "react";
import { Suspense } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { BarChart3, ChevronDown, Plus, X } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useInflows } from "@/hooks/use-inflows";
import { useCustomers } from "@/hooks/use-customers";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { Inflow, InflowQuery, InflowStatus } from "@/lib/types";
import { INFLOW_STATUSES } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
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
  const [search, setSearch] = React.useState("");
  const [debounced, setDebounced] = React.useState("");

  React.useEffect(() => {
    const id = setTimeout(() => setDebounced(search), 300);
    return () => clearTimeout(id);
  }, [search]);

  const query: InflowQuery = {
    ...(debounced ? { search: debounced } : {}),
    ...(customerId ? { customer_id: customerId } : {}),
    ...(orderId ? { order_id: orderId } : {}),
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

function InflowCard({ inflow, customerName }: { inflow: Inflow; customerName: string | null }) {
  const { t } = useTranslation();
  const { moneyObject, date } = useFormatters();
  const [open, setOpen] = React.useState(false);

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
          </p>
        </div>
        <div className="flex shrink-0 items-center gap-2 text-sm">
          <span className="font-medium tabular-nums">{moneyObject(inflow.amount)}</span>
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
