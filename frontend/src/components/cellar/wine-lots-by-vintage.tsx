"use client";

import * as React from "react";
import { useRouter } from "next/navigation";

import { useTranslation } from "@/i18n/context";
import { useFormatters } from "@/lib/format";
import type { WineLot } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { lotStatusVariant } from "@/lib/cellar-colors";

/** Group lots by vintage (desc), each as a table matching the legacy cellar page. */
export function WineLotsByVintage({ lots }: { lots: WineLot[] }) {
  const { t } = useTranslation();
  const { date } = useFormatters();
  const router = useRouter();

  const groups = React.useMemo(() => {
    const byVintage = new Map<string, WineLot[]>();
    for (const lot of lots) {
      const key = lot.vintage || "—";
      (byVintage.get(key) ?? byVintage.set(key, []).get(key)!).push(lot);
    }
    return Array.from(byVintage.entries()).sort((a, b) => b[0].localeCompare(a[0]));
  }, [lots]);

  if (lots.length === 0) {
    return (
      <Card>
        <CardContent className="p-6 text-sm text-muted-foreground">{t("cellar.empty")}</CardContent>
      </Card>
    );
  }

  return (
    <div className="space-y-6">
      {groups.map(([vintage, vintageLots]) => {
        const total = Math.round(vintageLots.reduce((s, l) => s + Number(l.current_volume || 0), 0));
        return (
          <section key={vintage} className="space-y-2">
            <h3 className="text-sm font-semibold text-muted-foreground">
              {t("cellar.vintageGroup", { vintage, count: vintageLots.length, volume: total })}
            </h3>
            <Card>
              <CardContent className="overflow-x-auto p-0">
                <table className="w-full text-sm">
                  <thead className="border-b border-border text-left text-xs text-muted-foreground">
                    <tr>
                      <th className="p-3">{t("cellar.cols.lot")}</th>
                      <th className="p-3">{t("cellar.cols.grape")}</th>
                      <th className="p-3">{t("cellar.fields.status")}</th>
                      <th className="hidden p-3 md:table-cell">{t("cellar.cols.lastAnalysis")}</th>
                      <th className="hidden p-3 md:table-cell">{t("cellar.cols.lastAddition")}</th>
                      <th className="p-3 text-right">{t("cellar.fields.volume")}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {vintageLots.map((lot) => {
                      const a = lot.latest_analysis;
                      const add = lot.latest_addition;
                      return (
                        <tr
                          key={lot.id}
                          onClick={() => router.push(`/cellar/lots/${lot.id}`)}
                          className="cursor-pointer border-b border-border last:border-0 hover:bg-muted/50"
                        >
                          <td className="p-3">
                            <div className="font-medium">{lot.name}</div>
                            <div className="text-xs text-muted-foreground">{lot.lot_number}</div>
                          </td>
                          <td className="p-3 text-muted-foreground">{lot.grape_variety}</td>
                          <td className="p-3">
                            <Badge variant={lotStatusVariant(lot.status)}>{t(`cellar.lotStatus.${lot.status}`)}</Badge>
                          </td>
                          <td className="hidden p-3 text-xs md:table-cell">
                            {a ? (
                              <>
                                <div className="text-muted-foreground">{date(a.date)}</div>
                                <div>
                                  {a.ph && <span>pH {a.ph} </span>}
                                  {a.alcohol && <span>· {a.alcohol}% </span>}
                                  {a.free_so2 && <span>· SO₂ {a.free_so2}{a.total_so2 ? `/${a.total_so2}` : ""}</span>}
                                </div>
                              </>
                            ) : (
                              "—"
                            )}
                          </td>
                          <td className="hidden p-3 text-xs md:table-cell">
                            {add ? (
                              <>
                                <div>{add.name}</div>
                                <div className="text-muted-foreground">
                                  {add.quantity} {add.unit}
                                  {add.date ? ` · ${date(add.date)}` : ""}
                                </div>
                              </>
                            ) : (
                              "—"
                            )}
                          </td>
                          <td className="p-3 text-right whitespace-nowrap">
                            <span className="font-medium">{Math.round(Number(lot.current_volume))}</span>
                            <span className="text-muted-foreground"> / {Math.round(Number(lot.initial_volume))} L</span>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </CardContent>
            </Card>
          </section>
        );
      })}
    </div>
  );
}
