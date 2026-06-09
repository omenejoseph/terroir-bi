"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft } from "lucide-react";

import { useInvitations } from "@/hooks/use-team";
import { useTranslation } from "@/i18n/context";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { InvitationForm } from "@/components/team/invitation-form";

export default function InvitationEditPage() {
  const params = useParams<{ invitationId: string }>();
  const invitationId = params?.invitationId;
  const { t } = useTranslation();
  const router = useRouter();

  const invitationsQ = useInvitations();
  const invitation = invitationsQ.data?.find((i) => i.id === invitationId);

  return (
    <div className="mx-auto max-w-xl space-y-6">
      <Link
        href="/team"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("team.back")}
      </Link>

      {invitationsQ.isLoading ? (
        <div className="flex justify-center py-16">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      ) : !invitation ? (
        <Card>
          <CardContent className="py-12 text-center text-sm text-muted-foreground">
            {t("team.invitation.notFound")}
          </CardContent>
        </Card>
      ) : (
        <>
          <h1 className="text-2xl font-semibold tracking-tight">{t("team.invitation.title")}</h1>
          <InvitationForm
            invitation={invitation}
            onDone={() => router.push("/team")}
            onRevoked={() => router.push("/team")}
          />
        </>
      )}
    </div>
  );
}