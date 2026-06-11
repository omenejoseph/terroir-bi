import { ProtectedRoute } from "@/components/protected-route";
import { AppShell } from "@/components/app-shell";
import { AccessGuard } from "@/components/access-guard";

/**
 * Layout for the authenticated area. The (app) route group keeps the URL clean
 * (/dashboard, /inventory) while sharing the guard + responsive shell. The
 * AccessGuard layers subscription gating (read-only banner / blocked screen).
 */
export default function AppLayout({ children }: { children: React.ReactNode }) {
  return (
    <ProtectedRoute>
      <AppShell>
        <AccessGuard>{children}</AccessGuard>
      </AppShell>
    </ProtectedRoute>
  );
}