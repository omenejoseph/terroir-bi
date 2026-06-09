"use client";

import * as React from "react";
import { Trash2 } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useRemoveMember, useUpdateMember } from "@/hooks/use-team";
import { useTranslation } from "@/i18n/context";
import type { Member, MembershipStatus } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { useConfirm } from "@/components/ui/confirm";
import { RoleCheckboxes } from "@/components/team/role-checkboxes";

export function MemberForm({
  member,
  onSaved,
  onCancel,
  onRemoved,
  bare = false,
}: {
  member: Member;
  onSaved: () => void;
  onCancel: () => void;
  onRemoved?: () => void;
  /** Render without the surrounding Card (e.g. inside an expandable panel). */
  bare?: boolean;
}) {
  const { t } = useTranslation();
  const confirm = useConfirm();
  const update = useUpdateMember();
  const remove = useRemoveMember();

  const [roles, setRoles] = React.useState<string[]>(member.roles);
  const [status, setStatus] = React.useState<MembershipStatus>(
    member.status === "suspended" ? "suspended" : "active",
  );
  const [error, setError] = React.useState<string | null>(null);

  // Re-sync when the target member changes.
  React.useEffect(() => {
    setRoles(member.roles);
    setStatus(member.status === "suspended" ? "suspended" : "active");
  }, [member]);

  async function save(event: React.SyntheticEvent) {
    event.preventDefault();
    setError(null);
    try {
      await update.mutateAsync({ userId: member.user_id, input: { roles, status } });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("team.member.errorGeneric"));
    }
  }

  async function handleRemove() {
    const ok = await confirm({
      title: t("team.member.removeConfirmTitle"),
      description: t("team.member.removeConfirmBody", { name: member.name || member.email }),
      confirmLabel: t("team.member.remove"),
      tone: "danger",
    });
    if (!ok) return;
    setError(null);
    try {
      await remove.mutateAsync(member.user_id);
      onRemoved?.();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("team.member.errorGeneric"));
    }
  }

  const body = (
    <form onSubmit={save} className="space-y-4">
      <div className="space-y-2">
        <Label>{t("team.member.rolesLabel")}</Label>
        <RoleCheckboxes value={roles} onChange={setRoles} />
      </div>

      <div className="space-y-2">
        <Label htmlFor="status">{t("team.member.statusLabel")}</Label>
        <Select
          id="status"
          value={status}
          onChange={(e) => setStatus(e.target.value as MembershipStatus)}
        >
          <option value="active">{t("team.status.active")}</option>
          <option value="suspended">{t("team.status.suspended")}</option>
        </Select>
      </div>

      {error && (
        <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</p>
      )}

      <div className="flex items-center justify-between gap-2 border-t border-border pt-4">
        <Button
          type="button"
          variant="ghost"
          className="text-destructive hover:bg-destructive/10 hover:text-destructive"
          onClick={handleRemove}
          disabled={remove.isPending}
        >
          {remove.isPending ? <Spinner /> : <Trash2 className="size-4" />}
          {t("team.member.remove")}
        </Button>
        <div className="flex gap-2">
          <Button type="button" variant="outline" onClick={onCancel}>
            {t("team.member.cancel")}
          </Button>
          <Button type="submit" disabled={update.isPending || roles.length === 0}>
            {update.isPending && <Spinner />}
            {t("team.member.save")}
          </Button>
        </div>
      </div>
    </form>
  );

  if (bare) return body;

  return (
    <Card>
      <CardContent className="pt-6">{body}</CardContent>
    </Card>
  );
}