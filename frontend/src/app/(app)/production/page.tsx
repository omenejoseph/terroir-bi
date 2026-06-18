"use client";

import * as React from "react";
import { Factory, Plus, Trash2 } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import { useFormatters } from "@/lib/format";
import { useInventory } from "@/hooks/use-inventory";
import {
  useConfirmProductionPlan,
  useCreateProductionPlan,
  useProductionCalculation,
  useProductionPlan,
  useProductionPlans,
  useUpdateProductionPlan,
} from "@/hooks/use-production";
import { PLAN_UNITS, type PlanUnit, type ProductionPlanRowInput, type VintageConflict } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";

export default function ProductionPage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const canManage = can("production.manage");
  const plans = useProductionPlans();
  const createPlan = useCreateProductionPlan();
  const [selected, setSelected] = React.useState<string | null>(null);
  const [error, setError] = React.useState<string | null>(null);

  React.useEffect(() => {
    if (!selected && (plans.data ?? []).length > 0) setSelected(plans.data![0].id);
  }, [plans.data, selected]);

  return (
    <div className="space-y-6">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-semibold">
            <Factory className="size-6" /> {t("production.title")}
          </h1>
          <p className="text-sm text-muted-foreground">{t("production.subtitle")}</p>
        </div>
        {canManage && (
          <form
            className="flex items-end gap-2"
            onSubmit={async (e) => {
              e.preventDefault();
              setError(null);
              const f = new FormData(e.currentTarget);
              try {
                const plan = await createPlan.mutateAsync({ name: String(f.get("name") ?? "") });
                setSelected(plan.id);
                e.currentTarget.reset();
              } catch (err) {
                setError(err instanceof ApiError ? err.message : "Error");
              }
            }}
          >
            <div>
              <Label>{t("production.planName")}</Label>
              <Input name="name" required className="w-48" />
            </div>
            <Button type="submit" size="sm" disabled={createPlan.isPending}>
              <Plus className="size-4" />
              {t("production.addPlan")}
            </Button>
          </form>
        )}
      </header>
      {error && <p className="text-sm text-destructive">{error}</p>}

      {plans.isLoading ? (
        <div className="flex h-32 items-center justify-center">
          <Spinner />
        </div>
      ) : (plans.data ?? []).length === 0 ? (
        <Card>
          <CardContent className="p-6 text-sm text-muted-foreground">{t("production.empty")}</CardContent>
        </Card>
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
                {p.name}{" "}
                <Badge variant={p.status === "CONFIRMED" ? "success" : "secondary"} className="ml-1">
                  {t(`production.planStatus.${p.status}`)}
                </Badge>
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
  const { money, number } = useFormatters();
  const { data: plan, isLoading } = useProductionPlan(planId);
  const items = useInventory({ sellable: true });
  const update = useUpdateProductionPlan(planId);
  const confirm = useConfirmProductionPlan(planId);

  const [rows, setRows] = React.useState<ProductionPlanRowInput[]>([]);
  const [showCalc, setShowCalc] = React.useState(false);
  const [conflicts, setConflicts] = React.useState<VintageConflict[] | null>(null);
  const [msg, setMsg] = React.useState<string | null>(null);
  const calc = useProductionCalculation(planId, showCalc);

  const isDraft = plan?.status === "DRAFT";
  const editable = canManage && isDraft;

  // Hydrate the local editor whenever the plan loads/changes.
  React.useEffect(() => {
    if (!plan) return;
    setRows(
      (plan.rows ?? []).map((r) => ({
        base_item_id: r.base_item_id,
        quantity: Number(r.quantity),
        plan_unit: r.plan_unit,
        new_vintage: r.new_vintage,
      })),
    );
    setShowCalc(false);
    setConflicts(null);
    setMsg(null);
  }, [plan]);

  if (isLoading || !plan) {
    return (
      <div className="flex h-24 items-center justify-center">
        <Spinner />
      </div>
    );
  }

  const itemOptions = items.data?.data ?? [];

  const setRow = (i: number, patch: Partial<ProductionPlanRowInput>) =>
    setRows((prev) => prev.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));

  const save = async () => {
    setMsg(null);
    await update.mutateAsync({ rows: rows.filter((r) => r.base_item_id && r.quantity > 0) });
  };

  const runConfirm = async (force: boolean) => {
    setMsg(null);
    setConflicts(null);
    try {
      await confirm.mutateAsync(force);
      setMsg(t("production.confirmed"));
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        const body = err.body as { data?: { conflicts?: VintageConflict[] } } | undefined;
        setConflicts(body?.data?.conflicts ?? []);
      } else {
        setMsg(err instanceof ApiError ? err.message : "Error");
      }
    }
  };

  return (
    <Card>
      <CardContent className="space-y-4 p-4">
        <div className="flex items-center justify-between">
          <h3 className="text-sm font-semibold">{t("production.rows")}</h3>
          <Badge variant={plan.status === "CONFIRMED" ? "success" : "secondary"}>
            {t(`production.planStatus.${plan.status}`)}
          </Badge>
        </div>

        <table className="w-full text-sm">
          <thead className="border-b border-border text-left text-xs text-muted-foreground">
            <tr>
              <th className="p-2">{t("production.product")}</th>
              <th className="p-2 w-28">{t("production.quantity")}</th>
              <th className="p-2 w-32">{t("production.unit")}</th>
              <th className="p-2 w-32">{t("production.newVintage")}</th>
              <th className="p-2 w-10" />
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && (
              <tr>
                <td colSpan={5} className="p-3 text-center text-muted-foreground">
                  {t("production.empty")}
                </td>
              </tr>
            )}
            {rows.map((r, i) => (
              <tr key={i} className="border-b border-border last:border-0">
                <td className="p-2">
                  {editable ? (
                    <Select value={r.base_item_id} onChange={(e) => setRow(i, { base_item_id: e.target.value })}>
                      <option value="">—</option>
                      {itemOptions.map((it) => (
                        <option key={it.id} value={it.id}>
                          {it.name}
                          {it.vintage ? ` (${it.vintage})` : ""}
                        </option>
                      ))}
                    </Select>
                  ) : (
                    (plan.rows?.[i]?.base_item_name ?? "—")
                  )}
                </td>
                <td className="p-2">
                  {editable ? (
                    <Input
                      type="number"
                      step="0.001"
                      value={r.quantity}
                      onChange={(e) => setRow(i, { quantity: Number(e.target.value) })}
                    />
                  ) : (
                    number(r.quantity)
                  )}
                </td>
                <td className="p-2">
                  {editable ? (
                    <Select value={r.plan_unit} onChange={(e) => setRow(i, { plan_unit: e.target.value as PlanUnit })}>
                      {PLAN_UNITS.map((u) => (
                        <option key={u} value={u}>
                          {t(`production.unitOpt.${u}`)}
                        </option>
                      ))}
                    </Select>
                  ) : (
                    t(`production.unitOpt.${r.plan_unit}`)
                  )}
                </td>
                <td className="p-2">
                  {editable ? (
                    <Input
                      value={r.new_vintage ?? ""}
                      onChange={(e) => setRow(i, { new_vintage: e.target.value || null })}
                    />
                  ) : (
                    (r.new_vintage ?? "—")
                  )}
                </td>
                <td className="p-2 text-right">
                  {editable && (
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      onClick={() => setRows((prev) => prev.filter((_, idx) => idx !== i))}
                    >
                      <Trash2 className="size-4" />
                    </Button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>

        <div className="flex flex-wrap items-center gap-2">
          {editable && (
            <>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() =>
                  setRows((prev) => [...prev, { base_item_id: "", quantity: 1, plan_unit: "bottles", new_vintage: null }])
                }
              >
                <Plus className="size-4" />
                {t("production.addRow")}
              </Button>
              <Button type="button" size="sm" onClick={save} disabled={update.isPending}>
                {t("production.save")}
              </Button>
            </>
          )}
          <Button type="button" variant="outline" size="sm" onClick={() => setShowCalc(true)}>
            {t("production.calculate")}
          </Button>
          {canManage && isDraft && (
            <Button type="button" size="sm" onClick={() => runConfirm(false)} disabled={confirm.isPending}>
              {t("production.confirm")}
            </Button>
          )}
        </div>

        {msg && <p className="text-sm text-success">{msg}</p>}

        {conflicts && (
          <div className="space-y-2 rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm">
            <p>{t("production.conflicts")}</p>
            <ul className="list-inside list-disc text-muted-foreground">
              {conflicts.map((c) => (
                <li key={c.row_id}>
                  {c.name} · {c.vintage}
                </li>
              ))}
            </ul>
            <Button type="button" size="sm" variant="outline" onClick={() => runConfirm(true)} disabled={confirm.isPending}>
              {t("production.confirm")}
            </Button>
          </div>
        )}

        {showCalc && <CalcPanel />}
      </CardContent>
    </Card>
  );

  function CalcPanel() {
    if (calc.isLoading || !calc.data) {
      return (
        <div className="flex h-20 items-center justify-center">
          <Spinner />
        </div>
      );
    }
    const c = calc.data;
    return (
      <div className="space-y-4 rounded-md border border-border p-3">
        <div className="grid grid-cols-3 gap-3 text-center">
          <div>
            <div className="text-xs text-muted-foreground">{t("production.revenue")}</div>
            <div className="text-lg font-semibold">{money(c.totals.revenue)}</div>
          </div>
          <div>
            <div className="text-xs text-muted-foreground">{t("production.cost")}</div>
            <div className="text-lg font-semibold">{money(c.totals.cost)}</div>
          </div>
          <div>
            <div className="text-xs text-muted-foreground">{t("production.margin")}</div>
            <div className="text-lg font-semibold">{c.totals.margin_pct}%</div>
          </div>
        </div>

        {c.rows.length > 0 && (
          <table className="w-full text-sm">
            <thead className="border-b border-border text-left text-xs text-muted-foreground">
              <tr>
                <th className="p-2">{t("production.product")}</th>
                <th className="p-2 text-right">{t("production.unitOpt.bottles")}</th>
                <th className="p-2 text-right">{t("production.revenue")}</th>
                <th className="p-2 text-right">{t("production.cost")}</th>
                <th className="p-2 text-right">{t("production.margin")}</th>
              </tr>
            </thead>
            <tbody>
              {c.rows.map((r) => (
                <tr key={r.row_id} className="border-b border-border last:border-0">
                  <td className="p-2">
                    {r.name}
                    {r.new_vintage ? ` (${r.new_vintage})` : ""}
                  </td>
                  <td className="p-2 text-right">{number(r.bottles)}</td>
                  <td className="p-2 text-right">{money(r.revenue)}</td>
                  <td className="p-2 text-right">{money(r.cost)}</td>
                  <td className="p-2 text-right">{r.margin_pct}%</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}

        {c.materials.length > 0 && (
          <div>
            <h4 className="mb-2 text-xs font-semibold text-muted-foreground">{t("production.materials")}</h4>
            <ul className="space-y-1 text-sm">
              {c.materials.map((m) => (
                <li key={m.name} className="flex justify-between border-b border-border py-1 last:border-0">
                  <span>{m.name}</span>
                  <span className="text-muted-foreground">
                    {number(m.quantity)} {m.unit} · {money(m.cost)}
                  </span>
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    );
  }
}
