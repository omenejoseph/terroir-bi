"use client";

import { useTranslation } from "@/i18n/context";
import { useFormatters } from "@/lib/format";
import { useCellarCosts } from "@/hooks/use-cellar";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { CellarPageHeader } from "@/components/cellar/cellar-page-header";

export default function CellarCostsPage() {
  const { t } = useTranslation();
  const { money } = useFormatters();
  const { data, isLoading } = useCellarCosts();

  return (
    <div className="space-y-6">
      <CellarPageHeader title={t("cellar.costsPage.title")} subtitle={t("cellar.costsPage.subtitle")} />
      {isLoading ? (
        <div className="flex h-32 items-center justify-center">
          <Spinner />
        </div>
      ) : (data ?? []).length === 0 ? (
        <Card>
          <CardContent className="p-6 text-sm text-muted-foreground">{t("cellar.costsPage.empty")}</CardContent>
        </Card>
      ) : (
        <Card>
          <CardContent className="overflow-x-auto p-0">
            <table className="w-full text-sm">
              <thead className="border-b border-border text-left text-xs text-muted-foreground">
                <tr>
                  <th className="p-3">{t("cellar.cols.lot")}</th>
                  <th className="p-3">{t("cellar.fields.vintage")}</th>
                  <th className="p-3 text-right">{t("cellar.fields.volume")}</th>
                  <th className="p-3 text-right">{t("cellar.costsPage.grape")}</th>
                  <th className="p-3 text-right">{t("cellar.costsPage.additions")}</th>
                  <th className="p-3 text-right">{t("cellar.costsPage.total")}</th>
                  <th className="p-3 text-right">{t("cellar.costsPage.perLiter")}</th>
                  <th className="p-3 text-right">{t("cellar.costsPage.perBottle")}</th>
                </tr>
              </thead>
              <tbody>
                {(data ?? []).map((row) => (
                  <tr key={row.id} className="border-b border-border last:border-0">
                    <td className="p-3">
                      <div className="font-medium">{row.name}</div>
                      <div className="text-xs text-muted-foreground">{row.lot_number}</div>
                    </td>
                    <td className="p-3 text-muted-foreground">{row.vintage}</td>
                    <td className="p-3 text-right text-muted-foreground">{Math.round(Number(row.current_volume))} L</td>
                    <td className="p-3 text-right">{money(row.cost.grape)}</td>
                    <td className="p-3 text-right">{money(row.cost.additions)}</td>
                    <td className="p-3 text-right font-medium">{money(row.cost.total)}</td>
                    <td className="p-3 text-right">{money(row.cost.per_liter)}</td>
                    <td className="p-3 text-right">{money(row.cost.per_bottle_750)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
