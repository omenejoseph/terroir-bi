"use client";

import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import { useInventory } from "@/hooks/use-inventory";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

export default function DashboardPage() {
  const { user, roles, tenants, activeTenantId } = useAuth();
  const { t } = useTranslation();
  const { data, isLoading } = useInventory();

  const activeTenant = tenants.find((tenant) => tenant.tenant_id === activeTenantId);

  const stats = [
    {
      label: t("dashboard.statInventory"),
      value: isLoading ? "…" : String(data?.meta?.total ?? data?.data.length ?? 0),
    },
    { label: t("dashboard.statRoles"), value: roles.length ? roles.join(", ") : t("dashboard.none") },
    { label: t("dashboard.statTenants"), value: String(tenants.length) },
  ];

  return (
    <div className="space-y-6">
      <header className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">
          {t("dashboard.welcome", { name: user?.first_name ?? "" })}
        </h1>
        <p className="text-sm text-muted-foreground">
          {activeTenant ? activeTenant.name : t("dashboard.noTenant")}
        </p>
      </header>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {stats.map((stat) => (
          <Card key={stat.label}>
            <CardHeader className="pb-2">
              <CardDescription>{stat.label}</CardDescription>
              <CardTitle className="text-2xl">{stat.value}</CardTitle>
            </CardHeader>
          </Card>
        ))}
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{t("dashboard.sessionTitle")}</CardTitle>
          <CardDescription>{t("dashboard.sessionSubtitle")}</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-wrap gap-2">
          <Badge variant="secondary">{user?.email}</Badge>
          {roles.map((role) => (
            <Badge key={role}>{role}</Badge>
          ))}
        </CardContent>
      </Card>
    </div>
  );
}