"use client";

import * as React from "react";
import { Plus } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import {
  useAdjustEnologicalStock,
  useCreateEnologicalProduct,
  useDeleteEnologicalProduct,
  useEnologicalProducts,
} from "@/hooks/use-cellar";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Spinner } from "@/components/ui/spinner";
import { CellarPageHeader } from "@/components/cellar/cellar-page-header";

export default function ProductsPage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const canManage = can("cellar.manage");
  const { data, isLoading } = useEnologicalProducts();
  const create = useCreateEnologicalProduct();
  const adjust = useAdjustEnologicalStock();
  const remove = useDeleteEnologicalProduct();
  const [open, setOpen] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  return (
    <div className="space-y-6">
      <CellarPageHeader
        title={t("cellar.productsPage.title")}
        subtitle={t("cellar.productsPage.subtitle")}
        actions={
          canManage && (
            <Button onClick={() => setOpen(true)}>
              <Plus className="size-4" />
              {t("cellar.productsPage.add")}
            </Button>
          )
        }
      />

      {isLoading ? (
        <div className="flex h-32 items-center justify-center">
          <Spinner />
        </div>
      ) : (data ?? []).length === 0 ? (
        <Card>
          <CardContent className="p-6 text-sm text-muted-foreground">{t("cellar.productsPage.empty")}</CardContent>
        </Card>
      ) : (
        <Card>
          <CardContent className="overflow-x-auto p-0">
            <table className="w-full text-sm">
              <thead className="border-b border-border text-left text-xs text-muted-foreground">
                <tr>
                  <th className="p-3">{t("cellar.productsPage.name")}</th>
                  <th className="p-3">{t("cellar.productsPage.category")}</th>
                  <th className="p-3">{t("cellar.productsPage.unit")}</th>
                  <th className="p-3 text-right">{t("cellar.productsPage.stock")}</th>
                  <th className="p-3 text-right">{t("cellar.productsPage.uplift")}</th>
                  <th className="p-3" />
                </tr>
              </thead>
              <tbody>
                {(data ?? []).map((p) => (
                  <tr key={p.id} className="border-b border-border last:border-0">
                    <td className="p-3 font-medium">{p.name}</td>
                    <td className="p-3 text-muted-foreground">{p.category}</td>
                    <td className="p-3 text-muted-foreground">{p.unit}</td>
                    <td className="p-3 text-right">{p.current_stock}</td>
                    <td className="p-3 text-right text-muted-foreground">{p.so2_uplift_per_unit ?? "—"}</td>
                    <td className="p-3 text-right">
                      {canManage && (
                        <div className="flex justify-end gap-1">
                          <Button size="sm" variant="ghost" onClick={() => adjust.mutate({ id: p.id, delta: 100 })}>
                            +100
                          </Button>
                          <Button size="sm" variant="ghost" onClick={() => adjust.mutate({ id: p.id, delta: -100 })}>
                            −100
                          </Button>
                          <Button size="sm" variant="ghost" onClick={() => remove.mutate(p.id)}>
                            {t("cellar.actions.delete")}
                          </Button>
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </CardContent>
        </Card>
      )}

      {open && (
        <Dialog open onOpenChange={setOpen} title={t("cellar.productsPage.add")}>
          <form
            className="space-y-4 p-6 pt-2"
            onSubmit={async (e) => {
              e.preventDefault();
              setError(null);
              const f = new FormData(e.currentTarget);
              try {
                await create.mutateAsync({
                  name: String(f.get("name") ?? ""),
                  category: String(f.get("category") ?? ""),
                  unit: String(f.get("unit") ?? "g"),
                  current_stock: f.get("stock") ? Number(f.get("stock")) : 0,
                  so2_uplift_per_unit: f.get("uplift") ? Number(f.get("uplift")) : null,
                });
                setOpen(false);
              } catch (err) {
                setError(err instanceof ApiError ? err.message : "Error");
              }
            }}
          >
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label>{t("cellar.productsPage.name")}</Label>
                <Input name="name" required />
              </div>
              <div>
                <Label>{t("cellar.productsPage.category")}</Label>
                <Input name="category" required defaultValue="SO2" />
              </div>
              <div>
                <Label>{t("cellar.productsPage.unit")}</Label>
                <Input name="unit" required defaultValue="g" />
              </div>
              <div>
                <Label>{t("cellar.productsPage.stock")}</Label>
                <Input name="stock" type="number" step="0.001" />
              </div>
              <div>
                <Label>{t("cellar.productsPage.uplift")}</Label>
                <Input name="uplift" type="number" step="0.0001" />
              </div>
            </div>
            {error && <p className="text-sm text-destructive">{error}</p>}
            <div className="flex justify-end gap-2">
              <Button variant="outline" onClick={() => setOpen(false)}>
                {t("cellar.actions.cancel")}
              </Button>
              <Button type="submit" disabled={create.isPending}>
                {t("cellar.actions.save")}
              </Button>
            </div>
          </form>
        </Dialog>
      )}
    </div>
  );
}
