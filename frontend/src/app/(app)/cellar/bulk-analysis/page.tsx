"use client";

import * as React from "react";
import { Plus, Trash2 } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useTranslation } from "@/i18n/context";
import { useBulkAnalyses, useWineLots } from "@/hooks/use-cellar";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { CellarPageHeader } from "@/components/cellar/cellar-page-header";

interface Row {
  wine_lot_id: string;
  ph: string;
  free_so2: string;
  total_so2: string;
  brix: string;
}

const EMPTY: Row = { wine_lot_id: "", ph: "", free_so2: "", total_so2: "", brix: "" };

export default function BulkAnalysisPage() {
  const { t } = useTranslation();
  const lots = useWineLots({ exclude_bottled: true, per_page: 200 });
  const save = useBulkAnalyses();
  const [rows, setRows] = React.useState<Row[]>([{ ...EMPTY }, { ...EMPTY }, { ...EMPTY }]);
  const [error, setError] = React.useState<string | null>(null);
  const [done, setDone] = React.useState(false);

  function setRow(i: number, patch: Partial<Row>) {
    setRows((r) => r.map((row, idx) => (idx === i ? { ...row, ...patch } : row)));
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setDone(false);
    const valid = rows.filter((r) => r.wine_lot_id);
    if (valid.length === 0) return;
    const num = (v: string) => (v ? Number(v) : null);
    try {
      await save.mutateAsync(
        valid.map((r) => ({
          wine_lot_id: r.wine_lot_id,
          ph: num(r.ph),
          free_so2: num(r.free_so2),
          total_so2: num(r.total_so2),
          brix: num(r.brix),
        })),
      );
      setRows([{ ...EMPTY }, { ...EMPTY }, { ...EMPTY }]);
      setDone(true);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    }
  }

  return (
    <div className="space-y-6">
      <CellarPageHeader title={t("cellar.bulkAnalysisPage.title")} subtitle={t("cellar.bulkAnalysisPage.subtitle")} />
      <Card>
        <CardContent className="p-4">
          <form onSubmit={submit} className="space-y-3">
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="text-left text-xs text-muted-foreground">
                  <tr>
                    <th className="p-2">{t("cellar.bulkAnalysisPage.lot")}</th>
                    <th className="p-2">pH</th>
                    <th className="p-2">Free SO₂</th>
                    <th className="p-2">Total SO₂</th>
                    <th className="p-2">Brix</th>
                    <th className="p-2" />
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row, i) => (
                    <tr key={i}>
                      <td className="p-1">
                        <Select value={row.wine_lot_id} onChange={(e) => setRow(i, { wine_lot_id: e.target.value })}>
                          <option value="">—</option>
                          {(lots.data?.data ?? []).map((l) => (
                            <option key={l.id} value={l.id}>
                              {l.lot_number} · {l.name}
                            </option>
                          ))}
                        </Select>
                      </td>
                      {(["ph", "free_so2", "total_so2", "brix"] as const).map((field) => (
                        <td key={field} className="p-1">
                          <Input
                            type="number"
                            step="0.01"
                            className="w-24"
                            value={row[field]}
                            onChange={(e) => setRow(i, { [field]: e.target.value })}
                          />
                        </td>
                      ))}
                      <td className="p-1">
                        <Button type="button" variant="ghost" size="icon" onClick={() => setRows((r) => r.filter((_, idx) => idx !== i))}>
                          <Trash2 className="size-4" />
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="flex items-center gap-3">
              <Button type="button" variant="outline" size="sm" onClick={() => setRows((r) => [...r, { ...EMPTY }])}>
                <Plus className="size-4" />
                {t("cellar.bulkAnalysisPage.addRow")}
              </Button>
              <Button type="submit" disabled={save.isPending}>
                {t("cellar.bulkAnalysisPage.save")}
              </Button>
              {done && <span className="text-sm text-green-600">{t("cellar.bulkAnalysisPage.done")}</span>}
              {error && <span className="text-sm text-destructive">{error}</span>}
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}
