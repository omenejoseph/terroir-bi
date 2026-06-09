"use client";

import * as React from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ArrowLeft, Check } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useInvite } from "@/hooks/use-team";
import { useTranslation } from "@/i18n/context";
import type { Invitation } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Spinner } from "@/components/ui/spinner";
import { InviteLink } from "@/components/team/invite-link";
import { RoleCheckboxes } from "@/components/team/role-checkboxes";

export default function InviteMemberPage() {
  const { t } = useTranslation();
  const router = useRouter();
  const invite = useInvite();

  const [email, setEmail] = React.useState("");
  const [roles, setRoles] = React.useState<string[]>([]);
  const [errors, setErrors] = React.useState<Record<string, string>>({});
  const [formError, setFormError] = React.useState<string | null>(null);
  const [created, setCreated] = React.useState<Invitation | null>(null);

  async function handleSubmit(event: React.SyntheticEvent) {
    event.preventDefault();
    setErrors({});
    setFormError(null);
    try {
      setCreated(await invite.mutateAsync({ email: email.trim(), roles }));
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        const flat: Record<string, string> = {};
        for (const [field, messages] of Object.entries(err.errors)) {
          if (messages[0]) flat[field] = messages[0];
        }
        setErrors(flat);
        setFormError(err.message);
      } else {
        setFormError(t("team.invitePage.errorGeneric"));
      }
    }
  }

  return (
    <div className="mx-auto max-w-xl space-y-6">
      <Link
        href="/team"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("team.back")}
      </Link>

      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("team.invitePage.title")}</h1>
        <p className="text-sm text-muted-foreground">{t("team.invitePage.subtitle")}</p>
      </div>

      {created ? (
        <Card>
          <CardContent className="space-y-4 pt-6">
            <div className="flex items-center gap-2 text-sm font-medium text-success">
              <Check className="size-4" />
              {t("team.invitePage.successTitle")}
            </div>
            <p className="text-sm text-muted-foreground">{t("team.invitePage.successDesc")}</p>
            <InviteLink token={created.accept_token ?? ""} />
            <div className="flex justify-end">
              <Button type="button" onClick={() => router.push("/team")}>
                {t("team.invitePage.done")}
              </Button>
            </div>
          </CardContent>
        </Card>
      ) : (
        <Card>
          <CardContent className="pt-6">
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="email">{t("team.invitePage.email")}</Label>
                <Input
                  id="email"
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
                <p className="text-xs text-muted-foreground">{t("team.invitePage.rolesHint")}</p>
                {errors.roles && <p className="text-sm text-destructive">{errors.roles}</p>}
              </div>

              {formError && (
                <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
                  {formError}
                </p>
              )}

              <div className="flex justify-end gap-2 pt-2">
                <Button type="button" variant="outline" onClick={() => router.push("/team")}>
                  {t("team.invitePage.cancel")}
                </Button>
                <Button type="submit" disabled={invite.isPending || roles.length === 0}>
                  {invite.isPending && <Spinner />}
                  {t("team.invitePage.submit")}
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>
      )}
    </div>
  );
}