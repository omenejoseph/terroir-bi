"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { Grape, Plus } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import { useCreateParcel, useParcels } from "@/hooks/use-vineyards";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { VineyardsSubnav } from "@/components/vineyards/vineyards-subnav";
import { PARCEL_OWNERSHIPS, type ParcelOwnership } from "@/lib/types";

export default function VineyardsPage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const router = useRouter();
  const { data, isLoading } = useParcels();
  const create = useCreateParcel();
  const [open, setOpen] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  return (
    <div className="space-y-6">
      <header className="flex items-center justify-between">
        <h1 className="flex items-center gap-2 text-2xl font-semibold">
          <Grape className="size-6" /> {t("vineyards.title")}
        </h1>
        {can("vineyards.manage") && (
          <Button onClick={() => setOpen(true)}>
            <Plus className="size-4" />
            {t("vineyards.parcels.add")}
          </Button>
        )}
      </header>
      <VineyardsSubnav />

      {isLoading ? (
        <div className="flex h-32 items-center justify-center">
          <Spinner />
        </div>
      ) : (data ?? []).length === 0 ? (
        <Card>
          <CardContent className="p-6 text-sm text-muted-foreground">{t("vineyards.parcels.empty")}</CardContent>
        </Card>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {(data ?? []).map((p) => (
            <button
              key={p.id}
              type="button"
              onClick={() => router.push(`/vineyards/parcels/${p.id}`)}
              className="rounded-lg border border-border p-4 text-left hover:bg-muted/50"
            >
              <div className="flex items-center justify-between">
                <div className="font-medium">{p.name}</div>
                <Badge variant="secondary">{t(`vineyards.ownershipType.${p.ownership}`)}</Badge>
              </div>
              <div className="text-sm text-muted-foreground">{p.grape_variety}</div>
              <div className="mt-1 text-xs text-muted-foreground">
                {p.area_hectares ? `${p.area_hectares} ha` : ""} {p.vine_count ? `· ${p.vine_count} vines` : ""}
              </div>
            </button>
          ))}
        </div>
      )}

      {open && (
        <Dialog open onOpenChange={setOpen} title={t("vineyards.parcels.add")}>
          <form
            className="space-y-4 p-6 pt-2"
            onSubmit={async (e) => {
              e.preventDefault();
              setError(null);
              const f = new FormData(e.currentTarget);
              try {
                const parcel = await create.mutateAsync({
                  name: String(f.get("name") ?? ""),
                  grape_variety: String(f.get("grape_variety") ?? ""),
                  area_hectares: f.get("area") ? Number(f.get("area")) : null,
                  vine_count: f.get("vines") ? Number(f.get("vines")) : null,
                  ownership: (String(f.get("ownership") ?? "OWN") || "OWN") as ParcelOwnership,
                  location: String(f.get("location") ?? "") || null,
                  soil_type: String(f.get("soil_type") ?? "") || null,
                  elevation: f.get("elevation") ? Number(f.get("elevation")) : null,
                  planting_year: f.get("planting_year") ? Number(f.get("planting_year")) : null,
                  row_spacing: f.get("row_spacing") ? Number(f.get("row_spacing")) : null,
                  rootstock: String(f.get("rootstock") ?? "") || null,
                  training: String(f.get("training") ?? "") || null,
                  orientation: String(f.get("orientation") ?? "") || null,
                  slope: f.get("slope") ? Number(f.get("slope")) : null,
                });
                setOpen(false);
                router.push(`/vineyards/parcels/${parcel.id}`);
              } catch (err) {
                setError(err instanceof ApiError ? err.message : "Error");
              }
            }}
          >
            <div>
              <Label>{t("vineyards.parcels.name")}</Label>
              <Input name="name" required />
            </div>
            <div className="grid grid-cols-3 gap-3">
              <div className="col-span-1">
                <Label>{t("vineyards.parcels.grapeVariety")}</Label>
                <Input name="grape_variety" required />
              </div>
              <div>
                <Label>{t("vineyards.parcels.area")}</Label>
                <Input name="area" type="number" step="0.0001" />
              </div>
              <div>
                <Label>{t("vineyards.parcels.vines")}</Label>
                <Input name="vines" type="number" />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
              <div>
                <Label>{t("vineyards.parcels.ownership")}</Label>
                <Select name="ownership" defaultValue="OWN">
                  {PARCEL_OWNERSHIPS.map((o) => (
                    <option key={o} value={o}>
                      {t(`vineyards.ownershipType.${o}`)}
                    </option>
                  ))}
                </Select>
              </div>
              <div><Label>{t("vineyards.parcels.location")}</Label><Input name="location" /></div>
              <div><Label>{t("vineyards.parcels.soilType")}</Label><Input name="soil_type" /></div>
              <div><Label>{t("vineyards.parcels.elevation")}</Label><Input name="elevation" type="number" /></div>
              <div><Label>{t("vineyards.parcels.plantingYear")}</Label><Input name="planting_year" type="number" /></div>
              <div><Label>{t("vineyards.parcels.rowSpacing")}</Label><Input name="row_spacing" type="number" step="0.01" /></div>
              <div><Label>{t("vineyards.parcels.rootstock")}</Label><Input name="rootstock" /></div>
              <div><Label>{t("vineyards.parcels.training")}</Label><Input name="training" /></div>
              <div><Label>{t("vineyards.parcels.orientation")}</Label><Input name="orientation" /></div>
              <div><Label>{t("vineyards.parcels.slope")}</Label><Input name="slope" type="number" step="0.1" /></div>
            </div>
            {error && <p className="text-sm text-destructive">{error}</p>}
            <div className="flex justify-end gap-2">
              <Button variant="outline" onClick={() => setOpen(false)}>
                {t("vineyards.actions.cancel")}
              </Button>
              <Button type="submit" disabled={create.isPending}>
                {t("vineyards.actions.save")}
              </Button>
            </div>
          </form>
        </Dialog>
      )}
    </div>
  );
}
