"use client";

import * as React from "react";
import { Check, Trash2 } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useInvite, useRevokeInvitation } from "@/hooks/use-team";
import { useTranslation } from "@/i18n/context";
import type { Invitation } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Spinner } from "@/components/ui/spinner";
import { InviteLink } from "@/components/team/invite-link";
import { RoleCheckboxes } from "@/components/team/role-checkboxes";

export function InvitationForm({
  invitation,
  onDone,
  onRevoked,
  bare = false,
}: {
  invitation: Invitation;
  onDone: () => void;
  onRevoked?: () => void;
  /** Render without the surrounding Card (e.g. inside an expandable panel). */
  bare?: boolean;
}) {
  const { t } = useTranslation();
  const invite = useInvite();
  const revoke = useRevokeInvitation();

  const [email, setEmail] = React.useState(invitation.email);
  const [roles, setRoles] = React.useState<string[]>(invitation.roles);
  const [errors, setErrors] = React.useState<Record<string, string>>({});
  const [error, setError] = React.useState<string | null>(null);
  const [newToken, setNewToken] = React.useState<string | null>(null);

  React.useEffect(() => {
    setEmail(invitation.email);
    setRoles(invitation.roles);
  }, [invitation]);

  async function save(event: React.SyntheticEvent) {
    event.preventDefault();
    setErrors({});
    setError(null);
    try {
      // Changing the email creates a new invitation row, so revoke the old one.
      const emailChanged = email.trim().toLowerCase() !== invitation.email.toLowerCase();
      if (emailChanged) {
        await revoke.mutateAsync(invitation.id);
      }
      // Re-inviting refreshes roles + issues a new accept token (server upserts by email).
      const updated = await invite.mutateAsync({ email: email.trim(), roles });
      setNewToken(updated.accept_token ?? "");
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        const flat: Record<string, string> = {};
        for (const [field, messages] of Object.entries(err.errors)) {
          if (messages[0]) flat[field] = messages[0];
        }
        setErrors(flat);
        setError(err.message);
      } else {
        setError(t("team.invitation.errorGeneric"));
      }
    }
  }

  async function handleRevoke() {
    setError(null);
    try {
      await revoke.mutateAsync(invitation.id);
      onRevoked?.();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("team.invitation.errorGeneric"));
    }
  }

  const body =
    newToken !== null ? (
      <div className="space-y-4">
        <div className="flex items-center gap-2 text-sm font-medium text-success">
          <Check className="size-4" />
          {t("team.invitation.updatedTitle")}
        </div>
        <p className="text-sm text-muted-foreground">{t("team.invitation.updatedDesc")}</p>
        <InviteLink token={newToken} />
        <div className="flex justify-end">
          <Button type="button" onClick={onDone}>
            {t("team.invitation.done")}
          </Button>
        </div>
      </div>
    ) : (
      <form onSubmit={save} className="space-y-4">
        <div className="space-y-2">
          <Label htmlFor="invitation_email">{t("team.invitePage.email")}</Label>
          <Input
            id="invitation_email"
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            placeholder={t("team.invitePage.emailPlaceholder")}
            required
          />
          {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
        </div>

        <div className="space-y-2">
          <Label>{t("team.invitePage.rolesLabel")}</Label>
          <RoleCheckboxes value={roles} onChange={setRoles} />
          {errors.roles && <p className="text-sm text-destructive">{errors.roles}</p>}
        </div>

        {error && (
          <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</p>
        )}

        <div className="flex items-center justify-between gap-2 border-t border-border pt-4">
          <Button
            type="button"
            variant="ghost"
            className="text-destructive hover:bg-destructive/10 hover:text-destructive"
            onClick={handleRevoke}
            disabled={revoke.isPending}
          >
            {revoke.isPending ? <Spinner /> : <Trash2 className="size-4" />}
            {t("team.invitation.revoke")}
          </Button>
          <Button type="submit" disabled={invite.isPending || roles.length === 0}>
            {invite.isPending && <Spinner />}
            {t("team.invitation.save")}
          </Button>
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