"use client";

import * as React from "react";
import { Plus, Trash2 } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import {
  useCostAnalytics,
  useCostCategories,
  useCosts,
  useCreateCost,
  useDeleteCost,
  useUpdateCostStatus,
} from "@/hooks/use-costs";
import { useSuppliers } from "@/hooks/use-suppliers";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { Cost, CostInput, CostQuery, CostStatus } from "@/lib/types";
import { COST_STATUSES } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { useConfirm } from "@/components/ui/confirm";

type StatusTab = "all" | CostStatus;

const STATUS_VARIANT: Record<CostStatus, "default" | "secondary" | "success"> = {
  PENDING: "secondary",
  APPROVED: "default",
  PAID: "success",
};

export default function CostsPage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const { moneyObject, date } = useFormatters();
  const confirm = useConfirm();

  const [tab, setTab] = React.useState<StatusTab>("all");
  const [category, setCategory] = React.useState("");
  const [search, setSearch] = React.useState("");
  const [debounced, setDebounced] = React.useState("");
  const [creating, setCreating] = React.useState(false);

  React.useEffect(() => {
    const id = setTimeout(() => setDebounced(search), 300);
    return () => clearTimeout(id);
  }, [search]);

  const query: CostQuery = {
    ...(debounced ? { search: debounced } : {}),
    ...(category ? { category } : {}),
    ...(tab === "all" ? {} : { status: tab }),
  };
  const { data, isLoading, isError, error } = useCosts(query);
  const analyticsQ = useCostAnalytics();
  const categoriesQ = useCostCategories();
  const updateStatus = useUpdateCostStatus();
  const remove = useDeleteCost();

  const costs = data?.data ?? [];
  const canManage = can("finance.manage");
  const canDelete = can("finance.delete");

  const tabs = [
    { value: "all", label: t("costs.tabs.all") },
    ...COST_STATUSES.map((s) => ({ value: s, label: t(`costs.tabs.${s}`) })),
  ];

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
          <Button onClick={() => setCreating(true)} className="shrink-0">
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

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <Tabs tabs={tabs} value={tab} onChange={(v) => setTab(v as StatusTab)} />
        <div className="flex items-center gap-2">
          <Select
            aria-label={t("costs.allCategories")}
            value={category}
            onChange={(e) => setCategory(e.target.value)}
            className="sm:w-48"
          >
            <option value="">{t("costs.allCategories")}</option>
            {(categoriesQ.data ?? []).map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </Select>
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t("costs.search")}
            className="sm:max-w-xs"
          />
        </div>
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

      <AddCostDialog
        open={creating}
        onOpenChange={setCreating}
        categories={categoriesQ.data ?? []}
      />
    </div>
  );
}

function AddCostDialog({
  open,
  onOpenChange,
  categories,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  categories: string[];
}) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const create = useCreateCost();
  const canViewSuppliers = can("suppliers.view");

  const [category, setCategory] = React.useState("");
  const [totalAmount, setTotalAmount] = React.useState("");
  const [vatAmount, setVatAmount] = React.useState("");
  const [costDate, setCostDate] = React.useState("");
  const [description, setDescription] = React.useState("");
  const [reference, setReference] = React.useState("");
  const [supplierId, setSupplierId] = React.useState("");
  const [formError, setFormError] = React.useState<string | null>(null);

  async function handleSubmit(event: React.SyntheticEvent) {
    event.preventDefault();
    setFormError(null);
    const input: CostInput = {
      total_amount: Number(totalAmount || 0),
      category: category.trim(),
      ...(costDate ? { date: costDate } : {}),
      ...(vatAmount.trim() ? { vat_amount: Number(vatAmount) } : {}),
      ...(description.trim() ? { description: description.trim() } : {}),
      ...(reference.trim() ? { reference: reference.trim() } : {}),
      ...(supplierId ? { supplier_id: supplierId } : {}),
    };
    try {
      await create.mutateAsync(input);
      onOpenChange(false);
      setCategory("");
      setTotalAmount("");
      setVatAmount("");
      setCostDate("");
      setDescription("");
      setReference("");
      setSupplierId("");
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : t("costs.form.errorGeneric"));
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange} title={t("costs.add")}>
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="space-y-2">
            <Label htmlFor="cost_category">{t("costs.form.category")}</Label>
            <Input
              id="cost_category"
              list="cost-categories"
              value={category}
              onChange={(e) => setCategory(e.target.value)}
              required
            />
            <datalist id="cost-categories">
              {categories.map((c) => (
                <option key={c} value={c} />
              ))}
            </datalist>
          </div>
          <div className="space-y-2">
            <Label htmlFor="cost_total">{t("costs.form.totalAmount")}</Label>
            <Input
              id="cost_total"
              type="number"
              min={0}
              step="1"
              value={totalAmount}
              onChange={(e) => setTotalAmount(e.target.value)}
              required
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="cost_vat">{t("costs.form.vatAmount")}</Label>
            <Input
              id="cost_vat"
              type="number"
              min={0}
              step="1"
              value={vatAmount}
              onChange={(e) => setVatAmount(e.target.value)}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="cost_date">{t("costs.form.date")}</Label>
            <Input id="cost_date" type="date" value={costDate} onChange={(e) => setCostDate(e.target.value)} />
          </div>
        </div>

        {canViewSuppliers && <SupplierSelect value={supplierId} onChange={setSupplierId} />}

        <div className="space-y-2">
          <Label htmlFor="cost_description">{t("costs.form.description")}</Label>
          <Input id="cost_description" value={description} onChange={(e) => setDescription(e.target.value)} />
        </div>

        <div className="space-y-2">
          <Label htmlFor="cost_reference">{t("costs.form.reference")}</Label>
          <Input id="cost_reference" value={reference} onChange={(e) => setReference(e.target.value)} />
        </div>

        {formError && (
          <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{formError}</p>
        )}

        <div className="flex justify-end gap-2 border-t border-border pt-4">
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            {t("costs.form.cancel")}
          </Button>
          <Button type="submit" disabled={create.isPending}>
            {create.isPending && <Spinner />}
            {t("costs.form.create")}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

function SupplierSelect({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  const { t } = useTranslation();
  const suppliersQ = useSuppliers();
  const suppliers = suppliersQ.data?.data ?? [];

  return (
    <div className="space-y-2">
      <Label htmlFor="cost_supplier">{t("costs.form.supplier")}</Label>
      <Select id="cost_supplier" value={value} onChange={(e) => onChange(e.target.value)}>
        <option value="">{t("costs.form.noSupplier")}</option>
        {suppliers.map((s) => (
          <option key={s.id} value={s.id}>
            {s.company_name}
          </option>
        ))}
      </Select>
    </div>
  );
}
