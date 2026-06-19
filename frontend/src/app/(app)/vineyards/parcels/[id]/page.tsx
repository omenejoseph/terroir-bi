"use client";

import { useParams } from "next/navigation";

import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import { useFormatters } from "@/lib/format";
import {
  useAddApplication,
  useAddCropEstimate,
  useAddMaturity,
  useAddPhenology,
  useParcel,
} from "@/hooks/use-vineyards";
import { PHENOLOGY_STAGES, APPLICATION_TYPES } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { Breadcrumb } from "@/components/ui/breadcrumb";

/** One agronomy field in the parcel "Site details" card; hidden when empty. */
function SiteDetail({ label, value }: { label: string; value: React.ReactNode }) {
  if (value == null || value === "") return null;
  return (
    <div className="space-y-0.5">
      <dt className="text-xs uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="font-medium">{value}</dd>
    </div>
  );
}

export default function ParcelDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { t } = useTranslation();
  const { can } = useAuth();
  const { date } = useFormatters();
  const canManage = can("vineyards.manage");
  const { data: parcel, isLoading } = useParcel(id);
  const addMaturity = useAddMaturity(id);
  const addPhenology = useAddPhenology(id);
  const addCrop = useAddCropEstimate(id);
  const addApplication = useAddApplication(id);

  if (isLoading || !parcel) {
    return (
      <div className="flex h-64 items-center justify-center">
        <Spinner />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <Breadcrumb
        items={[
          { label: t("vineyards.title"), href: "/vineyards" },
          { label: t("vineyards.subnav.parcels"), href: "/vineyards" },
          { label: parcel.name },
        ]}
      />
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">{parcel.name}</h1>
          <p className="text-sm text-muted-foreground">
            {parcel.grape_variety}
            {parcel.area_hectares ? ` · ${parcel.area_hectares} ha` : ""}
            {parcel.vine_count ? ` · ${parcel.vine_count} vines` : ""}
          </p>
        </div>
        <Badge variant="secondary">{t(`vineyards.ownershipType.${parcel.ownership}`)}</Badge>
      </header>

      {[
        parcel.location,
        parcel.soil_type,
        parcel.elevation,
        parcel.planting_year,
        parcel.row_spacing,
        parcel.rootstock,
        parcel.training,
        parcel.orientation,
        parcel.slope,
        parcel.latitude,
      ].some((v) => v != null && v !== "") && (
        <Card>
          <CardContent className="p-4">
            <h3 className="mb-3 text-sm font-semibold">{t("vineyards.parcels.site")}</h3>
            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3 lg:grid-cols-4">
              <SiteDetail label={t("vineyards.parcels.location")} value={parcel.location} />
              <SiteDetail label={t("vineyards.parcels.soilType")} value={parcel.soil_type} />
              <SiteDetail
                label={t("vineyards.parcels.elevation")}
                value={parcel.elevation != null ? `${parcel.elevation} m` : null}
              />
              <SiteDetail label={t("vineyards.parcels.plantingYear")} value={parcel.planting_year} />
              <SiteDetail
                label={t("vineyards.parcels.rowSpacing")}
                value={parcel.row_spacing != null ? `${parcel.row_spacing} m` : null}
              />
              <SiteDetail label={t("vineyards.parcels.rootstock")} value={parcel.rootstock} />
              <SiteDetail label={t("vineyards.parcels.training")} value={parcel.training} />
              <SiteDetail label={t("vineyards.parcels.orientation")} value={parcel.orientation} />
              <SiteDetail
                label={t("vineyards.parcels.slope")}
                value={parcel.slope != null ? `${parcel.slope}%` : null}
              />
              <SiteDetail
                label={t("vineyards.parcels.coordinates")}
                value={
                  parcel.latitude && parcel.longitude
                    ? `${parcel.latitude}, ${parcel.longitude}`
                    : null
                }
              />
            </dl>
          </CardContent>
        </Card>
      )}

      <div className="grid gap-4 lg:grid-cols-2">
        {/* Maturity */}
        <Card>
          <CardContent className="space-y-3 p-4">
            <h3 className="text-sm font-semibold">{t("vineyards.parcels.maturity")}</h3>
            {canManage && (
              <form
                className="flex flex-wrap items-end gap-2"
                onSubmit={(e) => {
                  e.preventDefault();
                  const f = new FormData(e.currentTarget);
                  addMaturity.mutate({ brix: f.get("brix"), ph: f.get("ph"), total_acidity: f.get("ta") });
                  e.currentTarget.reset();
                }}
              >
                <div><Label>Brix</Label><Input name="brix" type="number" step="0.01" className="w-20" /></div>
                <div><Label>pH</Label><Input name="ph" type="number" step="0.01" className="w-20" /></div>
                <div><Label>TA</Label><Input name="ta" type="number" step="0.01" className="w-20" /></div>
                <Button type="submit" size="sm" disabled={addMaturity.isPending}>{t("vineyards.parcels.addMaturity")}</Button>
              </form>
            )}
            <ul className="space-y-1 text-sm">
              {(parcel.maturity_samples ?? []).map((s) => (
                <li key={s.id} className="flex justify-between border-b border-border py-1 last:border-0">
                  <span className="text-muted-foreground">{date(s.date)}</span>
                  <span>{s.brix && `${s.brix} Bx`} {s.ph && `· pH ${s.ph}`}</span>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>

        {/* Phenology */}
        <Card>
          <CardContent className="space-y-3 p-4">
            <h3 className="text-sm font-semibold">{t("vineyards.parcels.phenology")}</h3>
            {canManage && (
              <form
                className="flex flex-wrap items-end gap-2"
                onSubmit={(e) => {
                  e.preventDefault();
                  const f = new FormData(e.currentTarget);
                  addPhenology.mutate({ stage: f.get("stage"), progress_percent: f.get("pct") });
                  e.currentTarget.reset();
                }}
              >
                <div>
                  <Label>Stage</Label>
                  <Select name="stage">
                    {PHENOLOGY_STAGES.map((s) => (
                      <option key={s} value={s}>{t(`vineyards.stage.${s}`)}</option>
                    ))}
                  </Select>
                </div>
                <div><Label>%</Label><Input name="pct" type="number" className="w-20" /></div>
                <Button type="submit" size="sm" disabled={addPhenology.isPending}>{t("vineyards.parcels.addPhenology")}</Button>
              </form>
            )}
            <ul className="space-y-1 text-sm">
              {(parcel.phenology_logs ?? []).map((l) => (
                <li key={l.id} className="flex justify-between border-b border-border py-1 last:border-0">
                  <span className="text-muted-foreground">{date(l.date)}</span>
                  <span>{t(`vineyards.stage.${l.stage}`)} {l.progress_percent && `· ${l.progress_percent}%`}</span>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>

        {/* Crop estimates */}
        <Card>
          <CardContent className="space-y-3 p-4">
            <h3 className="text-sm font-semibold">{t("vineyards.parcels.crop")}</h3>
            {canManage && (
              <form
                className="flex flex-wrap items-end gap-2"
                onSubmit={(e) => {
                  e.preventDefault();
                  const f = new FormData(e.currentTarget);
                  addCrop.mutate({ cluster_count: Number(f.get("clusters")), avg_cluster_weight: Number(f.get("weight")), sample_vine_count: Number(f.get("sample")) });
                  e.currentTarget.reset();
                }}
              >
                <div><Label>Clusters</Label><Input name="clusters" type="number" className="w-24" required /></div>
                <div><Label>g/cluster</Label><Input name="weight" type="number" step="0.01" className="w-24" required /></div>
                <div><Label>Sample vines</Label><Input name="sample" type="number" className="w-24" required /></div>
                <Button type="submit" size="sm" disabled={addCrop.isPending}>{t("vineyards.parcels.addCrop")}</Button>
              </form>
            )}
            <ul className="space-y-1 text-sm">
              {(parcel.crop_estimates ?? []).map((c) => (
                <li key={c.id} className="flex justify-between border-b border-border py-1 last:border-0">
                  <span className="text-muted-foreground">{date(c.date)}</span>
                  <span className="font-medium">{c.estimated_yield_kg} kg</span>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>

        {/* Applications */}
        <Card>
          <CardContent className="space-y-3 p-4">
            <h3 className="text-sm font-semibold">{t("vineyards.parcels.applications")}</h3>
            {canManage && (
              <form
                className="flex flex-wrap items-end gap-2"
                onSubmit={(e) => {
                  e.preventDefault();
                  const f = new FormData(e.currentTarget);
                  addApplication.mutate({ type: f.get("type"), product: f.get("product"), phi_days: f.get("phi") ? Number(f.get("phi")) : null });
                  e.currentTarget.reset();
                }}
              >
                <div>
                  <Label>Type</Label>
                  <Select name="type">
                    {APPLICATION_TYPES.map((a) => (
                      <option key={a} value={a}>{a}</option>
                    ))}
                  </Select>
                </div>
                <div><Label>Product</Label><Input name="product" /></div>
                <div><Label>PHI days</Label><Input name="phi" type="number" className="w-20" /></div>
                <Button type="submit" size="sm" disabled={addApplication.isPending}>{t("vineyards.parcels.addApplication")}</Button>
              </form>
            )}
            <ul className="space-y-1 text-sm">
              {(parcel.applications ?? []).map((a) => (
                <li key={a.id} className="flex justify-between border-b border-border py-1 last:border-0">
                  <span className="text-muted-foreground">{date(a.date)}</span>
                  <span>{a.product ?? a.type}{a.phi_end_date ? ` · PHI ${date(a.phi_end_date)}` : ""}</span>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
