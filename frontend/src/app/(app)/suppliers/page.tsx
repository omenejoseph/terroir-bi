"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { ChevronRight, Plus, Truck } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useSuppliers } from "@/hooks/use-suppliers";
import { useTranslation } from "@/i18n/context";
import type { SupplierQuery } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { SupplierForm } from "@/components/suppliers/supplier-form";

type StatusTab = "all" | "active" | "inactive";

export default function SuppliersPage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const router = useRouter();
  const [tab, setTab] = React.useState<StatusTab>("all");
  const [search, setSearch] = React.useState("");
  const [debounced, setDebounced] = React.useState("");
  const [creating, setCreating] = React.useState(false);

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
        <div className="flex items-center gap-2">
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t("suppliers.search")}
            className="sm:max-w-xs"
          />
          <Button variant="outline" onClick={() => router.push("/suppliers/orders")} className="shrink-0">
            <Truck className="size-4" />
            {t("suppliers.viewOrders")}
          </Button>
          {can("suppliers.manage") && (
            <Button onClick={() => setCreating(true)} className="shrink-0">
              <Plus className="size-4" />
              {t("suppliers.add")}
            </Button>
          )}
        </div>
      </header>

      <Tabs tabs={tabs} value={tab} onChange={(v) => setTab(v as StatusTab)} />

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
            <Card key={supplier.id} className="overflow-hidden">
              <button
                type="button"
                onClick={() => router.push(`/suppliers/${supplier.id}`)}
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
                  <ChevronRight className="size-4 text-muted-foreground" />
                </div>
              </button>
            </Card>
          ))}
        </div>
      )}

      <Dialog open={creating} onOpenChange={setCreating} title={t("suppliers.add")}>
        <SupplierForm
          supplier={null}
          onSaved={(saved) => {
            setCreating(false);
            router.push(`/suppliers/${saved.id}`);
          }}
          onCancel={() => setCreating(false)}
        />
      </Dialog>
    </div>
  );
}
