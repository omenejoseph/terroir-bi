"use client";

import * as React from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { FlaskConical } from "lucide-react";

import { wineLotsApi } from "@/lib/api/cellar";
import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import { useEnologicalProducts, useVessels, useWineLots } from "@/hooks/use-cellar";
import type { EnologicalProduct } from "@/lib/types";
import { Breadcrumb } from "@/components/ui/breadcrumb";
import { CellarSubnav } from "@/components/cellar/cellar-subnav";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { so2Color } from "@/lib/cellar-colors";

/** dose = (target − current) × volume / upliftPerUnit, never negative. */
function dose(target: number, current: number, volumeL: number, uplift: number): number {
  const delta = target - current;
  if (delta <= 0 || uplift <= 0 || volumeL <= 0) return 0;
  return (delta * volumeL) / uplift;
}

export default function So2Page() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const qc = useQueryClient();
  const canManage = can("cellar.manage");

  const lots = useWineLots({ exclude_bottled: true, per_page: 200 });
  const vessels = useVessels();
  const products = useEnologicalProducts({ active_only: true });

  const so2Products = React.useMemo(
    () => (products.data ?? []).filter((p) => p.so2_uplift_per_unit != null),
    [products.data],
  );
  const [productId, setProductId] = React.useState("");
  const product: EnologicalProduct | undefined = so2Products.find((p) => p.id === productId) ?? so2Products[0];
  const uplift = product?.so2_uplift_per_unit ? Number(product.so2_uplift_per_unit) : 0;

  // Latest free SO₂ per lot, taken from the cellar-map vessel data.
  const currentByLot = React.useMemo(() => {
    const map: Record<string, number> = {};
    (vessels.data ?? []).forEach((v) =>
      (v.lots ?? []).forEach((vl) => {
        if (vl.lot && vl.lot.free_so2 != null) map[vl.lot.id] = Number(vl.lot.free_so2);
      }),
    );
    return map;
  }, [vessels.data]);

  const [targets, setTargets] = React.useState<Record<string, string>>({});
  const [applied, setApplied] = React.useState<string | null>(null);

  const apply = useMutation({
    mutationFn: (vars: { lotId: string; quantity: number }) =>
      wineLotsApi.addAddition(vars.lotId, {
        name: product?.name ?? "SO₂",
        category: "SO2",
        quantity: Number(vars.quantity.toFixed(3)),
        unit: product?.unit ?? "g",
        enological_product_id: product?.id ?? null,
        note: "SO₂ dose",
      }),
    onSuccess: (_d, vars) => {
      void qc.invalidateQueries({ queryKey: ["cellar"] });
      setApplied(vars.lotId);
      setTimeout(() => setApplied(null), 2500);
    },
  });

  const isLoading = lots.isLoading || vessels.isLoading || products.isLoading;

  return (
    <div className="space-y-6">
      <Breadcrumb items={[{ label: t("cellar.title"), href: "/cellar" }, { label: t("cellar.so2") }]} />
      <CellarSubnav />

      <header>
        <h1 className="flex items-center gap-2 text-2xl font-semibold">
          <FlaskConical className="size-6" /> {t("cellar.so2Page.title")}
        </h1>
        <p className="text-sm text-muted-foreground">{t("cellar.so2Page.subtitle")}</p>
      </header>

      {so2Products.length > 0 && (
        <div className="max-w-xs">
          <Label>{t("cellar.so2Page.product")}</Label>
          <Select value={product?.id ?? ""} onChange={(e) => setProductId(e.target.value)}>
            {so2Products.map((p) => (
              <option key={p.id} value={p.id}>
                {p.name} ({p.unit})
              </option>
            ))}
          </Select>
        </div>
      )}

      {isLoading ? (
        <div className="flex h-32 items-center justify-center">
          <Spinner />
        </div>
      ) : so2Products.length === 0 ? (
        <Card>
          <CardContent className="p-6 text-sm text-muted-foreground">{t("cellar.so2Page.noProduct")}</CardContent>
        </Card>
      ) : (lots.data?.data ?? []).length === 0 ? (
        <Card>
          <CardContent className="p-6 text-sm text-muted-foreground">{t("cellar.so2Page.empty")}</CardContent>
        </Card>
      ) : (
        <Card>
          <CardContent className="p-0">
            <table className="w-full text-sm">
              <thead className="border-b border-border text-left text-xs text-muted-foreground">
                <tr>
                  <th className="p-3">{t("cellar.lots")}</th>
                  <th className="p-3">{t("cellar.fields.volume")}</th>
                  <th className="p-3">{t("cellar.so2Page.current")}</th>
                  <th className="p-3">{t("cellar.so2Page.target")}</th>
                  <th className="p-3">{t("cellar.so2Page.dose")}</th>
                  <th className="p-3" />
                </tr>
              </thead>
              <tbody>
                {(lots.data?.data ?? []).map((lot) => {
                  const current = currentByLot[lot.id] ?? 0;
                  const target = Number(targets[lot.id] ?? "");
                  const volume = Number(lot.current_volume);
                  const d = Number.isFinite(target) ? dose(target, current, volume, uplift) : 0;
                  return (
                    <tr key={lot.id} className="border-b border-border last:border-0">
                      <td className="p-3">
                        <div className="font-medium">{lot.name}</div>
                        <div className="text-xs text-muted-foreground">{lot.lot_number}</div>
                      </td>
                      <td className="p-3 text-muted-foreground">{Math.round(volume)} L</td>
                      <td className="p-3">
                        <span className="font-medium" style={{ color: so2Color(current || null) }}>
                          {current ? `${current} mg/L` : "—"}
                        </span>
                      </td>
                      <td className="p-3">
                        <Input
                          type="number"
                          min="0"
                          className="w-24"
                          value={targets[lot.id] ?? ""}
                          onChange={(e) => setTargets((s) => ({ ...s, [lot.id]: e.target.value }))}
                        />
                      </td>
                      <td className="p-3 font-medium">{d > 0 ? `${d.toFixed(1)} ${product?.unit}` : "—"}</td>
                      <td className="p-3 text-right">
                        {canManage && (
                          <Button
                            size="sm"
                            variant="outline"
                            disabled={d <= 0 || apply.isPending}
                            onClick={() => apply.mutate({ lotId: lot.id, quantity: d })}
                          >
                            {applied === lot.id ? "✓" : t("cellar.so2Page.apply")}
                          </Button>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
