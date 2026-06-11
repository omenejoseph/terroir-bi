"use client";

import * as React from "react";

import { ApiError } from "@/lib/api/client";
import { useMergeSuppliers, useSupplierMergePreview, useSuppliers } from "@/hooks/use-suppliers";
import { useTranslation } from "@/i18n/context";
import type { SupplierMergePreview } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";

export function SupplierMergeDialog({
  open,
  onOpenChange,
  onMerged,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onMerged: (message: string) => void;
}) {
  const { t } = useTranslation();
  const previewMutation = useSupplierMergePreview();
  const mergeMutation = useMergeSuppliers();

  const [search, setSearch] = React.useState("");
  const [debounced, setDebounced] = React.useState("");
  const [selected, setSelected] = React.useState<Set<string>>(new Set());
  const [keepId, setKeepId] = React.useState<string | null>(null);
  const [preview, setPreview] = React.useState<SupplierMergePreview | null>(null);
  const [error, setError] = React.useState<string | null>(null);

  React.useEffect(() => {
    if (open) {
      setSearch("");
      setDebounced("");
      setSelected(new Set());
      setKeepId(null);
      setPreview(null);
      setError(null);
    }
  }, [open]);

  React.useEffect(() => {
    const id = setTimeout(() => setDebounced(search.trim()), 300);
    return () => clearTimeout(id);
  }, [search]);

  const listQ = useSuppliers(debounced ? { search: debounced } : {});
  const rows = listQ.data?.data ?? [];

  function toggleSelect(id: string) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
        if (keepId === id) setKeepId(null);
      } else {
        next.add(id);
      }
      return next;
    });
  }

  function chooseKeep(id: string) {
    setKeepId(id);
    setSelected((prev) => new Set(prev).add(id)); // keeping implies selecting
  }

  const loserIds = [...selected].filter((id) => id !== keepId);
  const canPreview = keepId !== null && loserIds.length >= 1;

  async function onPreview() {
    if (!canPreview || keepId === null) return;
    setError(null);
    try {
      const result = await previewMutation.mutateAsync({ winner_id: keepId, loser_ids: loserIds });
      setPreview(result);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("suppliers.merge.errorGeneric"));
    }
  }

  async function onConfirm() {
    if (keepId === null) return;
    setError(null);
    try {
      const result = await mergeMutation.mutateAsync({ winner_id: keepId, loser_ids: loserIds });
      onMerged(
        t("suppliers.merge.applied", {
          count: result.totals.losers_deleted,
          winner: result.winner.company_name,
        }),
      );
      onOpenChange(false);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("suppliers.merge.errorGeneric"));
    }
  }

  return (
    <Dialog
      open={open}
      onOpenChange={onOpenChange}
      title={t("suppliers.merge.title")}
      description={t("suppliers.merge.description")}
    >
      {preview ? (
        <div className="space-y-4">
          <p className="text-sm">
            {t("suppliers.merge.previewIntro", {
              winner: preview.winner.company_name,
              count: preview.losers.length,
            })}
          </p>
          <ul className="space-y-1 rounded-md border border-border p-3 text-sm">
            {preview.losers.map((l) => (
              <li key={l.id} className="flex items-center justify-between gap-3">
                <span className="truncate">{l.company_name}</span>
                <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                  {l.orders} · {l.costs} · {l.price_reassign} / {l.price_drop}
                </span>
              </li>
            ))}
          </ul>
          <p className="text-xs text-muted-foreground">
            {t("suppliers.merge.summary", {
              orders: preview.totals.orders,
              costs: preview.totals.costs,
              reassign: preview.totals.price_reassign,
              drop: preview.totals.price_drop,
            })}
          </p>
          {error && <p className="text-sm text-destructive">{error}</p>}
          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => setPreview(null)}>
              {t("suppliers.merge.back")}
            </Button>
            <Button type="button" onClick={onConfirm} disabled={mergeMutation.isPending}>
              {mergeMutation.isPending && <Spinner />}
              {t("suppliers.merge.confirm")}
            </Button>
          </div>
        </div>
      ) : (
        <div className="space-y-3">
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t("suppliers.merge.search")}
          />

          <div className="max-h-80 overflow-y-auto rounded-md border border-border">
            <table className="w-full text-sm">
              <thead className="sticky top-0 border-b border-border bg-card text-left text-xs uppercase text-muted-foreground">
                <tr>
                  <th className="px-3 py-2 font-medium">{t("suppliers.merge.colSelect")}</th>
                  <th className="px-3 py-2 font-medium">{t("suppliers.merge.colKeep")}</th>
                  <th className="px-3 py-2 font-medium">{t("suppliers.merge.colSupplier")}</th>
                  <th className="px-3 py-2 font-medium">{t("suppliers.merge.colEmail")}</th>
                </tr>
              </thead>
              <tbody>
                {listQ.isLoading ? (
                  <tr>
                    <td colSpan={4} className="py-6 text-center">
                      <Spinner className="size-5 text-muted-foreground" />
                    </td>
                  </tr>
                ) : rows.length === 0 ? (
                  <tr>
                    <td colSpan={4} className="py-6 text-center text-muted-foreground">
                      {t("suppliers.merge.empty")}
                    </td>
                  </tr>
                ) : (
                  rows.map((s) => (
                    <tr key={s.id} className="border-b border-border/60 last:border-0">
                      <td className="px-3 py-2">
                        <Checkbox
                          checked={selected.has(s.id)}
                          onChange={() => toggleSelect(s.id)}
                          aria-label={`Select ${s.company_name}`}
                        />
                      </td>
                      <td className="px-3 py-2">
                        <input
                          type="radio"
                          name="merge-keep"
                          checked={keepId === s.id}
                          onChange={() => chooseKeep(s.id)}
                          aria-label={`Keep ${s.company_name}`}
                          className="size-4 accent-primary"
                        />
                      </td>
                      <td className="px-3 py-2">
                        <span className="font-medium">{s.company_name}</span>
                        {s.contact_name && (
                          <span className="block text-xs text-muted-foreground">{s.contact_name}</span>
                        )}
                      </td>
                      <td className="px-3 py-2 text-muted-foreground">{s.email ?? ""}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          {error && <p className="text-sm text-destructive">{error}</p>}

          <div className="flex items-center justify-between gap-2">
            <p className="text-xs text-muted-foreground">{t("suppliers.merge.needSelection")}</p>
            <div className="flex gap-2">
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                {t("suppliers.merge.cancel")}
              </Button>
              <Button
                type="button"
                onClick={onPreview}
                disabled={!canPreview || previewMutation.isPending}
              >
                {previewMutation.isPending && <Spinner />}
                {t("suppliers.merge.preview")}
              </Button>
            </div>
          </div>
        </div>
      )}
    </Dialog>
  );
}
