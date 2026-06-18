"use client";

import * as React from "react";

import { ApiError } from "@/lib/api/client";
import { useTranslation } from "@/i18n/context";
import { useCreateWineLot } from "@/hooks/use-cellar";
import { majorToMinor } from "@/lib/money";
import { WINE_TYPES, type WineLot, type WineType } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";

/** Create a wine lot. Calls back with the new lot so the caller can navigate. */
export function WineLotForm({ onSaved, onClose }: { onSaved: (lot: WineLot) => void; onClose: () => void }) {
  const { t } = useTranslation();
  const create = useCreateWineLot();
  const [form, setForm] = React.useState({
    name: "",
    grape_variety: "",
    vintage: String(new Date().getFullYear()),
    initial_volume: "",
    wine_type: "" as WineType | "",
    vineyard: "",
    harvest_weight_kg: "",
    grape_price_per_kg: "",
  });
  const [error, setError] = React.useState<string | null>(null);

  function set<K extends keyof typeof form>(key: K, value: string) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    try {
      const lot = await create.mutateAsync({
        name: form.name,
        grape_variety: form.grape_variety,
        vintage: form.vintage,
        initial_volume: Number(form.initial_volume),
        wine_type: form.wine_type || null,
        vineyard: form.vineyard || null,
        harvest_weight_kg: form.harvest_weight_kg ? Number(form.harvest_weight_kg) : null,
        grape_price_per_kg: form.grape_price_per_kg ? majorToMinor(form.grape_price_per_kg) : null,
      });
      onSaved(lot);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    }
  }

  return (
    <Dialog open onOpenChange={onClose} title={t("cellar.addLot")}>
      <form onSubmit={submit} className="space-y-4 p-6 pt-2">
        <div>
          <Label>{t("cellar.fields.name")}</Label>
          <Input value={form.name} onChange={(e) => set("name", e.target.value)} required />
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label>{t("cellar.fields.grapeVariety")}</Label>
            <Input value={form.grape_variety} onChange={(e) => set("grape_variety", e.target.value)} required />
          </div>
          <div>
            <Label>{t("cellar.fields.vintage")}</Label>
            <Input value={form.vintage} onChange={(e) => set("vintage", e.target.value)} required />
          </div>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label>{t("cellar.fields.initialVolume")} (L)</Label>
            <Input type="number" min="0.001" step="0.001" value={form.initial_volume} onChange={(e) => set("initial_volume", e.target.value)} required />
          </div>
          <div>
            <Label>{t("cellar.fields.wineType")}</Label>
            <Select value={form.wine_type} onChange={(e) => set("wine_type", e.target.value)}>
              <option value="">—</option>
              {WINE_TYPES.map((wt) => (
                <option key={wt} value={wt}>
                  {t(`cellar.wineType.${wt}`)}
                </option>
              ))}
            </Select>
          </div>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label>{t("cellar.fields.harvestWeight")}</Label>
            <Input type="number" min="0" step="0.001" value={form.harvest_weight_kg} onChange={(e) => set("harvest_weight_kg", e.target.value)} />
          </div>
          <div>
            <Label>{t("cellar.fields.pricePerKg")}</Label>
            <Input type="number" min="0" step="0.01" value={form.grape_price_per_kg} onChange={(e) => set("grape_price_per_kg", e.target.value)} />
          </div>
        </div>
        {error && <p className="text-sm text-destructive">{error}</p>}
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>
            {t("cellar.actions.cancel")}
          </Button>
          <Button type="submit" disabled={create.isPending}>
            {t("cellar.actions.save")}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
