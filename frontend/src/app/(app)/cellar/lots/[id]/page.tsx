"use client";

import * as React from "react";
import { useParams } from "next/navigation";

import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import { useFormatters } from "@/lib/format";
import {
  useAddAddition,
  useAddAnalysis,
  useAddBottling,
  useAddProcess,
  useAddTasting,
  useAddTransfer,
  useAdjustLotVolume,
  useAssignProtocol,
  useDeleteAddition,
  useDeleteAnalysis,
  useFermentationTemplates,
  useGenerateProtocol,
  useLotAnalysisTrend,
  useUpdateWineLot,
  useWineLot,
  useWineLots,
} from "@/hooks/use-cellar";
import { majorToMinor } from "@/lib/money";
import {
  WINE_LOT_STATUSES,
  type CellarTransferType,
  type WineLot,
  type WineLotStatus,
} from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { ParameterTrends } from "@/components/cellar/charts/parameter-trends";
import { Breadcrumb } from "@/components/ui/breadcrumb";
import { fillRatio, lotStatusVariant } from "@/lib/cellar-colors";

type DetailTab =
  | "overview"
  | "analyses"
  | "additions"
  | "transfers"
  | "processes"
  | "tastings"
  | "protocol"
  | "details";

export default function WineLotDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { t } = useTranslation();
  const { can } = useAuth();
  const canManage = can("cellar.manage");
  const { data: lot, isLoading, isError } = useWineLot(id);
  const [tab, setTab] = React.useState<DetailTab>("overview");

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <Spinner />
      </div>
    );
  }
  if (isError || !lot) {
    return <p className="text-sm text-destructive">{t("cellar.error")}</p>;
  }

  const tabs: { value: DetailTab; label: string }[] = [
    { value: "overview", label: t("cellar.tabs.overview") },
    { value: "analyses", label: t("cellar.tabs.analyses") },
    { value: "additions", label: t("cellar.tabs.additions") },
    { value: "transfers", label: t("cellar.tabs.transfers") },
    { value: "processes", label: t("cellar.tabs.processes") },
    { value: "tastings", label: t("cellar.tabs.tastings") },
    { value: "protocol", label: t("cellar.tabs.protocol") },
    { value: "details", label: t("cellar.tabs.details") },
  ];

  return (
    <div className="space-y-6">
      <Breadcrumb
        items={[
          { label: t("cellar.title"), href: "/cellar" },
          { label: t("cellar.lots"), href: "/cellar/lots" },
          { label: lot.name },
        ]}
      />

      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">{lot.name}</h1>
          <p className="text-sm text-muted-foreground">
            {lot.lot_number} · {lot.grape_variety} {lot.vintage}
            {lot.wine_type ? ` · ${t(`cellar.wineType.${lot.wine_type}`)}` : ""}
          </p>
        </div>
        <Badge variant={lotStatusVariant(lot.status)}>{t(`cellar.lotStatus.${lot.status}`)}</Badge>
      </header>

      <Tabs tabs={tabs} value={tab} onChange={(v) => setTab(v as DetailTab)} />

      {tab === "overview" && <OverviewTab lot={lot} canManage={canManage} />}
      {tab === "analyses" && <AnalysesTab lot={lot} canManage={canManage} />}
      {tab === "additions" && <AdditionsTab lot={lot} canManage={canManage} />}
      {tab === "transfers" && <TransfersTab lot={lot} canManage={canManage} />}
      {tab === "processes" && <ProcessesTab lot={lot} canManage={canManage} />}
      {tab === "tastings" && <TastingsTab lot={lot} canManage={canManage} />}
      {tab === "protocol" && <ProtocolTab lot={lot} canManage={canManage} />}
      {tab === "details" && <DetailsTab lot={lot} canManage={canManage} />}
    </div>
  );
}

/* --- Overview ------------------------------------------------------------- */

