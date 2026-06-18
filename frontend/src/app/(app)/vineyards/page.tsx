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
import { Spinner } from "@/components/ui/spinner";
import { VineyardsSubnav } from "@/components/vineyards/vineyards-subnav";

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
