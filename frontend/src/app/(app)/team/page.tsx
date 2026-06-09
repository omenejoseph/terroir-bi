"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { ChevronDown, Pencil, Plus, RefreshCw, Trash2 } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import {
  useInvitations,
  useInvite,
  useMembers,
  useRemoveMember,
  useRevokeInvitation,
} from "@/hooks/use-team";
import { useTranslation } from "@/i18n/context";
import type { Invitation, Member } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { InviteLink } from "@/components/team/invite-link";
import { InvitationForm } from "@/components/team/invitation-form";
import { MemberForm } from "@/components/team/member-form";

export default function TeamPage() {
  const { t, locale } = useTranslation();
  const { can } = useAuth();
  const router = useRouter();
  const membersQ = useMembers();
  const invitationsQ = useInvitations();

  const canManage = can("members.manage");
  const canInvite = can("invitations.manage");

  const members = membersQ.data ?? [];
  const invitations = invitationsQ.data ?? [];

  const dateFmt = new Intl.DateTimeFormat(locale, { dateStyle: "medium" });

  return (
    <div className="space-y-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold tracking-tight">{t("team.title")}</h1>
          <p className="text-sm text-muted-foreground">{t("team.subtitle")}</p>
        </div>
        {canInvite && (
          <Button onClick={() => router.push("/team/invite")} className="shrink-0">
            <Plus className="size-4" />
            {t("team.invite")}
          </Button>
        )}
      </header>

      {membersQ.isLoading ? (
        <div className="flex items-center justify-center py-16">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      ) : membersQ.isError ? (
        <Card>
          <CardContent className="py-8 text-center text-sm text-destructive">
            {membersQ.error instanceof ApiError && membersQ.error.status === 403
              ? t("team.errorForbidden")
              : t("team.errorGeneric")}
          </CardContent>
        </Card>
      ) : (
        <section className="space-y-2">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
            {t("team.membersTitle")}
          </h2>
          {members.length === 0 ? (
            <Card>
              <CardContent className="py-8 text-center text-sm text-muted-foreground">
                {t("team.empty")}
              </CardContent>
            </Card>
          ) : (
            <div className="space-y-2">
              {members.map((member) => (
                <MemberCard key={member.id} member={member} canManage={canManage} />
              ))}
            </div>
          )}
        </section>
      )}

      {/* Pending invitations */}
      {canInvite && (
        <section className="space-y-2">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
            {t("team.invitationsTitle")}
          </h2>
          {invitationsQ.isLoading ? (
            <div className="flex justify-center py-6">
              <Spinner className="size-5 text-muted-foreground" />
            </div>
          ) : invitations.length === 0 ? (
            <Card>
              <CardContent className="py-8 text-center text-sm text-muted-foreground">
                {t("team.noInvitations")}
              </CardContent>
            </Card>
          ) : (
            <div className="space-y-2">
              {invitations.map((invitation) => (
                <InvitationCard
                  key={invitation.id}
                  invitation={invitation}
                  expires={dateFmt.format(new Date(invitation.expires_at))}
                />
              ))}
            </div>
          )}
        </section>
      )}
    </div>
  );
}

function RoleBadges({ roles }: { roles: string[] }) {
  const { t } = useTranslation();
  return (
    <div className="flex flex-wrap gap-1">
      {roles.map((role) => (
        <Badge key={role} variant="secondary">
          {t(`team.roles.${role}`)}
        </Badge>
      ))}
    </div>
  );
}

/** Header row shared by member/invitation cards — toggles the panel. */
function CardHeader({
  open,
  onToggle,
  title,
  subtitle,
  children,
}: {
  open: boolean;
  onToggle: () => void;
  title: string;
  subtitle: string;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onToggle}
      aria-expanded={open}
      className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/40"
    >
      <div className="min-w-0">
        <p className="truncate font-medium">{title}</p>
        <p className="truncate text-xs text-muted-foreground">{subtitle}</p>
      </div>
      <div className="flex shrink-0 items-center gap-2">
        {children}
        <ChevronDown
          className={`size-4 text-muted-foreground transition-transform duration-300 ${open ? "rotate-180" : ""}`}
        />
      </div>
    </button>
  );
}

/** Wrapper providing the slick expand/collapse animation. */
function Panel({ open, children }: { open: boolean; children: React.ReactNode }) {
  return (
    <div
      className={`grid transition-all duration-300 ease-out ${
        open ? "grid-rows-[1fr] opacity-100" : "grid-rows-[0fr] opacity-0"
      }`}
    >
      <div className="overflow-hidden">
        <div className="border-t border-border px-4 py-4">{open ? children : null}</div>
      </div>
    </div>
  );
}

