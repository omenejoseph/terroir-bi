"use client";

import * as React from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { Boxes, LayoutDashboard, LogOut, Menu, Users, X } from "lucide-react";

import { APP_NAME } from "@/lib/config";
import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { TenantSwitcher } from "@/components/tenant-switcher";
import { LanguageSwitcher } from "@/components/language-switcher";

interface NavItem {
  href: string;
  /** i18n key for the label. */
  labelKey: string;
  icon: React.ComponentType<{ className?: string }>;
}

const NAV: NavItem[] = [
  { href: "/dashboard", labelKey: "nav.dashboard", icon: LayoutDashboard },
  { href: "/inventory", labelKey: "nav.inventory", icon: Boxes },
  { href: "/customers", labelKey: "nav.customers", icon: Users },
];

/**
 * Responsive application shell.
 * - Desktop (md+): fixed left sidebar.
 * - Mobile: top bar with a hamburger that opens a slide-in drawer.
 * Layout is all token + Tailwind responsive utilities — no per-page chrome.
 */
export function AppShell({ children }: { children: React.ReactNode }) {
  const [mobileOpen, setMobileOpen] = React.useState(false);
  const pathname = usePathname();

  // Close the drawer whenever the route changes.
  React.useEffect(() => {
    setMobileOpen(false);
  }, [pathname]);

  return (
    <div className="flex min-h-dvh flex-col md:flex-row">
      {/* Mobile top bar */}
      <header className="flex items-center justify-between border-b border-border bg-background px-4 py-3 md:hidden">
        <Link href="/dashboard" className="font-semibold">
          {APP_NAME}
        </Link>
        <Button
          variant="ghost"
          size="icon"
          aria-label="menu"
          onClick={() => setMobileOpen(true)}
        >
          <Menu />
        </Button>
      </header>

      {/* Mobile drawer overlay */}
      {mobileOpen && (
        <div
          className="fixed inset-0 z-40 bg-black/50 md:hidden"
          onClick={() => setMobileOpen(false)}
          aria-hidden
        />
      )}

      {/* Sidebar — drawer on mobile, static on desktop */}
      <Sidebar
        className={cn(
          "fixed inset-y-0 left-0 z-50 w-72 transform transition-transform md:static md:z-auto md:translate-x-0",
          mobileOpen ? "translate-x-0" : "-translate-x-full",
        )}
        onClose={() => setMobileOpen(false)}
        pathname={pathname}
        showClose
      />

      {/* Main content */}
      <main className="flex-1 overflow-x-hidden">
        <div className="mx-auto w-full max-w-6xl px-4 py-6 sm:px-6 lg:px-8">{children}</div>
      </main>
    </div>
  );
}

function Sidebar({
  className,
  pathname,
  onClose,
  showClose,
}: {
  className?: string;
  pathname: string;
  onClose: () => void;
  showClose?: boolean;
}) {
  const { user, logout } = useAuth();
  const { t } = useTranslation();

  return (
    <aside
      className={cn("flex w-72 flex-col border-r border-border bg-background", className)}
    >
      <div className="flex items-center justify-between px-4 py-4">
        <Link href="/dashboard" className="text-lg font-semibold">
          {APP_NAME}
        </Link>
        {showClose && (
          <Button variant="ghost" size="icon" className="md:hidden" onClick={onClose}>
            <X />
          </Button>
        )}
      </div>

      <div className="px-3 pb-2">
        <TenantSwitcher />
      </div>

      <nav className="flex-1 space-y-1 px-3 py-2">
        {NAV.map((item) => {
          const active = pathname === item.href || pathname.startsWith(`${item.href}/`);
          return (
            <Link
              key={item.href}
              href={item.href}
              className={cn(
                "flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors",
                active
                  ? "bg-primary text-primary-foreground"
                  : "text-muted-foreground hover:bg-accent hover:text-accent-foreground",
              )}
            >
              <item.icon className="size-4" />
              {t(item.labelKey)}
            </Link>
          );
        })}
      </nav>

      <div className="space-y-3 border-t border-border p-3">
        <LanguageSwitcher />
        <div className="px-1">
          <p className="truncate text-sm font-medium">{user?.name}</p>
          <p className="truncate text-xs text-muted-foreground">{user?.email}</p>
        </div>
        <Button variant="outline" size="sm" className="w-full" onClick={() => void logout()}>
          <LogOut className="size-4" />
          {t("common.signOut")}
        </Button>
      </div>
    </aside>
  );
}