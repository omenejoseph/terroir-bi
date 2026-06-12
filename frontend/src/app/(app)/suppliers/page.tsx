"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { ChevronDown, GitMerge, Plus, Truck } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useSuppliers } from "@/hooks/use-suppliers";
import { useTranslation } from "@/i18n/context";
import type { Supplier, SupplierQuery } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { SupplierDetailPanel } from "@/components/suppliers/supplier-detail-panel";
import { SupplierMergeDialog } from "@/components/suppliers/supplier-merge-dialog";

type StatusTab = "all" | "active" | "inactive";

export default function SuppliersPage() {
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

  const query: SupplierQuery = {
    ...(debounced ? { search: debounced } : {}),
    ...(tab === "all" ? {} : { is_active: tab === "active" }),
  };
  const { data, isLoading, isError, error } = useSuppliers(query);
  const suppliers = data?.data ?? [];

  const tabs = [
    { value: "all", label: t("suppliers.tabs.all") },
    { value: "active", label: t("suppliers.tabs.active") },
    { value: "inactive", label: t("suppliers.tabs.inactive") },
  ];

  return (
    <div className="space-y-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold tracking-tight">{t("suppliers.title")}</h1>
          <p className="text-sm text-muted-foreground">
            {data?.meta
              ? t("suppliers.subtitleCount", { count: data.meta.total })
              : t("suppliers.subtitleDefault")}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t("suppliers.search")}
            className="w-full sm:w-auto sm:max-w-xs"
          />
          <Button variant="outline" onClick={() => router.push("/suppliers/orders")} className="shrink-0">
            <Truck className="size-4" />
            {t("suppliers.viewOrders")}
          </Button>
          {can("suppliers.delete") && (
            <Button variant="outline" onClick={() => setMergeOpen(true)} className="shrink-0">
              <GitMerge className="size-4" />
              {t("suppliers.merge.trigger")}
            </Button>
          )}
          {can("suppliers.manage") && (
            <Button onClick={() => router.push("/suppliers/new")} className="shrink-0">
              <Plus className="size-4" />
              {t("suppliers.add")}
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
              ? t("suppliers.errorForbidden")
              : t("suppliers.errorGeneric")}
          </CardContent>
        </Card>
      )}

      {!isLoading && !isError && suppliers.length === 0 && (
        <Card>
          <CardContent className="py-12 text-center text-sm text-muted-foreground">
            {t("suppliers.empty")}
          </CardContent>
        </Card>
      )}

      {!isLoading && !isError && suppliers.length > 0 && (
        <div className="space-y-2">
          {suppliers.map((supplier) => (
            <SupplierCard key={supplier.id} supplier={supplier} />
          ))}
        </div>
      )}

      <SupplierMergeDialog open={mergeOpen} onOpenChange={setMergeOpen} onMerged={setMergedMsg} />
    </div>
  );
}

function SupplierCard({ supplier }: { supplier: Supplier }) {
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
          <p className="truncate font-medium">{supplier.company_name}</p>
          <p className="truncate text-xs text-muted-foreground">
            {supplier.contact_name ? `${supplier.contact_name} · ` : ""}
            {supplier.email ?? supplier.tax_id ?? ""}
          </p>
        </div>
        <div className="flex shrink-0 items-center gap-2 text-sm">
          {supplier.price_items_count != null && (
            <Badge variant="outline">
              {t("suppliers.priceItemsCount", { count: supplier.price_items_count })}
            </Badge>
          )}
          <Badge variant={supplier.is_active ? "success" : "secondary"}>
            {supplier.is_active ? t("common.status.active") : t("common.status.inactive")}
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
            {open && <SupplierDetailPanel supplier={supplier} onDeleted={() => setOpen(false)} />}
          </div>
        </div>
      </div>
    </Card>
  );
}
