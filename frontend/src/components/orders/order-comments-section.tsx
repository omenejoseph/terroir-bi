"use client";

import * as React from "react";
import { Trash2 } from "lucide-react";

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
import { MentionInput } from "@/components/ui/mention-input";
import { Spinner } from "@/components/ui/spinner";
import { useConfirm } from "@/components/ui/confirm";

export function OrderCommentsSection({ order }: { order: Order }) {
  const { t } = useTranslation();
  const { dateTime } = useFormatters();
  const add = useAddComment(order.id);
  const members = useMembers().data ?? [];
  const named = (id: string) => members.find((m) => m.user_id === id)?.name ?? id;

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
          <MentionInput
            value={content}
            onChange={setContent}
            onMentionsChange={setMentions}
            members={members}
            placeholder={t("orders.comments.placeholder")}
            aria-label={t("orders.comments.placeholder")}
          />
          {mentions.length > 0 && (
            <p className="text-xs text-muted-foreground">
              {t("orders.comments.mentioning")} {mentions.map(named).join(", ")}
            </p>
          )}
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
