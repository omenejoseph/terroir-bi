"use client";

import * as React from "react";

import { ApiError } from "@/lib/api/client";
import { useTranslation } from "@/i18n/context";
import { useBulkCreateVessels } from "@/hooks/use-cellar";
import { VESSEL_TYPES, type VesselType } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";

/** Create a run of similarly-sized, sequentially-named vessels. */
export function BulkVesselDialog({ room, onClose }: { room: string; onClose: () => void }) {
  const { t } = useTranslation();
  const bulk = useBulkCreateVessels();
  const [prefix, setPrefix] = React.useState("F");
  const [start, setStart] = React.useState("1");
  const [count, setCount] = React.useState("10");
  const [type, setType] = React.useState<VesselType>("BARRIQUE");
  const [capacity, setCapacity] = React.useState("225");
  const [error, setError] = React.useState<string | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    try {
      await bulk.mutateAsync({
        prefix,
        start_number: Number(start),
        count: Number(count),
        type,
        capacity_liters: Number(capacity),
        room,
      });
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    }
  }

  return (
    <Dialog open onOpenChange={onClose} title={t("cellar.bulkVessels")}>
      <form onSubmit={submit} className="space-y-4 p-6 pt-2">
        <div className="grid grid-cols-3 gap-3">
          <div>
            <Label>Prefix</Label>
            <Input value={prefix} onChange={(e) => setPrefix(e.target.value)} />
          </div>
          <div>
            <Label>Start #</Label>
            <Input type="number" min="0" value={start} onChange={(e) => setStart(e.target.value)} />
          </div>
          <div>
            <Label>Count</Label>
            <Input type="number" min="1" max="50" value={count} onChange={(e) => setCount(e.target.value)} required />
          </div>
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
        {error && <p className="text-sm text-destructive">{error}</p>}
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>
            {t("cellar.actions.cancel")}
          </Button>
          <Button type="submit" disabled={bulk.isPending}>
            {t("cellar.actions.save")}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
