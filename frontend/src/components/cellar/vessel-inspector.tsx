"use client";

import * as React from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";

import { ApiError } from "@/lib/api/client";
import { wineLotsApi } from "@/lib/api/cellar";
import { useTranslation } from "@/i18n/context";
import { useVessels, useWineLots } from "@/hooks/use-cellar";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";

/** Inspect a vessel: see what it holds and assign/unassign wine. */
export function VesselInspector({
  vesselId,
  onClose,
  canManage,
}: {
  vesselId: string;
  onClose: () => void;
  canManage: boolean;
}) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  const { data: vessels } = useVessels();
  const { data: lots } = useWineLots({ exclude_bottled: true });
  const vessel = (vessels ?? []).find((v) => v.id === vesselId);

  const [lotId, setLotId] = React.useState("");
  const [volume, setVolume] = React.useState("");
  const [error, setError] = React.useState<string | null>(null);

  const invalidate = () => void qc.invalidateQueries({ queryKey: ["cellar"] });

  const assign = useMutation({
    mutationFn: () => wineLotsApi.assignVessel(lotId, vesselId, Number(volume)),
    onSuccess: () => {
      invalidate();
      setLotId("");
      setVolume("");
    },
    onError: (e) => setError(e instanceof ApiError ? e.message : "Error"),
  });

  const unassign = useMutation({
    mutationFn: (v: { lotId: string; vesselLotId: string }) => wineLotsApi.unassignVessel(v.lotId, v.vesselLotId),
    onSuccess: invalidate,
  });

  if (!vessel) return null;

  return (
    <Dialog open onOpenChange={onClose} title={vessel.name} description={`${vessel.current_volume} / ${vessel.capacity_liters} L`}>
      <div className="space-y-4 p-6 pt-2">
        <div>
          <h3 className="mb-2 text-sm font-semibold">{t("cellar.lots")}</h3>
          {(vessel.lots ?? []).length === 0 ? (
            <p className="text-sm text-muted-foreground">—</p>
          ) : (
            <ul className="space-y-2">
              {(vessel.lots ?? []).map((vl) => (
                <li key={vl.vessel_lot_id} className="flex items-center justify-between rounded-md border border-border p-2 text-sm">
                  <span>
                    <span className="font-medium">{vl.lot?.name ?? "—"}</span>{" "}
                    <span className="text-muted-foreground">· {vl.volume} L</span>
                  </span>
                  {canManage && (
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => unassign.mutate({ lotId: vl.wine_lot_id, vesselLotId: vl.vessel_lot_id })}
                    >
                      {t("cellar.actions.delete")}
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>

        {canManage && (
          <form
            className="space-y-3 border-t border-border pt-4"
            onSubmit={(e) => {
              e.preventDefault();
              setError(null);
              assign.mutate();
            }}
          >
            <h3 className="text-sm font-semibold">{t("cellar.actions.assignVessel")}</h3>
            <div>
              <Label>{t("cellar.lots")}</Label>
              <Select value={lotId} onChange={(e) => setLotId(e.target.value)} required>
                <option value="">—</option>
                {(lots?.data ?? []).map((l) => (
                  <option key={l.id} value={l.id}>
                    {l.lot_number} · {l.name}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label>{t("cellar.fields.volume")} (L)</Label>
              <Input type="number" min="0.001" step="0.001" value={volume} onChange={(e) => setVolume(e.target.value)} required />
            </div>
            {error && <p className="text-sm text-destructive">{error}</p>}
            <div className="flex justify-end">
              <Button type="submit" size="sm" disabled={assign.isPending || !lotId}>
                {t("cellar.actions.assignVessel")}
              </Button>
            </div>
          </form>
        )}
      </div>
    </Dialog>
  );
}
