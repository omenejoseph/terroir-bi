"use client";

import * as React from "react";
import { Plus, Trash2 } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import {
  useBottleAnalyses,
  useCreateBottleAnalysis,
  useDeleteBottleAnalysis,
} from "@/hooks/use-inventory";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import {
  BOTTLE_ANALYSIS_FIELDS,
  type BottleAnalysisField,
  type BottleAnalysisInput,
  type InventoryItem,
} from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Spinner } from "@/components/ui/spinner";
import { useConfirm } from "@/components/ui/confirm";

type FormState = { analyzed_on: string; note: string } & Record<BottleAnalysisField, string>;

function todayISO(): string {
  const d = new Date();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${d.getFullYear()}-${m}-${day}`;
}

function emptyForm(): FormState {
  const blanks = Object.fromEntries(BOTTLE_ANALYSIS_FIELDS.map((f) => [f, ""])) as Record<
    BottleAnalysisField,
    string
  >;
  return { analyzed_on: todayISO(), note: "", ...blanks };
}

export function AnalysisSection({ item, canManage }: { item: InventoryItem; canManage: boolean }) {
  const { t } = useTranslation();
  const { date, number } = useFormatters();
  const confirm = useConfirm();
  const listQ = useBottleAnalyses(item.id);
  const create = useCreateBottleAnalysis(item.id);
  const remove = useDeleteBottleAnalysis(item.id);

  const [adding, setAdding] = React.useState(false);
  const [form, setForm] = React.useState<FormState>(emptyForm);
  const [error, setError] = React.useState<string | null>(null);

  const analyses = listQ.data ?? [];

  function setField(key: keyof FormState, value: string) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  function startAdd() {
    setForm(emptyForm());
    setError(null);
    setAdding(true);
  }

  async function save(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    const input: BottleAnalysisInput = {
      analyzed_on: form.analyzed_on,
      note: form.note.trim() || null,
    };
    for (const f of BOTTLE_ANALYSIS_FIELDS) {
      const v = form[f].trim();
      if (v !== "" && Number.isFinite(Number(v))) input[f] = Number(v);
    }
    try {
      await create.mutateAsync(input);
      setAdding(false);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("inventory.analysis.errorGeneric"));
    }
  }

  async function del(id: string, on: string) {
    const ok = await confirm({
      title: t("inventory.analysis.removeTitle"),
      description: t("inventory.analysis.removeBody", { date: date(on) }),
      confirmLabel: t("inventory.analysis.remove"),
      tone: "danger",
    });
    if (ok) await remove.mutateAsync(id);
  }

  return (
    <Card>
      <CardContent className="space-y-4 pt-6">
        <div className="flex items-center justify-between">
          <h3 className="text-sm font-semibold">{t("inventory.analysis.sectionTitle")}</h3>
          {canManage &&
            (adding ? (
              <Button type="button" variant="outline" size="sm" onClick={() => setAdding(false)}>
                {t("inventory.analysis.cancel")}
              </Button>
            ) : (
              <Button type="button" size="sm" onClick={startAdd}>
                <Plus className="size-4" />
                {t("inventory.analysis.add")}
              </Button>
            ))}
        </div>

        {adding && (
          <form onSubmit={save} className="space-y-3 rounded-md border border-border bg-muted/30 p-3">
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
              <div className="space-y-1">
                <Label htmlFor="ba-date">{t("inventory.analysis.dateLabel")}</Label>
                <Input
                  id="ba-date"
                  type="date"
                  value={form.analyzed_on}
                  onChange={(e) => setField("analyzed_on", e.target.value)}
                  required
                />
              </div>
              {BOTTLE_ANALYSIS_FIELDS.map((f) => (
                <div key={f} className="space-y-1">
                  <Label htmlFor={`ba-${f}`}>{t(`inventory.analysis.fields.${f}`)}</Label>
                  <Input
                    id={`ba-${f}`}
                    type="number"
                    step="any"
                    value={form[f]}
                    onChange={(e) => setField(f, e.target.value)}
                    placeholder="—"
                  />
                </div>
              ))}
            </div>
            <div className="space-y-1">
              <Label htmlFor="ba-note">{t("inventory.analysis.noteLabel")}</Label>
              <Input
                id="ba-note"
                value={form.note}
                onChange={(e) => setField("note", e.target.value)}
                placeholder={t("inventory.analysis.notePlaceholder")}
              />
            </div>
            {error && <p className="text-sm text-destructive">{error}</p>}
            <div className="flex justify-end gap-2">
              <Button type="button" variant="outline" onClick={() => setAdding(false)}>
                {t("inventory.analysis.cancel")}
              </Button>
              <Button type="submit" disabled={create.isPending}>
                {create.isPending && <Spinner />}
                {t("inventory.analysis.save")}
              </Button>
            </div>
          </form>
        )}

        {listQ.isLoading ? (
          <div className="flex justify-center py-6">
            <Spinner className="size-5 text-muted-foreground" />
          </div>
        ) : analyses.length === 0 ? (
          <p className="py-2 text-sm text-muted-foreground">{t("inventory.analysis.empty")}</p>
        ) : (
          <ul className="space-y-2">
            {analyses.map((a) => {
              const recorded = BOTTLE_ANALYSIS_FIELDS.filter((f) => a[f] !== null);
              return (
                <li key={a.id} className="rounded-md border border-border p-3">
                  <div className="flex items-start justify-between gap-3">
                    <p className="text-sm font-medium">{date(a.analyzed_on)}</p>
                    {canManage && (
                      <button
                        type="button"
                        onClick={() => del(a.id, a.analyzed_on)}
                        aria-label={t("inventory.analysis.remove")}
                        className="text-muted-foreground transition-colors hover:text-destructive"
                      >
                        <Trash2 className="size-4" />
                      </button>
                    )}
                  </div>
                  {recorded.length > 0 && (
                    <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                      {recorded.map((f) => (
                        <span key={f}>
                          {t(`inventory.analysis.fields.${f}`)}:{" "}
                          <span className="font-medium tabular-nums text-foreground">
                            {number(a[f]!)}
                          </span>
                        </span>
                      ))}
                    </div>
                  )}
                  {a.note && <p className="mt-1 text-xs text-muted-foreground">{a.note}</p>}
                </li>
              );
            })}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}