function OverviewTab({ lot, canManage }: { lot: WineLot; canManage: boolean }) {
  const { t } = useTranslation();
  const { money } = useFormatters();
  const adjust = useAdjustLotVolume(lot.id);
  const ratio = fillRatio(lot.current_volume, lot.initial_volume);
  const cost = lot.cost_breakdown;

  return (
    <div className="grid gap-6 lg:grid-cols-3">
      <div className="space-y-4 lg:col-span-2">
        <Card>
          <CardContent className="space-y-3 p-4">
            <div className="flex items-baseline justify-between">
              <span className="text-sm text-muted-foreground">{t("cellar.fields.currentVolume")}</span>
              <span className="text-xl font-semibold">{lot.current_volume} L</span>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-muted">
              <div className="h-full bg-primary" style={{ width: `${ratio * 100}%` }} />
            </div>
            <div className="text-xs text-muted-foreground">
              {t("cellar.fields.initialVolume")}: {lot.initial_volume} L
            </div>
            <div className="space-y-1">
              {(lot.vessels ?? []).map((v) => (
                <div key={v.vessel_lot_id} className="flex justify-between text-sm">
                  <span>{v.vessel_name ?? v.vessel_id}</span>
                  <span className="text-muted-foreground">{v.volume} L</span>
                </div>
              ))}
            </div>
            {canManage && (
              <form
                className="flex items-end gap-2 pt-2"
                onSubmit={(e) => {
                  e.preventDefault();
                  const data = new FormData(e.currentTarget);
                  const delta = Number(data.get("delta"));
                  if (delta) adjust.mutate({ delta });
                  e.currentTarget.reset();
                }}
              >
                <div>
                  <Label>{t("cellar.actions.adjustVolume")} (±L)</Label>
                  <Input name="delta" type="number" step="0.001" className="w-32" />
                </div>
                <Button type="submit" size="sm" variant="outline" disabled={adjust.isPending}>
                  {t("cellar.actions.save")}
                </Button>
              </form>
            )}
          </CardContent>
        </Card>

        {(lot.grapes ?? []).length > 0 && (
          <Card>
            <CardContent className="p-4">
              <h3 className="mb-2 text-sm font-semibold">{t("cellar.fields.grapeVariety")}</h3>
              {(lot.grapes ?? []).map((g) => (
                <div key={g.id} className="flex justify-between text-sm">
                  <span>{g.grape_variety}</span>
                  <span className="text-muted-foreground">
                    {g.percentage ? `${g.percentage}%` : ""} {g.weight_kg ? `· ${g.weight_kg} kg` : ""}
                  </span>
                </div>
              ))}
            </CardContent>
          </Card>
        )}
      </div>

      <aside className="space-y-4">
        {cost && (
          <Card>
            <CardContent className="space-y-1 p-4 text-sm">
              <h3 className="mb-1 font-semibold">Cost</h3>
              <Row label="Grape" value={money(cost.grape)} />
              <Row label="Additions" value={money(cost.additions)} />
              <Row label="Total" value={money(cost.total)} />
              <Row label="Per L" value={money(cost.per_liter)} />
              <Row label="Per 0.75L" value={money(cost.per_bottle_750)} />
            </CardContent>
          </Card>
        )}
        <LatestAnalysisCard lot={lot} />
      </aside>
    </div>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between">
      <span className="text-muted-foreground">{label}</span>
      <span className="font-medium">{value}</span>
    </div>
  );
}

function LatestAnalysisCard({ lot }: { lot: WineLot }) {
  const { t } = useTranslation();
  const latest = (lot.analyses ?? [])[0];
  if (!latest) return null;
  const shown = (["ph", "alcohol", "free_so2", "total_so2", "brix"] as const).filter((p) => latest[p] != null);
  return (
    <Card>
      <CardContent className="p-4 text-sm">
        <h3 className="mb-2 font-semibold">{t("cellar.tabs.analyses")}</h3>
        {shown.map((p) => (
          <Row key={p} label={t(`cellar.analysis.param.${p}`)} value={String(latest[p])} />
        ))}
      </CardContent>
    </Card>
  );
}

/* --- Analyses ------------------------------------------------------------- */

