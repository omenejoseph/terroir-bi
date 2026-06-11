"use client";

import * as React from "react";

import { ApiError } from "@/lib/api/client";
import { useCustomers, useMergeCustomers, useMergePreview } from "@/hooks/use-customers";
import { useTranslation } from "@/i18n/context";
import type { MergePreview } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";

export function CustomerMergeDialog({
  open,
  onOpenChange,
  onMerged,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onMerged: (message: string) => void;
}) {
  const { t } = useTranslation();
  const previewMutation = useMergePreview();
  const mergeMutation = useMergeCustomers();

  const [search, setSearch] = React.useState("");
  const [debounced, setDebounced] = React.useState("");
  const [selected, setSelected] = React.useState<Set<string>>(new Set());
  const [keepId, setKeepId] = React.useState<string | null>(null);
  const [preview, setPreview] = React.useState<MergePreview | null>(null);
  const [error, setError] = React.useState<string | null>(null);

  // Reset whenever (re)opened.
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

  const listQ = useCustomers(debounced ? { search: debounced } : {});
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
      setError(err instanceof ApiError ? err.message : t("customers.merge.errorGeneric"));
    }
  }

  async function onConfirm() {
    if (keepId === null) return;
    setError(null);
    try {
      const result = await mergeMutation.mutateAsync({ winner_id: keepId, loser_ids: loserIds });
      onMerged(
        t("customers.merge.applied", {
          count: result.totals.losers_deleted,
          winner: result.winner.company_name,
        }),
      );
      onOpenChange(false);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("customers.merge.errorGeneric"));
    }
  }

  return (
    <Dialog
      open={open}
      onOpenChange={onOpenChange}
      title={t("customers.merge.title")}
      description={t("customers.merge.description")}
    >
      {preview ? (
        <div className="space-y-4">
          <p className="text-sm">
            {t("customers.merge.previewIntro", {
              winner: preview.winner.company_name,
              count: preview.losers.length,
            })}
          </p>
          <ul className="space-y-1 rounded-md border border-border p-3 text-sm">
            {preview.losers.map((l) => (
              <li key={l.id} className="flex items-center justify-between gap-3">
                <span className="truncate">{l.company_name}</span>
                <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                  {l.orders} · {l.price_reassign + l.override_reassign} / {l.price_drop + l.override_drop}
                </span>
              </li>
            ))}
          </ul>
          <p className="text-xs text-muted-foreground">
            {t("customers.merge.summary", {
              orders: preview.totals.orders,
              reassign: preview.totals.price_reassign + preview.totals.override_reassign,
              drop: preview.totals.price_drop + preview.totals.override_drop,
            })}
          </p>
          {error && <p className="text-sm text-destructive">{error}</p>}
          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => setPreview(null)}>
              {t("customers.merge.back")}
            </Button>
            <Button type="button" onClick={onConfirm} disabled={mergeMutation.isPending}>
              {mergeMutation.isPending && <Spinner />}
              {t("customers.merge.confirm")}
            </Button>
          </div>
        </div>
      ) : (
        <div className="space-y-3">
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t("customers.merge.search")}
          />

          <div className="max-h-80 overflow-y-auto rounded-md border border-border">
            <table className="w-full text-sm">
              <thead className="sticky top-0 border-b border-border bg-card text-left text-xs uppercase text-muted-foreground">
                <tr>
                  <th className="px-3 py-2 font-medium">{t("customers.merge.colSelect")}</th>
                  <th className="px-3 py-2 font-medium">{t("customers.merge.colKeep")}</th>
                  <th className="px-3 py-2 font-medium">{t("customers.merge.colCustomer")}</th>
                  <th className="px-3 py-2 font-medium">{t("customers.merge.colEmail")}</th>
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
                      {t("customers.merge.empty")}
                    </td>
                  </tr>
                ) : (
                  rows.map((c) => (
                    <tr key={c.id} className="border-b border-border/60 last:border-0">
                      <td className="px-3 py-2">
                        <Checkbox
                          checked={selected.has(c.id)}
                          onChange={() => toggleSelect(c.id)}
                          aria-label={`Select ${c.company_name}`}
                        />
                      </td>
                      <td className="px-3 py-2">
                        <input
                          type="radio"
                          name="merge-keep"
                          checked={keepId === c.id}
                          onChange={() => chooseKeep(c.id)}
                          aria-label={`Keep ${c.company_name}`}
                          className="size-4 accent-primary"
                        />
                      </td>
                      <td className="px-3 py-2">
                        <span className="font-medium">{c.company_name}</span>
                        {c.contact_name && (
                          <span className="block text-xs text-muted-foreground">{c.contact_name}</span>
                        )}
                      </td>
                      <td className="px-3 py-2 text-muted-foreground">{c.email}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          {error && <p className="text-sm text-destructive">{error}</p>}

          <div className="flex items-center justify-between gap-2">
            <p className="text-xs text-muted-foreground">{t("customers.merge.needSelection")}</p>
            <div className="flex gap-2">
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                {t("customers.merge.cancel")}
              </Button>
              <Button
                type="button"
                onClick={onPreview}
                disabled={!canPreview || previewMutation.isPending}
              >
                {previewMutation.isPending && <Spinner />}
                {t("customers.merge.preview")}
              </Button>
            </div>
          </div>
        </div>
      )}
    </Dialog>
  );
}
