"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { Plus, Trash2 } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useDeleteInflow, useInflows, useUpdateInflowStatus } from "@/hooks/use-inflows";
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
import { useConfirm } from "@/components/ui/confirm";

type Tab = "all" | InflowStatus;

const STATUS_VARIANT: Record<InflowStatus, "secondary" | "success"> = {
  PENDING: "secondary",
  RECEIVED: "success",
};

export default function InflowsPage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const router = useRouter();
  const { moneyObject, date } = useFormatters();
  const confirm = useConfirm();

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
    ...(tab === "all" ? {} : { status: tab }),
  };

  const { data, isLoading, isError, error } = useInflows(query);
  const customersQ = useCustomers();
  const updateStatus = useUpdateInflowStatus();
  const remove = useDeleteInflow();

  const inflows = data?.data ?? [];
  const customers = customersQ.data?.data ?? [];
  const canManage = can("finance.manage");
  const canDelete = can("finance.delete");

  const customerName = (id: string | null) =>
    id ? customers.find((c) => c.id === id)?.company_name ?? null : null;

  const tabs = [
    { value: "all", label: t("inflows.tabs.all") },
    ...INFLOW_STATUSES.map((s) => ({ value: s, label: t(`inflows.status.${s}`) })),
  ];

  async function handleDelete(inflow: Inflow) {
    const ok = await confirm({
      title: t("inflows.deleteTitle"),
      description: t("inflows.deleteBody"),
      confirmLabel: t("inflows.delete"),
      tone: "danger",
    });
    if (!ok) return;
    await remove.mutateAsync(inflow.id);
  }

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
        {canManage && (
          <Button onClick={() => router.push("/inflows/new")} className="shrink-0">
            <Plus className="size-4" />
            {t("inflows.add")}
          </Button>
        )}
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
        <Card>
          <CardContent className="overflow-x-auto pt-6">
            <table className="w-full text-sm">
              <thead className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                  <th className="py-2 pr-3 font-medium">{t("inflows.colDate")}</th>
                  <th className="py-2 pr-3 font-medium">{t("inflows.colSource")}</th>
                  <th className="py-2 pr-3 font-medium">{t("inflows.colCustomer")}</th>
                  <th className="py-2 pr-3 text-right font-medium">{t("inflows.colAmount")}</th>
                  <th className="py-2 pr-3 font-medium">{t("inflows.colStatus")}</th>
                  <th className="py-2 font-medium" />
                </tr>
              </thead>
              <tbody>
                {inflows.map((inflow) => (
                  <tr key={inflow.id} className="border-b border-border last:border-0">
                    <td className="py-2.5 pr-3 text-muted-foreground">{date(inflow.date)}</td>
                    <td className="py-2.5 pr-3">
                      <p className="flex items-center gap-2 font-medium">
                        {inflow.category ?? inflow.reference ?? t("inflows.noSource")}
                        {inflow.is_credit_note && (
                          <Badge variant="outline">{t("inflows.creditNote")}</Badge>
                        )}
                      </p>
                      {inflow.reference && inflow.category && (
                        <p className="text-xs text-muted-foreground">{inflow.reference}</p>
                      )}
                    </td>
                    <td className="py-2.5 pr-3 text-muted-foreground">
                      {customerName(inflow.customer_id) ?? t("inflows.noCustomer")}
                    </td>
                    <td className="py-2.5 pr-3 text-right tabular-nums">
                      {moneyObject(inflow.amount)}
                    </td>
                    <td className="py-2.5 pr-3">
                      {canManage ? (
                        <Select
                          aria-label={t("inflows.markAs")}
                          value={inflow.status}
                          onChange={(e) =>
                            updateStatus.mutate({ id: inflow.id, status: e.target.value as InflowStatus })
                          }
                          className="h-8 w-32"
                        >
                          {INFLOW_STATUSES.map((s) => (
                            <option key={s} value={s}>
                              {t(`inflows.status.${s}`)}
                            </option>
                          ))}
                        </Select>
                      ) : (
                        <Badge variant={STATUS_VARIANT[inflow.status]}>
                          {t(`inflows.status.${inflow.status}`)}
                        </Badge>
                      )}
                    </td>
                    <td className="py-2.5 text-right">
                      {canDelete && (
                        <button
                          type="button"
                          aria-label={t("inflows.delete")}
                          onClick={() => handleDelete(inflow)}
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