function AnalysesTab({ lot, canManage }: { lot: WineLot; canManage: boolean }) {
  const { t } = useTranslation();
  const trend = useLotAnalysisTrend(lot.id);
  const add = useAddAnalysis(lot.id);
  const del = useDeleteAnalysis(lot.id);

  return (
    <div className="space-y-4">
      <Card>
        <CardContent className="p-4">
          <h3 className="mb-3 text-sm font-semibold">{t("cellar.analysis.trend")}</h3>
          <ParameterTrends points={trend.data ?? []} />
        </CardContent>
      </Card>

      {canManage && (
        <Card>
          <CardContent className="p-4">
            <form
              className="flex flex-wrap items-end gap-3"
              onSubmit={(e) => {
                e.preventDefault();
                const f = new FormData(e.currentTarget);
                const num = (k: string) => (f.get(k) ? Number(f.get(k)) : null);
                add.mutate({
                  ph: num("ph"),
                  alcohol: num("alcohol"),
                  free_so2: num("free_so2"),
                  total_so2: num("total_so2"),
                  brix: num("brix"),
                });
                e.currentTarget.reset();
              }}
            >
              {(["ph", "alcohol", "free_so2", "total_so2", "brix"] as const).map((p) => (
                <div key={p}>
                  <Label>{t(`cellar.analysis.param.${p}`)}</Label>
                  <Input name={p} type="number" step="0.01" className="w-24" />
                </div>
              ))}
              <Button type="submit" size="sm" disabled={add.isPending}>
                {t("cellar.actions.addAnalysis")}
              </Button>
            </form>
          </CardContent>
        </Card>
      )}

      <div className="space-y-2">
        {(lot.analyses ?? []).map((a) => (
          <div key={a.id} className="flex items-center justify-between rounded-md border border-border p-3 text-sm">
            <span className="text-muted-foreground">{a.date ? new Date(a.date).toLocaleDateString() : ""}</span>
            <span className="flex flex-wrap gap-3">
              {a.ph && <span>pH {a.ph}</span>}
              {a.free_so2 && <span>SO₂ {a.free_so2}</span>}
              {a.brix && <span>Bx {a.brix}</span>}
            </span>
            {canManage && (
              <Button variant="ghost" size="sm" onClick={() => del.mutate(a.id)}>
                {t("cellar.actions.delete")}
              </Button>
            )}
          </div>
        ))}
      </div>
    </div>
  );
}

/* --- Additions ------------------------------------------------------------ */

function AdditionsTab({ lot, canManage }: { lot: WineLot; canManage: boolean }) {
  const { t } = useTranslation();
  const add = useAddAddition(lot.id);
  const del = useDeleteAddition(lot.id);

  return (
    <div className="space-y-4">
      {canManage && (
        <Card>
          <CardContent className="p-4">
            <form
              className="flex flex-wrap items-end gap-3"
              onSubmit={(e) => {
                e.preventDefault();
                const f = new FormData(e.currentTarget);
                add.mutate({
                  name: String(f.get("name") ?? ""),
                  quantity: Number(f.get("quantity")),
                  unit: String(f.get("unit") ?? "g"),
                });
                e.currentTarget.reset();
              }}
            >
              <div>
                <Label>{t("cellar.fields.name")}</Label>
                <Input name="name" required />
              </div>
              <div>
                <Label>Qty</Label>
                <Input name="quantity" type="number" step="0.001" className="w-24" required />
              </div>
              <div>
                <Label>Unit</Label>
                <Input name="unit" defaultValue="g" className="w-20" />
              </div>
              <Button type="submit" size="sm" disabled={add.isPending}>
                {t("cellar.actions.addAddition")}
              </Button>
            </form>
          </CardContent>
        </Card>
      )}
      <div className="space-y-2">
        {(lot.additions ?? []).map((a) => (
          <div key={a.id} className="flex items-center justify-between rounded-md border border-border p-3 text-sm">
            <span>{a.name}</span>
            <span className="text-muted-foreground">{a.quantity} {a.unit}</span>
            {canManage && (
              <Button variant="ghost" size="sm" onClick={() => del.mutate(a.id)}>
                {t("cellar.actions.delete")}
              </Button>
            )}
          </div>
        ))}
      </div>
    </div>
  );
}

/* --- Transfers ------------------------------------------------------------ */