function MemberCard({ member, canManage }: { member: Member; canManage: boolean }) {
  const { t } = useTranslation();
  const [open, setOpen] = React.useState(false);
  const [editing, setEditing] = React.useState(false);

  function toggle() {
    setOpen((prev) => {
      if (prev) setEditing(false);
      return !prev;
    });
  }

  // Without manage permission the row is read-only and not expandable.
  if (!canManage) {
    return (
      <Card>
        <div className="flex items-center justify-between gap-3 px-4 py-3">
          <div className="min-w-0">
            <p className="truncate font-medium">{member.name || member.email}</p>
            <p className="truncate text-xs text-muted-foreground">{member.email}</p>
          </div>
          <div className="flex shrink-0 items-center gap-2">
            <RoleBadges roles={member.roles} />
            <Badge variant={member.status === "active" ? "success" : "secondary"}>
              {t(`team.status.${member.status}`)}
            </Badge>
          </div>
        </div>
      </Card>
    );
  }

  return (
    <Card className="overflow-hidden">
      <CardHeader
        open={open}
        onToggle={toggle}
        title={member.name || member.email}
        subtitle={member.email}
      >
        <RoleBadges roles={member.roles} />
        <Badge variant={member.status === "active" ? "success" : "secondary"}>
          {t(`team.status.${member.status}`)}
        </Badge>
      </CardHeader>

      <Panel open={open}>
        {editing ? (
          <MemberForm
            member={member}
            onSaved={() => setEditing(false)}
            onCancel={() => setEditing(false)}
            onRemoved={() => setOpen(false)}
            bare
          />
        ) : (
          <div className="space-y-4">
            <dl className="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
              <Detail label={t("team.member.rolesLabel")}>
                <RoleBadges roles={member.roles} />
              </Detail>
              <Detail label={t("team.member.statusLabel")}>
                <span className="text-sm">{t(`team.status.${member.status}`)}</span>
              </Detail>
            </dl>
            <MemberActions member={member} onEdit={() => setEditing(true)} onRemoved={() => setOpen(false)} />
          </div>
        )}
      </Panel>
    </Card>
  );
}

function MemberActions({
  member,
  onEdit,
  onRemoved,
}: {
  member: Member;
  onEdit: () => void;
  onRemoved: () => void;
}) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const remove = useRemoveMember();

  async function handleRemove() {
    try {
      await remove.mutateAsync(member.user_id);
      onRemoved();
    } catch {
      /* surfaced on the edit form; ignore here */
    }
  }

  return (
    <div className="flex flex-wrap justify-end gap-2 border-t border-border pt-3">
      <Button variant="outline" size="sm" onClick={onEdit}>
        <Pencil className="size-3.5" />
        {t("team.edit")}
      </Button>
      {can("members.manage") && (
        <Button
          variant="ghost"
          size="sm"
          className="text-destructive hover:bg-destructive/10 hover:text-destructive"
          onClick={handleRemove}
          disabled={remove.isPending}
        >
          {remove.isPending ? <Spinner /> : <Trash2 className="size-3.5" />}
          {t("team.member.remove")}
        </Button>
      )}
    </div>
  );
}

function InvitationCard({ invitation, expires }: { invitation: Invitation; expires: string }) {
  const { t } = useTranslation();
  const [open, setOpen] = React.useState(false);
  const [editing, setEditing] = React.useState(false);

  function toggle() {
    setOpen((prev) => {
      if (prev) setEditing(false);
      return !prev;
    });
  }

  return (
    <Card className="overflow-hidden">
      <CardHeader
        open={open}
        onToggle={toggle}
        title={invitation.email}
        subtitle={t("team.expires", { date: expires })}
      >
        <RoleBadges roles={invitation.roles} />
      </CardHeader>

      <Panel open={open}>
        {editing ? (
          <InvitationForm
            invitation={invitation}
            onDone={() => setEditing(false)}
            onRevoked={() => setOpen(false)}
            bare
          />
        ) : (
          <div className="space-y-4">
            <dl className="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
              <Detail label={t("team.invitePage.email")}>
                <span className="text-sm">{invitation.email}</span>
              </Detail>
              <Detail label={t("team.invitePage.rolesLabel")}>
                <RoleBadges roles={invitation.roles} />
              </Detail>
              <Detail label={t("team.expiresLabel")}>
                <span className="text-sm">{expires}</span>
              </Detail>
            </dl>
            <InvitationActions invitation={invitation} onEdit={() => setEditing(true)} onRevoked={() => setOpen(false)} />
          </div>
        )}
      </Panel>
    </Card>
  );
}

function InvitationActions({
  invitation,
  onEdit,
  onRevoked,
}: {
  invitation: Invitation;
  onEdit: () => void;
  onRevoked: () => void;
}) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const invite = useInvite();
  const revoke = useRevokeInvitation();
  const [token, setToken] = React.useState<string | null>(null);

  const canManage = can("invitations.manage");

  async function handleRegenerate() {
    try {
      const updated = await invite.mutateAsync({ email: invitation.email, roles: invitation.roles });
      setToken(updated.accept_token ?? "");
    } catch {
      /* ignore — re-issue is best effort here */
    }
  }

  async function handleRevoke() {
    try {
      await revoke.mutateAsync(invitation.id);
      onRevoked();
    } catch {
      /* ignore */
    }
  }

  return (
    <div className="space-y-3">
      {token !== null && <InviteLink token={token} />}
      <div className="flex flex-wrap justify-end gap-2 border-t border-border pt-3">
        <Button variant="outline" size="sm" onClick={onEdit}>
          <Pencil className="size-3.5" />
          {t("team.edit")}
        </Button>
        {canManage && (
          <Button variant="outline" size="sm" onClick={handleRegenerate} disabled={invite.isPending}>
            {invite.isPending ? <Spinner /> : <RefreshCw className="size-3.5" />}
            {t("team.regenerate")}
          </Button>
        )}
        {canManage && (
          <Button
            variant="ghost"
            size="sm"
            className="text-destructive hover:bg-destructive/10 hover:text-destructive"
            onClick={handleRevoke}
            disabled={revoke.isPending}
          >
            {revoke.isPending ? <Spinner /> : <Trash2 className="size-3.5" />}
            {t("team.revoke")}
          </Button>
        )}
      </div>
    </div>
  );
}

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="space-y-1">
      <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd>{children}</dd>
    </div>
  );
}