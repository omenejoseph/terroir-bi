"use client";

import * as React from "react";

import { authApi } from "@/lib/api/auth";
import { tokenStorage } from "@/lib/auth/storage";
import type { AuthSession, TenantMembership, User } from "@/lib/types";

interface AuthState {
  user: User | null;
  activeTenantId: string | null;
  roles: string[];
  tenants: TenantMembership[];
  /** True until the initial session restore completes. */
  loading: boolean;
  isAuthenticated: boolean;
}

interface AuthContextValue extends AuthState {
  login: (email: string, password: string, tenantId?: string) => Promise<void>;
  logout: () => Promise<void>;
  switchTenant: (tenantId: string) => Promise<void>;
  /** Convenience permission helper based on the active tenant's roles. */
  hasRole: (role: string) => boolean;
}

const AuthContext = React.createContext<AuthContextValue | null>(null);

const EMPTY: AuthState = {
  user: null,
  activeTenantId: null,
  roles: [],
  tenants: [],
  loading: true,
  isAuthenticated: false,
};

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [state, setState] = React.useState<AuthState>(EMPTY);

  const applySession = React.useCallback((session: AuthSession) => {
    if (session.token) tokenStorage.set(session.token);
    setState({
      user: session.user,
      activeTenantId: session.active_tenant_id,
      roles: session.roles,
      tenants: session.tenants,
      loading: false,
      isAuthenticated: true,
    });
  }, []);

  const reset = React.useCallback(() => {
    tokenStorage.clear();
    setState({ ...EMPTY, loading: false });
  }, []);

  // Restore the session on first mount if a token is present.
  React.useEffect(() => {
    if (!tokenStorage.get()) {
      setState((s) => ({ ...s, loading: false }));
      return;
    }
    authApi
      .me()
      .then(applySession)
      .catch(() => reset());
  }, [applySession, reset]);

  const login = React.useCallback(
    async (email: string, password: string, tenantId?: string) => {
      const session = await authApi.login(email, password, tenantId);
      applySession(session);
    },
    [applySession],
  );

  const logout = React.useCallback(async () => {
    try {
      await authApi.logout();
    } catch {
      // Ignore — clear locally regardless.
    }
    reset();
  }, [reset]);

  const switchTenant = React.useCallback(
    async (tenantId: string) => {
      // Returns a brand-new tenant-bound token; applySession persists it.
      const session = await authApi.switchTenant(tenantId);
      applySession(session);
    },
    [applySession],
  );

  const hasRole = React.useCallback((role: string) => state.roles.includes(role), [state.roles]);

  const value = React.useMemo<AuthContextValue>(
    () => ({ ...state, login, logout, switchTenant, hasRole }),
    [state, login, logout, switchTenant, hasRole],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = React.useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within <AuthProvider>");
  return ctx;
}