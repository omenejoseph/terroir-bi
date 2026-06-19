"use client";

import * as React from "react";
import { Check, Pencil, Plus, X } from "lucide-react";

import { useUpdateNotes } from "@/hooks/use-orders";
import { useTranslation } from "@/i18n/context";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";

/**
 * The order-level free-text note (`order.notes`), shown and edited inline on the
 * order detail page. Mirrors the prototype's EditableOrderNote: an "add note"
 * affordance when empty, otherwise a card with click-to-edit.
 */
export function EditableOrderNote({
  orderId,
  note,
  canManage,
}: {
  orderId: string;
  note: string | null;
  canManage: boolean;
}) {
  const { t } = useTranslation();
  const update = useUpdateNotes(orderId);
  const [editing, setEditing] = React.useState(false);
  const [draft, setDraft] = React.useState(note ?? "");

  async function save() {
    await update.mutateAsync(draft.trim() || null);
    setEditing(false);
  }

  // Empty and not editing → compact "add note" affordance (managers only).
  if (!note && !editing) {
    if (!canManage) return null;
    return (
      <button
        type="button"
        onClick={() => {
          setDraft("");
          setEditing(true);
        }}
        className="flex items-center gap-1.5 text-xs text-muted-foreground transition-colors hover:text-foreground"
      >
        <Plus className="size-3.5" />
        {t("orders.note.add")}
      </button>
    );
  }

  return (
    <Card>
      <CardContent className="space-y-2 pt-6">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-semibold">{t("orders.note.title")}</h2>
          {canManage && !editing && (
            <Button
              variant="ghost"
              size="icon"
              className="size-7 text-muted-foreground"
              onClick={() => {
                setDraft(note ?? "");
                setEditing(true);
              }}
              aria-label={t("orders.details.edit")}
            >
              <Pencil className="size-3.5" />
            </Button>
          )}
        </div>
        {editing ? (
          <div className="space-y-2">
            <textarea
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
              rows={3}
              autoFocus
              placeholder={t("orders.note.placeholder")}
              className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
            <div className="flex gap-2">
              <Button type="button" size="sm" onClick={save} disabled={update.isPending}>
                {update.isPending ? <Spinner /> : <Check className="size-3.5" />}
                {t("orders.details.save")}
              </Button>
              <Button
                type="button"
                size="sm"
                variant="ghost"
                onClick={() => setEditing(false)}
                disabled={update.isPending}
              >
                <X className="size-3.5" />
                {t("orders.form.cancel")}
              </Button>
            </div>
          </div>
        ) : (
          <p className="whitespace-pre-wrap text-sm">{note}</p>
        )}
      </CardContent>
    </Card>
  );
}