import { api } from "@/lib/api/client";
import type { AuthSession, TenantMembership } from "@/lib/types";

/** Auth endpoints. Mirrors routes/api.php (auth/*). */
export const authApi = {
  /** POST /auth/login — public. Returns a tenant-bound token. */
  login: (email: string, password: string, tenantId?: string) =>
    api.post<AuthSession>("/auth/login", {
      email,
      password,
      ...(tenantId ? { tenant_id: tenantId } : {}),
    }),

  /** POST /auth/logout — revokes the current token. */
  logout: () => api.post<void>("/auth/logout"),

  /** GET /auth/me — current user + active tenant + roles. */
  me: () => api.get<AuthSession>("/auth/me"),

  /** GET /auth/tenants — memberships for the tenant switcher. */
  tenants: () => api.get<TenantMembership[]>("/auth/tenants"),

  /**
   * POST /auth/switch-tenant — returns a NEW token bound to the chosen tenant.
   * Callers must persist the returned token in place of the old one.
   */
  switchTenant: (tenantId: string) =>
    api.post<AuthSession>("/auth/switch-tenant", { tenant_id: tenantId }),
};