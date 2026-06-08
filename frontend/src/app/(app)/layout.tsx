import { ProtectedRoute } from "@/components/protected-route";
import { AppShell } from "@/components/app-shell";

/**
 * Layout for the authenticated area. The (app) route group keeps the URL clean
 * (/dashboard, /inventory) while sharing the guard + responsive shell.
 */
export default function AppLayout({ children }: { children: React.ReactNode }) {
  return (
    <ProtectedRoute>
      <AppShell>{children}</AppShell>
    </ProtectedRoute>
  );
}