"use client";

import * as React from "react";

import { ApiError } from "@/lib/api/client";
import { useTranslation } from "@/i18n/context";
import { useCreateVessel } from "@/hooks/use-cellar";
import { VESSEL_TYPES, type VesselType } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";

/** Create a single vessel into the given room. */
export function VesselDialog({ room, onClose }: { room: string; onClose: () => void }) {
  const { t } = useTranslation();
  const create = useCreateVessel();
  const [name, setName] = React.useState("");
  const [type, setType] = React.useState<VesselType>("TANK");
  const [capacity, setCapacity] = React.useState("");
  const [material, setMaterial] = React.useState("");
  const [error, setError] = React.useState<string | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    try {
      await create.mutateAsync({
        name,
        type,
        capacity_liters: Number(capacity),
        material: material || null,
        room,
      });
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    }
  }

  return (
    <Dialog open onOpenChange={onClose} title={t("cellar.addVessel")}>
      <form onSubmit={submit} className="space-y-4 p-6 pt-2">
        <div>
          <Label>{t("cellar.fields.name")}</Label>
          <Input value={name} onChange={(e) => setName(e.target.value)} required />
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label>{t("cellar.fields.type")}</Label>
            <Select value={type} onChange={(e) => setType(e.target.value as VesselType)}>
              {VESSEL_TYPES.map((vt) => (
                <option key={vt} value={vt}>
                  {t(`cellar.vesselType.${vt}`)}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label>{t("cellar.fields.capacity")} (L)</Label>
            <Input type="number" min="1" step="0.001" value={capacity} onChange={(e) => setCapacity(e.target.value)} required />
          </div>
        </div>
        <div>
          <Label>{t("cellar.fields.material")}</Label>
          <Input value={material} onChange={(e) => setMaterial(e.target.value)} />
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
