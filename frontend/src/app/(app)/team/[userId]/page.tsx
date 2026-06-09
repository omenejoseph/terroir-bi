"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft } from "lucide-react";

import { useMembers } from "@/hooks/use-team";
import { useTranslation } from "@/i18n/context";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { MemberForm } from "@/components/team/member-form";

export default function MemberEditPage() {
  const params = useParams<{ userId: string }>();
  const userId = params?.userId;
  const { t } = useTranslation();
  const router = useRouter();

  const membersQ = useMembers();
  const member = membersQ.data?.find((m) => m.user_id === userId);

  return (
    <div className="mx-auto max-w-xl space-y-6">
      <Link
        href="/team"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("team.back")}
      </Link>

      {membersQ.isLoading ? (
        <div className="flex justify-center py-16">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      ) : !member ? (
        <Card>
          <CardContent className="py-12 text-center text-sm text-muted-foreground">
            {t("team.member.notFound")}
          </CardContent>
        </Card>
      ) : (
        <>
          <div className="space-y-1">
            <h1 className="text-2xl font-semibold tracking-tight">{member.name || member.email}</h1>
            <p className="text-sm text-muted-foreground">{member.email}</p>
          </div>
          <MemberForm
            member={member}
            onSaved={() => router.push("/team")}
            onCancel={() => router.push("/team")}
            onRemoved={() => router.push("/team")}
          />
        </>
      )}
    </div>
  );
}