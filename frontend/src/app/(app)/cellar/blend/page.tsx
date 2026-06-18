"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { Plus, Trash2 } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useTranslation } from "@/i18n/context";
import { useExecuteBlend, useVessels } from "@/hooks/use-cellar";
import { WINE_TYPES, type WineType } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { CellarPageHeader } from "@/components/cellar/cellar-page-header";

interface SourceRow {
  vessel_lot_id: string;
  volume: string;
}

export default function BlendPage() {
  const { t } = useTranslation();
  const router = useRouter();
  const { data: vessels } = useVessels();
  const blend = useExecuteBlend();
  const [name, setName] = React.useState("");
  const [vintage, setVintage] = React.useState(String(new Date().getFullYear()));
  const [wineType, setWineType] = React.useState<WineType | "">("");
  const [destination, setDestination] = React.useState("");
  const [sources, setSources] = React.useState<SourceRow[]>([{ vessel_lot_id: "", volume: "" }, { vessel_lot_id: "", volume: "" }]);
  const [error, setError] = React.useState<string | null>(null);

  // Every (vessel, lot) pairing that currently holds wine, as blend sources.
  const sourceOptions = React.useMemo(
    () =>
      (vessels ?? []).flatMap((v) =>
        (v.lots ?? []).map((vl) => ({
          value: vl.vessel_lot_id,
          label: `${v.name} · ${vl.lot?.name ?? "—"} (${vl.volume} L)`,
        })),
      ),
    [vessels],
  );

  function setSource(i: number, patch: Partial<SourceRow>) {
    setSources((s) => s.map((row, idx) => (idx === i ? { ...row, ...patch } : row)));
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    const valid = sources.filter((s) => s.vessel_lot_id && Number(s.volume) > 0);
    if (valid.length < 2) {
      setError(t("cellar.blendPage.empty"));
      return;
    }
    try {
      const lot = await blend.mutateAsync({
        name,
        vintage,
        wine_type: wineType || null,
        destination_vessel_id: destination,
        sources: valid.map((s) => ({ vessel_lot_id: s.vessel_lot_id, volume: Number(s.volume) })),
      });
      router.push(`/cellar/lots/${lot.id}`);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    }
  }

  return (
    <div className="space-y-6">
      <CellarPageHeader title={t("cellar.blendPage.title")} subtitle={t("cellar.blendPage.subtitle")} />
      <Card>
        <CardContent className="p-4">
          <form onSubmit={submit} className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <div>
                <Label>{t("cellar.blendPage.newLotName")}</Label>
                <Input value={name} onChange={(e) => setName(e.target.value)} required />
              </div>
              <div>
                <Label>{t("cellar.fields.vintage")}</Label>
                <Input value={vintage} onChange={(e) => setVintage(e.target.value)} />
              </div>
              <div>
                <Label>{t("cellar.fields.wineType")}</Label>
                <Select value={wineType} onChange={(e) => setWineType(e.target.value as WineType | "")}>
                  <option value="">—</option>
                  {WINE_TYPES.map((wt) => (
                    <option key={wt} value={wt}>
                      {t(`cellar.wineType.${wt}`)}
                    </option>
                  ))}
                </Select>
              </div>
              <div>
                <Label>{t("cellar.blendPage.destination")}</Label>
                <Select value={destination} onChange={(e) => setDestination(e.target.value)} required>
                  <option value="">—</option>
                  {(vessels ?? []).map((v) => (
                    <option key={v.id} value={v.id}>
                      {v.name}
                    </option>
                  ))}
                </Select>
              </div>
            </div>

            <div className="space-y-2">
              <Label>{t("cellar.blendPage.source")}</Label>
              {sources.map((row, i) => (
                <div key={i} className="flex items-end gap-2">
                  <div className="flex-1">
                    <Select value={row.vessel_lot_id} onChange={(e) => setSource(i, { vessel_lot_id: e.target.value })}>
                      <option value="">—</option>
                      {sourceOptions.map((o) => (
                        <option key={o.value} value={o.value}>
                          {o.label}
                        </option>
                      ))}
                    </Select>
                  </div>
                  <div className="w-28">
                    <Input
                      type="number"
                      step="0.001"
                      placeholder={t("cellar.blendPage.volume")}
                      value={row.volume}
                      onChange={(e) => setSource(i, { volume: e.target.value })}
                    />
                  </div>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    onClick={() => setSources((s) => s.filter((_, idx) => idx !== i))}
                  >
                    <Trash2 className="size-4" />
                  </Button>
                </div>
              ))}
              <Button type="button" variant="outline" size="sm" onClick={() => setSources((s) => [...s, { vessel_lot_id: "", volume: "" }])}>
                <Plus className="size-4" />
                {t("cellar.blendPage.addSource")}
              </Button>
            </div>

            {error && <p className="text-sm text-destructive">{error}</p>}
            <div className="flex justify-end">
              <Button type="submit" disabled={blend.isPending}>
                {t("cellar.blendPage.execute")}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}