function TransfersTab({ lot, canManage }: { lot: WineLot; canManage: boolean }) {
  const { t } = useTranslation();
  const add = useAddTransfer(lot.id);
  const others = useWineLots({ exclude_bottled: true });

  return (
    <div className="space-y-4">
      {canManage && (
        <Card>
          <CardContent className="p-4">
            <form
              className="flex flex-wrap items-end gap-3"
              onSubmit={(e) => {
                e.preventDefault();
                const f = new FormData(e.currentTarget);
                add.mutate({
                  type: String(f.get("type")) as CellarTransferType,
                  to_lot_id: String(f.get("to_lot_id")),
                  volume_liters: Number(f.get("volume")),
                });
                e.currentTarget.reset();
              }}
            >
              <div>
                <Label>Type</Label>
                <Select name="type" defaultValue="BLEND">
                  <option value="BLEND">Blend</option>
                  <option value="RACK">Rack</option>
                  <option value="SPLIT">Split</option>
                </Select>
              </div>
              <div>
                <Label>To lot</Label>
                <Select name="to_lot_id" required>
                  {(others.data?.data ?? [])
                    .filter((l) => l.id !== lot.id)
                    .map((l) => (
                      <option key={l.id} value={l.id}>
                        {l.lot_number} · {l.name}
                      </option>
                    ))}
                </Select>
              </div>
              <div>
                <Label>{t("cellar.fields.volume")} (L)</Label>
                <Input name="volume" type="number" step="0.001" className="w-28" required />
              </div>
              <Button type="submit" size="sm" disabled={add.isPending}>
                {t("cellar.actions.addTransfer")}
              </Button>
            </form>
          </CardContent>
        </Card>
      )}
      <div className="space-y-2">
        {(lot.transfers ?? []).map((tr) => (
          <div key={tr.id} className="flex items-center justify-between rounded-md border border-border p-3 text-sm">
            <span>{tr.type} · {tr.direction}</span>
            <span className="text-muted-foreground">{tr.volume_liters} L</span>
          </div>
        ))}
      </div>
    </div>
  );
}

/* --- Processes ------------------------------------------------------------ */

