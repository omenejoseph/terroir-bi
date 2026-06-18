"use client";

import { useTranslation } from "@/i18n/context";
import { useAuth } from "@/lib/auth/context";
import { useDeleteVessel, useVessels } from "@/hooks/use-cellar";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { CellarPageHeader } from "@/components/cellar/cellar-page-header";
import { fillRatio } from "@/lib/cellar-colors";

export default function VesselsPage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const canDelete = can("cellar.delete");
  const { data, isLoading } = useVessels();
  const remove = useDeleteVessel();

  return (
    <div className="space-y-6">
      <CellarPageHeader title={t("cellar.vesselsPage.title")} subtitle={t("cellar.vesselsPage.subtitle")} />
      {isLoading ? (
        <div className="flex h-32 items-center justify-center">
          <Spinner />
        </div>
      ) : (data ?? []).length === 0 ? (
        <Card>
          <CardContent className="p-6 text-sm text-muted-foreground">{t("cellar.vesselsPage.empty")}</CardContent>
        </Card>
      ) : (
        <Card>
          <CardContent className="overflow-x-auto p-0">
            <table className="w-full text-sm">
              <thead className="border-b border-border text-left text-xs text-muted-foreground">
                <tr>
                  <th className="p-3">{t("cellar.fields.name")}</th>
                  <th className="p-3">{t("cellar.fields.room")}</th>
                  <th className="p-3">{t("cellar.fields.type")}</th>
                  <th className="p-3 text-right">{t("cellar.vesselsPage.capacity")}</th>
                  <th className="p-3 text-right">{t("cellar.vesselsPage.fill")}</th>
                  <th className="p-3">{t("cellar.vesselsPage.currentLot")}</th>
                  <th className="p-3" />
                </tr>
              </thead>
              <tbody>
                {(data ?? []).map((v) => {
                  const lot = v.lots?.[0]?.lot ?? null;
                  const pct = Math.round(fillRatio(v.current_volume, v.capacity_liters) * 100);
                  return (
                    <tr key={v.id} className="border-b border-border last:border-0">
                      <td className="p-3 font-medium">
                        {v.name}
                        {v.is_faulty && <Badge variant="destructive" className="ml-2">!</Badge>}
                      </td>
                      <td className="p-3 text-muted-foreground">{v.room}</td>
                      <td className="p-3 text-muted-foreground">{t(`cellar.vesselType.${v.type}`)}</td>
                      <td className="p-3 text-right text-muted-foreground">{Math.round(Number(v.capacity_liters))} L</td>
                      <td className="p-3 text-right">{pct}%</td>
                      <td className="p-3 text-muted-foreground">{lot?.name ?? "—"}</td>
                      <td className="p-3 text-right">
                        {canDelete && pct === 0 && (
                          <Button size="sm" variant="ghost" onClick={() => remove.mutate(v.id)}>
                            {t("cellar.actions.delete")}
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
