"use client";

import * as React from "react";
import { Plus } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import { useFormatters } from "@/lib/format";
import { useCreateGrapeContract, useGrapeContracts } from "@/hooks/use-vineyards";
import { useSuppliers } from "@/hooks/use-suppliers";
import { majorToMinor } from "@/lib/money";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { Breadcrumb } from "@/components/ui/breadcrumb";
import { VineyardsSubnav } from "@/components/vineyards/vineyards-subnav";

export default function ContractsPage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const { money } = useFormatters();
  const { data, isLoading } = useGrapeContracts();
  const suppliers = useSuppliers();
  const create = useCreateGrapeContract();
  const [open, setOpen] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  const supplierName = (id: string) => (suppliers.data?.data ?? []).find((s) => s.id === id)?.company_name ?? id;

  return (
    <div className="space-y-6">
      <Breadcrumb items={[{ label: t("vineyards.title"), href: "/vineyards" }, { label: t("vineyards.subnav.contracts") }]} />
      <VineyardsSubnav />
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">{t("vineyards.contracts.title")}</h1>
          <p className="text-sm text-muted-foreground">{t("vineyards.contracts.subtitle")}</p>
        </div>
        {can("vineyards.manage") && (
          <Button onClick={() => setOpen(true)}>
            <Plus className="size-4" />
            {t("vineyards.contracts.add")}
          </Button>
        )}
      </header>

      {isLoading ? (
        <div className="flex h-32 items-center justify-center">
          <Spinner />
        </div>
      ) : (data ?? []).length === 0 ? (
        <Card>
          <CardContent className="p-6 text-sm text-muted-foreground">{t("vineyards.contracts.empty")}</CardContent>
        </Card>
      ) : (
        <Card>
          <CardContent className="overflow-x-auto p-0">
            <table className="w-full text-sm">
              <thead className="border-b border-border text-left text-xs text-muted-foreground">
                <tr>
                  <th className="p-3">{t("vineyards.contracts.supplier")}</th>
                  <th className="p-3">{t("vineyards.contracts.season")}</th>
                  <th className="p-3">{t("vineyards.contracts.variety")}</th>
                  <th className="p-3 text-right">{t("vineyards.contracts.estimated")}</th>
                  <th className="p-3 text-right">{t("vineyards.contracts.delivered")}</th>
                  <th className="p-3 text-right">{t("vineyards.contracts.price")}</th>
                  <th className="p-3">{t("vineyards.contracts.status")}</th>
                </tr>
              </thead>
              <tbody>
                {(data ?? []).map((c) => (
                  <tr key={c.id} className="border-b border-border last:border-0">
                    <td className="p-3 font-medium">{supplierName(c.supplier_id)}</td>
                    <td className="p-3 text-muted-foreground">{c.season}</td>
                    <td className="p-3 text-muted-foreground">{c.grape_variety}</td>
                    <td className="p-3 text-right">{Math.round(Number(c.estimated_kg))} kg</td>
                    <td className="p-3 text-right">{Math.round(Number(c.delivered_kg))} kg</td>
                    <td className="p-3 text-right">{money(c.price_per_kg.minor)}</td>
                    <td className="p-3"><Badge variant="secondary">{t(`vineyards.contractStatus.${c.status}`)}</Badge></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </CardContent>
        </Card>
      )}

      {open && (
        <Dialog open onOpenChange={setOpen} title={t("vineyards.contracts.add")}>
          <form
            className="space-y-4 p-6 pt-2"
            onSubmit={async (e) => {
              e.preventDefault();
              setError(null);
              const f = new FormData(e.currentTarget);
              try {
                await create.mutateAsync({
                  supplier_id: String(f.get("supplier_id") ?? ""),
                  season: String(f.get("season") ?? ""),
                  grape_variety: String(f.get("variety") ?? ""),
                  estimated_kg: Number(f.get("estimated")),
                  price_per_kg: majorToMinor(String(f.get("price") ?? "0")) ?? 0,
                  min_brix: f.get("min_brix") ? Number(f.get("min_brix")) : null,
                  max_ph: f.get("max_ph") ? Number(f.get("max_ph")) : null,
                  delivery_window: String(f.get("delivery_window") ?? "") || null,
                  payment_terms: String(f.get("payment_terms") ?? "") || null,
                  notes: String(f.get("notes") ?? "") || null,
                });
                setOpen(false);
              } catch (err) {
                setError(err instanceof ApiError ? err.message : "Error");
              }
            }}
          >
            <div>
              <Label>{t("vineyards.contracts.supplier")}</Label>
              <Select name="supplier_id" required>
                <option value="">—</option>
                {(suppliers.data?.data ?? []).map((s) => (
                  <option key={s.id} value={s.id}>{s.company_name}</option>
                ))}
              </Select>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div><Label>{t("vineyards.contracts.season")}</Label><Input name="season" defaultValue={String(new Date().getFullYear())} required /></div>
              <div><Label>{t("vineyards.contracts.variety")}</Label><Input name="variety" required /></div>
              <div><Label>{t("vineyards.contracts.estimated")}</Label><Input name="estimated" type="number" step="0.001" required /></div>
              <div><Label>{t("vineyards.contracts.price")}</Label><Input name="price" type="number" step="0.01" required /></div>
              <div><Label>{t("vineyards.contracts.minBrix")}</Label><Input name="min_brix" type="number" step="0.1" /></div>
              <div><Label>{t("vineyards.contracts.maxPh")}</Label><Input name="max_ph" type="number" step="0.01" /></div>
              <div><Label>{t("vineyards.contracts.deliveryWindow")}</Label><Input name="delivery_window" /></div>
              <div><Label>{t("vineyards.contracts.paymentTerms")}</Label><Input name="payment_terms" /></div>
            </div>
            <div><Label>{t("vineyards.contracts.notes")}</Label><Input name="notes" /></div>
            {error && <p className="text-sm text-destructive">{error}</p>}
            <div className="flex justify-end gap-2">
              <Button variant="outline" onClick={() => setOpen(false)}>{t("vineyards.actions.cancel")}</Button>
              <Button type="submit" disabled={create.isPending}>{t("vineyards.actions.save")}</Button>
            </div>
          </form>
        </Dialog>
      )}
    </div>
  );
}
