"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { BarChart3, ChevronDown, GitMerge, Plus } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useCustomers } from "@/hooks/use-customers";
import { useTranslation } from "@/i18n/context";
import type { Customer, CustomerQuery } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { CustomerDetailPanel } from "@/components/customers/customer-detail-panel";
import { CustomerMergeDialog } from "@/components/customers/customer-merge-dialog";

type StatusTab = "all" | "active" | "inactive";

export default function CustomersPage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const router = useRouter();
  const [tab, setTab] = React.useState<StatusTab>("all");
  const [search, setSearch] = React.useState("");
  const [debounced, setDebounced] = React.useState("");
  const [mergeOpen, setMergeOpen] = React.useState(false);
  const [mergedMsg, setMergedMsg] = React.useState<string | null>(null);

  React.useEffect(() => {
    const id = setTimeout(() => setDebounced(search), 300);
    return () => clearTimeout(id);
  }, [search]);

  const query: CustomerQuery = {
    ...(debounced ? { search: debounced } : {}),
    ...(tab === "all" ? {} : { is_active: tab === "active" }),
  };
  const { data, isLoading, isError, error } = useCustomers(query);
  const customers = data?.data ?? [];

  const tabs = [
    { value: "all", label: t("customers.tabs.all") },
    { value: "active", label: t("customers.tabs.active") },
    { value: "inactive", label: t("customers.tabs.inactive") },
  ];

  return (
    <div className="space-y-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold tracking-tight">{t("customers.title")}</h1>
          <p className="text-sm text-muted-foreground">
            {data?.meta
              ? t("customers.subtitleCount", { count: data.meta.total })
              : t("customers.subtitleDefault")}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t("customers.search")}
            className="w-full sm:w-auto sm:max-w-xs"
          />
          {can("financials.view") && (
            <Button
              variant="outline"
              onClick={() => router.push("/customers/analytics")}
              className="shrink-0"
            >
              <BarChart3 className="size-4" />
              {t("customers.analytics.trigger")}
            </Button>
          )}
          {can("customers.delete") && (
            <Button variant="outline" onClick={() => setMergeOpen(true)} className="shrink-0">
              <GitMerge className="size-4" />
              {t("customers.merge.trigger")}
            </Button>
          )}
          {can("customers.manage") && (
            <Button onClick={() => router.push("/customers/new")} className="shrink-0">
              <Plus className="size-4" />
              {t("customers.add")}
            </Button>
          )}
        </div>
      </header>

      <Tabs tabs={tabs} value={tab} onChange={(v) => setTab(v as StatusTab)} />

      {mergedMsg && (
        <p className="rounded-md bg-success/10 px-3 py-2 text-sm text-success">{mergedMsg}</p>
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
              ? t("customers.errorForbidden")
              : t("customers.errorGeneric")}
          </CardContent>
        </Card>
      )}

      {!isLoading && !isError && customers.length === 0 && (
        <Card>
          <CardContent className="py-12 text-center text-sm text-muted-foreground">
            {t("customers.empty")}
          </CardContent>
        </Card>
      )}

      {!isLoading && !isError && customers.length > 0 && (
        <div className="space-y-2">
          {customers.map((customer) => (
            <CustomerCard key={customer.id} customer={customer} />
          ))}
        </div>
      )}

      <CustomerMergeDialog
        open={mergeOpen}
        onOpenChange={setMergeOpen}
        onMerged={setMergedMsg}
      />
    </div>
  );
}

function CustomerCard({ customer }: { customer: Customer }) {
  const { t } = useTranslation();
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
          <p className="truncate font-medium">{customer.company_name}</p>
          <p className="truncate text-xs text-muted-foreground">
            {customer.contact_name ? `${customer.contact_name} · ` : ""}
            {customer.email}
          </p>
        </div>
        <div className="flex shrink-0 items-center gap-2 text-sm">
          <Badge variant="outline">{customer.pricing_tier?.name ?? t("customers.noTier")}</Badge>
          <span className="hidden tabular-nums text-muted-foreground sm:inline">
            {t("customers.rebateOff", { percent: customer.effective_rebate_percent })}
          </span>
          <Badge variant={customer.is_active ? "success" : "secondary"}>
            {customer.is_active ? t("common.status.active") : t("common.status.inactive")}
          </Badge>
          <ChevronDown
            className={`size-4 text-muted-foreground transition-transform duration-300 ${open ? "rotate-180" : ""}`}
          />
        </div>
      </button>

      {/* Expandable dropdown panel */}
      <div
        className={`grid transition-all duration-300 ease-out ${
          open ? "grid-rows-[1fr] opacity-100" : "grid-rows-[0fr] opacity-0"
        }`}
      >
        <div className="overflow-hidden">
          <div className="border-t border-border px-4 py-4">
            {open && <CustomerDetailPanel customer={customer} onDeleted={() => setOpen(false)} />}
          </div>
        </div>
      </div>
    </Card>
  );
}