function ProcessesTab({ lot, canManage }: { lot: WineLot; canManage: boolean }) {
  const { t } = useTranslation();
  const add = useAddProcess(lot.id);
  return (
    <div className="space-y-4">
      {canManage && (
        <Card>
          <CardContent className="p-4">
            <form
              className="flex flex-wrap items-end gap-3"
              onSubmit={(e) => {
                e.preventDefault();
                const f = new FormData(e.currentTarget);
                add.mutate({ kind: String(f.get("kind") ?? ""), volume: f.get("volume") ? Number(f.get("volume")) : null });
                e.currentTarget.reset();
              }}
            >
              <div>
                <Label>Kind</Label>
                <Input name="kind" placeholder="Racking, Filter…" required />
              </div>
              <div>
                <Label>{t("cellar.fields.volume")} (L)</Label>
                <Input name="volume" type="number" step="0.001" className="w-28" />
              </div>
              <Button type="submit" size="sm" disabled={add.isPending}>
                {t("cellar.actions.save")}
              </Button>
            </form>
          </CardContent>
        </Card>
      )}
      <div className="space-y-2">
        {(lot.processes ?? []).map((p) => (
          <div key={p.id} className="flex items-center justify-between rounded-md border border-border p-3 text-sm">
            <span>{p.kind}</span>
            <span className="text-muted-foreground">{p.volume ? `${p.volume} L` : ""}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

/* --- Tastings ------------------------------------------------------------- */

function TastingsTab({ lot, canManage }: { lot: WineLot; canManage: boolean }) {
  const { t } = useTranslation();
  const add = useAddTasting(lot.id);
  return (
    <div className="space-y-4">
      {canManage && (
        <Card>
          <CardContent className="p-4">
            <form
              className="flex flex-wrap items-end gap-3"
              onSubmit={(e) => {
                e.preventDefault();
                const f = new FormData(e.currentTarget);
                add.mutate({
                  score: f.get("score") ? Number(f.get("score")) : null,
                  note: String(f.get("note") ?? ""),
                });
                e.currentTarget.reset();
              }}
            >
              <div>
                <Label>Score</Label>
                <Input name="score" type="number" min="0" max="100" className="w-20" />
              </div>
              <div className="flex-1">
                <Label>{t("cellar.fields.notes")}</Label>
                <Input name="note" />
              </div>
              <Button type="submit" size="sm" disabled={add.isPending}>
                {t("cellar.actions.save")}
              </Button>
            </form>
          </CardContent>
        </Card>
      )}
      <div className="space-y-2">
        {(lot.tasting_notes ?? []).map((n) => (
          <div key={n.id} className="flex items-center justify-between rounded-md border border-border p-3 text-sm">
            <span>{n.note}</span>
            {n.score != null && <Badge variant="secondary">{n.score}</Badge>}
          </div>
        ))}
      </div>
    </div>
  );
}

/* --- Protocol ------------------------------------------------------------- */

function ProtocolTab({ lot, canManage }: { lot: WineLot; canManage: boolean }) {
  const { t } = useTranslation();
  const templates = useFermentationTemplates({ active_only: true });
  const assign = useAssignProtocol(lot.id);
  const generate = useGenerateProtocol(lot.id);
  const [result, setResult] = React.useState<string | null>(null);

  return (
    <Card>
      <CardContent className="space-y-4 p-4">
        <div>
          <Label>{t("cellar.tabs.protocol")}</Label>
          <Select
            value={lot.fermentation_template_id ?? ""}
            disabled={!canManage}
            onChange={(e) => assign.mutate(e.target.value || null)}
          >
            <option value="">—</option>
            {(templates.data ?? []).map((tpl) => (
              <option key={tpl.id} value={tpl.id}>
                {tpl.name}
              </option>
            ))}
          </Select>
        </div>
        {canManage && lot.fermentation_template_id && (
          <Button
            size="sm"
            onClick={async () => {
              const res = await generate.mutateAsync();
              const r = res as unknown as { created: number; skipped: number };
              setResult(`Created ${r.created}, skipped ${r.skipped}`);
            }}
            disabled={generate.isPending}
          >
            {t("cellar.actions.generateTasks")}
          </Button>
        )}
        {result && <p className="text-sm text-muted-foreground">{result}</p>}
      </CardContent>
    </Card>
  );
}

/* --- Details (status, bottling) ------------------------------------------- */

function DetailsTab({ lot, canManage }: { lot: WineLot; canManage: boolean }) {
  const { t } = useTranslation();
  const update = useUpdateWineLot(lot.id);
  const bottle = useAddBottling(lot.id);

  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <Card>
        <CardContent className="space-y-3 p-4">
          <h3 className="text-sm font-semibold">{t("cellar.fields.status")}</h3>
          <Select
            value={lot.status}
            disabled={!canManage}
            onChange={(e) => update.mutate({ status: e.target.value as WineLotStatus })}
          >
            {WINE_LOT_STATUSES.map((s) => (
              <option key={s} value={s}>
                {t(`cellar.lotStatus.${s}`)}
              </option>
            ))}
          </Select>
        </CardContent>
      </Card>

      {canManage && (
        <Card>
          <CardContent className="p-4">
            <h3 className="mb-2 text-sm font-semibold">{t("cellar.actions.bottle")}</h3>
            <form
              className="flex flex-wrap items-end gap-3"
              onSubmit={(e) => {
                e.preventDefault();
                const f = new FormData(e.currentTarget);
                bottle.mutate({
                  bottle_count: Number(f.get("bottle_count")),
                  bottle_volume_ml: Number(f.get("bottle_volume_ml") || 750),
                });
                e.currentTarget.reset();
              }}
            >
              <div>
                <Label>Bottles</Label>
                <Input name="bottle_count" type="number" min="1" className="w-28" required />
              </div>
              <div>
                <Label>ml</Label>
                <Input name="bottle_volume_ml" type="number" defaultValue="750" className="w-20" />
              </div>
              <Button type="submit" size="sm" disabled={bottle.isPending}>
                {t("cellar.actions.bottle")}
              </Button>
            </form>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
