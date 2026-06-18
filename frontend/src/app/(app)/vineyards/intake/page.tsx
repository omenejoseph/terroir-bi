"use client";

import * as React from "react";
import { Plus } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import {
  useAddHarvestEntry,
  useCreateHarvestPlan,
  useHarvestPlan,
  useHarvestPlans,
  useParcels,
  useRecordIntake,
} from "@/hooks/use-vineyards";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { Breadcrumb } from "@/components/ui/breadcrumb";
import { VineyardsSubnav } from "@/components/vineyards/vineyards-subnav";

export default function IntakePage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const canManage = can("vineyards.manage");
  const plans = useHarvestPlans();
  const createPlan = useCreateHarvestPlan();
  const [selected, setSelected] = React.useState<string | null>(null);
  const [error, setError] = React.useState<string | null>(null);

  React.useEffect(() => {
    if (!selected && (plans.data ?? []).length > 0) setSelected(plans.data![0].id);
  }, [plans.data, selected]);

  return (
    <div className="space-y-6">
      <Breadcrumb items={[{ label: t("vineyards.title"), href: "/vineyards" }, { label: t("vineyards.subnav.intake") }]} />
      <VineyardsSubnav />
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">{t("vineyards.intake.title")}</h1>
          <p className="text-sm text-muted-foreground">{t("vineyards.intake.subtitle")}</p>
        </div>
        {canManage && (
          <form
            className="flex items-end gap-2"
            onSubmit={async (e) => {
              e.preventDefault();
              setError(null);
              const f = new FormData(e.currentTarget);
              try {
                const plan = await createPlan.mutateAsync({
                  name: String(f.get("name") ?? ""),
                  season: String(f.get("season") ?? ""),
                  yield_ratio: f.get("ratio") ? Number(f.get("ratio")) : undefined,
                });
                setSelected(plan.id);
                e.currentTarget.reset();
              } catch (err) {
                setError(err instanceof ApiError ? err.message : "Error");
              }
            }}
          >
            <div><Label>{t("vineyards.intake.planName")}</Label><Input name="name" required className="w-40" /></div>
            <div><Label>{t("vineyards.intake.season")}</Label><Input name="season" defaultValue={String(new Date().getFullYear())} className="w-24" required /></div>
            <div><Label>{t("vineyards.intake.yieldRatio")}</Label><Input name="ratio" type="number" step="0.001" defaultValue="0.65" className="w-24" /></div>
            <Button type="submit" size="sm" disabled={createPlan.isPending}><Plus className="size-4" />{t("vineyards.intake.addPlan")}</Button>
          </form>
        )}
      </header>
      {error && <p className="text-sm text-destructive">{error}</p>}

      {plans.isLoading ? (
        <div className="flex h-32 items-center justify-center"><Spinner /></div>
      ) : (plans.data ?? []).length === 0 ? (
        <Card><CardContent className="p-6 text-sm text-muted-foreground">{t("vineyards.intake.empty")}</CardContent></Card>
      ) : (
        <div className="space-y-3">
          <div className="flex flex-wrap gap-2">
            {(plans.data ?? []).map((p) => (
              <button
                key={p.id}
                type="button"
                onClick={() => setSelected(p.id)}
                className={`rounded-md border px-3 py-1.5 text-sm ${selected === p.id ? "border-primary bg-primary/10 font-medium" : "border-border text-muted-foreground"}`}
              >
                {p.name} <span className="text-xs">({p.season})</span>
              </button>
            ))}
          </div>
          {selected && <PlanPanel planId={selected} canManage={canManage} />}
        </div>
      )}
    </div>
  );
}

function PlanPanel({ planId, canManage }: { planId: string; canManage: boolean }) {
  const { t } = useTranslation();
  const { data: plan, isLoading } = useHarvestPlan(planId);
  const parcels = useParcels();
  const addEntry = useAddHarvestEntry(planId);
  const record = useRecordIntake(planId);

  if (isLoading || !plan) {
    return <div className="flex h-24 items-center justify-center"><Spinner /></div>;
  }

  return (
    <Card>
      <CardContent className="space-y-4 p-4">
        <div className="flex items-center justify-between">
          <h3 className="text-sm font-semibold">{t("vineyards.intake.entries")}</h3>
          <span className="text-xs text-muted-foreground">{t("vineyards.intake.yieldRatio")}: {plan.yield_ratio}</span>
        </div>

        {canManage && (
          <form
            className="flex flex-wrap items-end gap-2"
            onSubmit={(e) => {
              e.preventDefault();
              const f = new FormData(e.currentTarget);
              addEntry.mutate({ parcel_id: String(f.get("parcel_id") || "") || null, grape_variety: String(f.get("grape") || "") || null });
              e.currentTarget.reset();
            }}
          >
            <div>
              <Label>{t("vineyards.intake.parcel")}</Label>
              <Select name="parcel_id">
                <option value="">—</option>
                {(parcels.data ?? []).map((p) => (
                  <option key={p.id} value={p.id}>{p.name}</option>
                ))}
              </Select>
            </div>
            <div><Label>{t("vineyards.intake.grape")}</Label><Input name="grape" /></div>
            <Button type="submit" size="sm" disabled={addEntry.isPending}>{t("vineyards.intake.addEntry")}</Button>
          </form>
        )}

        <table className="w-full text-sm">
          <thead className="border-b border-border text-left text-xs text-muted-foreground">
            <tr>
              <th className="p-2">{t("vineyards.intake.grape")}</th>
              <th className="p-2">{t("vineyards.intake.parcel")}</th>
              <th className="p-2">{t("vineyards.intake.status")}</th>
              <th className="p-2 text-right">{t("vineyards.intake.actualKg")}</th>
              <th className="p-2" />
            </tr>
          </thead>
          <tbody>
            {(plan.entries ?? []).map((e) => (
              <tr key={e.id} className="border-b border-border last:border-0">
                <td className="p-2">{e.grape_variety ?? "—"}</td>
                <td className="p-2 text-muted-foreground">{e.parcel_name ?? "—"}</td>
                <td className="p-2"><Badge variant={e.status === "HARVESTED" ? "success" : "secondary"}>{t(`vineyards.entryStatus.${e.status}`)}</Badge></td>
                <td className="p-2 text-right">{e.actual_yield_kg ?? "—"}</td>
                <td className="p-2 text-right">
                  {canManage && e.status === "PLANNED" && (
                    <form
                      className="flex items-center justify-end gap-1"
                      onSubmit={(ev) => {
                        ev.preventDefault();
                        const f = new FormData(ev.currentTarget);
                        record.mutate({ entryId: e.id, input: { actual_yield_kg: Number(f.get("kg")) } });
                      }}
                    >
                      <Input name="kg" type="number" step="0.001" placeholder="kg" className="w-24" required />
                      <Button type="submit" size="sm" variant="outline" disabled={record.isPending}>{t("vineyards.intake.record")}</Button>
                    </form>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </CardContent>
    </Card>
  );
}
