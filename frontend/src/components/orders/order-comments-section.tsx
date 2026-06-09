"use client";

import * as React from "react";
import { AtSign, Trash2, X } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useAddComment, useDeleteComment, useUpdateComment } from "@/hooks/use-orders";
import { useMembers } from "@/hooks/use-team";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { Order, OrderComment } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";
import { useConfirm } from "@/components/ui/confirm";

export function OrderCommentsSection({ order }: { order: Order }) {
  const { t } = useTranslation();
  const { dateTime } = useFormatters();
  const add = useAddComment(order.id);

  const [content, setContent] = React.useState("");
  const [mentions, setMentions] = React.useState<string[]>([]);
  const [error, setError] = React.useState<string | null>(null);

  async function submit() {
    if (!content.trim()) return;
    setError(null);
    try {
      await add.mutateAsync({ content: content.trim(), ...(mentions.length ? { mentions } : {}) });
      setContent("");
      setMentions([]);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("orders.comments.errorGeneric"));
    }
  }

  return (
    <Card>
      <CardContent className="space-y-4 pt-6">
        {order.comments.length === 0 ? (
          <p className="py-4 text-center text-sm text-muted-foreground">{t("orders.comments.empty")}</p>
        ) : (
          <ul className="space-y-3">
            {order.comments.map((comment) => (
              <CommentRow key={comment.id} orderId={order.id} comment={comment} dateTime={dateTime} />
            ))}
          </ul>
        )}

        <div className="space-y-2 border-t border-border pt-4">
          <Input
            value={content}
            onChange={(e) => setContent(e.target.value)}
            placeholder={t("orders.comments.placeholder")}
          />
          <MentionRow mentions={mentions} onChange={setMentions} />
          {error && <p className="text-sm text-destructive">{error}</p>}
          <div className="flex justify-end">
            <Button type="button" size="sm" onClick={submit} disabled={add.isPending || !content.trim()}>
              {add.isPending && <Spinner />}
              {t("orders.comments.add")}
            </Button>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

function MentionRow({
  mentions,
  onChange,
}: {
  mentions: string[];
  onChange: (m: string[]) => void;
}) {
  const { t } = useTranslation();
  const membersQ = useMembers();
  const [open, setOpen] = React.useState(false);
  const members = membersQ.data ?? [];
  const named = (id: string) => members.find((m) => m.user_id === id)?.name ?? id;

  return (
    <div className="flex flex-wrap items-center gap-2">
      <div className="relative">
        <Button type="button" variant="outline" size="sm" onClick={() => setOpen((o) => !o)}>
          <AtSign className="size-3.5" />
          {t("orders.comments.mention")}
        </Button>
        {open && (
          <div className="absolute z-30 mt-1 max-h-48 w-56 overflow-auto rounded-md border border-border bg-popover p-1 shadow-md">
            {members.map((m) => (
              <button
                key={m.user_id}
                type="button"
                className="block w-full rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent"
                onClick={() => {
                  if (!mentions.includes(m.user_id)) onChange([...mentions, m.user_id]);
                  setOpen(false);
                }}
              >
                {m.name}
              </button>
            ))}
          </div>
        )}
      </div>
      {mentions.length > 0 && (
        <span className="text-xs text-muted-foreground">{t("orders.comments.mentioning")}</span>
      )}
      {mentions.map((id) => (
        <span
          key={id}
          className="inline-flex items-center gap-1 rounded-full bg-accent px-2 py-0.5 text-xs"
        >
          {named(id)}
          <button type="button" onClick={() => onChange(mentions.filter((m) => m !== id))} aria-label="remove">
            <X className="size-3" />
          </button>
        </span>
      ))}
    </div>
  );
}

function CommentRow({
  orderId,
  comment,
  dateTime,
}: {
  orderId: string;
  comment: OrderComment;
  dateTime: (v: string) => string;
}) {
  const { t } = useTranslation();
  const { user, hasRole } = useAuth();
  const confirm = useConfirm();
  const update = useUpdateComment(orderId);
  const remove = useDeleteComment(orderId);

  const canModify = hasRole("ADMIN") || comment.author?.id === user?.id;
  const [editing, setEditing] = React.useState(false);
  const [draft, setDraft] = React.useState(comment.content);

  async function saveEdit() {
    await update.mutateAsync({ commentId: comment.id, content: draft.trim() });
    setEditing(false);
  }
  async function handleDelete() {
    const ok = await confirm({
      title: t("orders.comments.deleteConfirmTitle"),
      description: t("orders.comments.deleteConfirmBody"),
      confirmLabel: t("orders.comments.delete"),
      tone: "danger",
    });
    if (!ok) return;
    await remove.mutateAsync(comment.id);
  }

  return (
    <li className="rounded-lg border border-border p-3">
      <div className="mb-1 flex items-center justify-between gap-2">
        <span className="text-sm font-medium">{comment.author?.name ?? "—"}</span>
        <span className="text-xs text-muted-foreground">
          {comment.created_at ? dateTime(comment.created_at) : ""}
        </span>
      </div>
      {editing ? (
        <div className="space-y-2">
          <Input value={draft} onChange={(e) => setDraft(e.target.value)} />
          <div className="flex justify-end gap-2">
            <Button type="button" variant="ghost" size="sm" onClick={() => setEditing(false)}>
              {t("orders.comments.cancel")}
            </Button>
            <Button type="button" size="sm" onClick={saveEdit} disabled={update.isPending}>
              {t("orders.comments.save")}
            </Button>
          </div>
        </div>
      ) : (
        <>
          <p className="whitespace-pre-wrap text-sm">{comment.content}</p>
          {canModify && (
            <div className="mt-2 flex gap-2">
              <button
                type="button"
                className="text-xs text-muted-foreground hover:text-foreground"
                onClick={() => {
                  setDraft(comment.content);
                  setEditing(true);
                }}
              >
                {t("orders.comments.edit")}
              </button>
              <button
                type="button"
                className="inline-flex items-center gap-1 text-xs text-destructive hover:underline"
                onClick={handleDelete}
              >
                <Trash2 className="size-3" />
                {t("orders.comments.delete")}
              </button>
            </div>
          )}
        </>
      )}
    </li>
  );
}
