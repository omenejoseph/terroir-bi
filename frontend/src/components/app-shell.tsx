"use client";

import * as React from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  Boxes,
  ChevronsLeft,
  LayoutDashboard,
  LogOut,
  Menu,
  Users,
  UsersRound,
  X,
} from "lucide-react";

import { APP_NAME } from "@/lib/config";
import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Logo } from "@/components/logo";
import { TenantSwitcher } from "@/components/tenant-switcher";
import { LanguageSwitcher } from "@/components/language-switcher";

interface NavItem {
  href: string;
  /** i18n key for the label. */
  labelKey: string;
  icon: React.ComponentType<{ className?: string }>;
  /** Capability required to see this item (omit = always visible). */
  cap?: string;
}

const NAV: NavItem[] = [
  { href: "/dashboard", labelKey: "nav.dashboard", icon: LayoutDashboard },
  { href: "/inventory", labelKey: "nav.inventory", icon: Boxes },
  { href: "/customers", labelKey: "nav.customers", icon: Users },
  { href: "/team", labelKey: "nav.team", icon: UsersRound, cap: "members.view" },
];

// Desktop rail sizing (px).
const RAIL = 72;
const MIN_WIDTH = 208;
const MAX_WIDTH = 380;
const DEFAULT_WIDTH = 288;
const COLLAPSE_AT = 168;

const KEY_COLLAPSED = "terroir.sidebar.collapsed";
const KEY_WIDTH = "terroir.sidebar.width";

function userInitials(name?: string): string {
  if (!name) return "?";
  const parts = name.trim().split(/\s+/);
  return ((parts[0]?.[0] ?? "") + (parts[1]?.[0] ?? "")).toUpperCase() || "?";
}

/**
 * Responsive application shell.
 * - Desktop (md+): a sidebar that collapses to an icon rail (toggle or drag the
 *   right edge). Width is animated and persisted.
 * - Mobile: top bar with a hamburger that opens a slide-in drawer.
 */
export function AppShell({ children }: { children: React.ReactNode }) {
  const [mobileOpen, setMobileOpen] = React.useState(false);
  const [collapsed, setCollapsed] = React.useState(false);
  const [width, setWidth] = React.useState(DEFAULT_WIDTH);
  const [dragging, setDragging] = React.useState(false);
  const pathname = usePathname();

  // Restore persisted rail state.
  React.useEffect(() => {
    if (typeof window === "undefined") return;
    setCollapsed(window.localStorage.getItem(KEY_COLLAPSED) === "true");
    const w = Number(window.localStorage.getItem(KEY_WIDTH));
    if (Number.isFinite(w) && w >= MIN_WIDTH && w <= MAX_WIDTH) setWidth(w);
  }, []);

  React.useEffect(() => {
    window.localStorage.setItem(KEY_COLLAPSED, String(collapsed));
  }, [collapsed]);
  React.useEffect(() => {
    window.localStorage.setItem(KEY_WIDTH, String(width));
  }, [width]);

  // Close the drawer whenever the route changes.
  React.useEffect(() => {
    setMobileOpen(false);
  }, [pathname]);

  // Drag-to-resize: the sidebar's left edge is at viewport x=0, so cursor x is
  // the width. Below a threshold it snaps to the collapsed rail.
  React.useEffect(() => {
    if (!dragging) return;
    const onMove = (e: MouseEvent) => {
      const x = e.clientX;
      if (x < COLLAPSE_AT) {
        setCollapsed(true);
      } else {
        setCollapsed(false);
        setWidth(Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, x)));
      }
    };
    const onUp = () => setDragging(false);
    document.addEventListener("mousemove", onMove);
    document.addEventListener("mouseup", onUp);
    document.body.style.userSelect = "none";
    document.body.style.cursor = "col-resize";
    return () => {
      document.removeEventListener("mousemove", onMove);
      document.removeEventListener("mouseup", onUp);
      document.body.style.userSelect = "";
      document.body.style.cursor = "";
    };
  }, [dragging]);

  return (
    <div className="flex min-h-dvh flex-col md:flex-row">
      {/* Mobile top bar */}
      <header className="sticky top-0 z-30 flex items-center justify-between border-b border-border bg-card/80 px-4 py-2.5 backdrop-blur-xl md:hidden">
        <Link href="/dashboard" aria-label={APP_NAME}>
          <Logo className="size-9" />
        </Link>
        <Button variant="ghost" size="icon" aria-label="menu" onClick={() => setMobileOpen(true)}>
          <Menu />
        </Button>
      </header>

      {/* Mobile drawer */}
      {mobileOpen && (
        <div
          className="fixed inset-0 z-40 bg-black/50 md:hidden"
          onClick={() => setMobileOpen(false)}
          aria-hidden
        />
      )}
      <aside
        className={cn(
          "fixed inset-y-0 left-0 z-50 flex w-72 transform flex-col border-r border-border bg-card/95 backdrop-blur-xl transition-transform md:hidden",
          mobileOpen ? "translate-x-0" : "-translate-x-full",
        )}
      >
        <SidebarContent
          collapsed={false}
          pathname={pathname}
          onClose={() => setMobileOpen(false)}
          showClose
        />
      </aside>

      {/* Desktop sidebar — collapsible + draggable */}
      <aside
        style={{ width: collapsed ? RAIL : width }}
        className={cn(
          "relative hidden shrink-0 flex-col border-r border-border bg-card/80 backdrop-blur-xl md:flex",
          // Pin to the viewport so the menu stays put while the page scrolls.
          "sticky top-0 h-dvh self-start",
          !dragging && "transition-[width] duration-300 ease-out",
        )}
      >
        <SidebarContent
          collapsed={collapsed}
          pathname={pathname}
          onToggle={() => setCollapsed((c) => !c)}
        />
        {/* Drag handle */}
        <div
          role="separator"
          aria-orientation="vertical"
          onMouseDown={() => setDragging(true)}
          onDoubleClick={() => setCollapsed((c) => !c)}
          className="absolute -right-1 top-0 z-10 h-full w-2 cursor-col-resize transition-colors hover:bg-primary/20 active:bg-primary/30"
        />
      </aside>

      {/* Main content */}
      <main className="min-w-0 flex-1 overflow-x-hidden">
        <div className="mx-auto w-full max-w-6xl px-4 py-6 sm:px-6 lg:px-8">{children}</div>
      </main>
    </div>
  );
}

function SidebarContent({
  collapsed,
  pathname,
  onClose,
  onToggle,
  showClose,
}: {
  collapsed: boolean;
  pathname: string;
  onClose?: () => void;
  onToggle?: () => void;
  showClose?: boolean;
}) {
  const { user, logout, can } = useAuth();
  const { t } = useTranslation();
  const nav = NAV.filter((item) => !item.cap || can(item.cap));

  return (
    <div className="flex h-full flex-col">
      {/* Logo — centered */}
      <div className="relative flex items-center justify-center">
        <Link href="/dashboard" aria-label={APP_NAME} className="flex w-full items-center justify-center">
          <Logo className={collapsed ? "size-10" : "h-auto w-full max-w-[136px]"} />
        </Link>
        {showClose && (
          <Button
            variant="ghost"
            size="icon"
            className="absolute right-2 md:hidden"
            onClick={onClose}
          >
            <X />
          </Button>
        )}
      </div>

      {/* Org switcher */}
      <div className={cn("pb-2", collapsed ? "px-2" : "px-3")}>
        <TenantSwitcher compact={collapsed} />
      </div>

      {/* Nav */}
      <nav className={cn("min-h-0 flex-1 space-y-1 overflow-y-auto py-2", collapsed ? "px-2" : "px-3")}>
        {nav.map((item) => {
          const active = pathname === item.href || pathname.startsWith(`${item.href}/`);
          return (
            <Link
              key={item.href}
              href={item.href}
              aria-current={active ? "page" : undefined}
              title={collapsed ? t(item.labelKey) : undefined}
              className={cn(
                "group relative flex items-center rounded-md text-sm font-medium transition-all duration-200 ease-out",
                collapsed ? "justify-center px-0 py-2.5" : "gap-3 px-3 py-2",
                active
                  ? "bg-primary/10 text-primary"
                  : "text-muted-foreground hover:bg-accent/60 hover:text-foreground",
              )}
            >
              <span
                className={cn(
                  "absolute left-0 top-1/2 h-5 w-0.5 -translate-y-1/2 rounded-full bg-primary transition-all duration-200 ease-out",
                  active ? "opacity-100" : "opacity-0 group-hover:opacity-40",
                )}
              />
              <item.icon
                className={cn(
                  "size-4 shrink-0 transition-colors duration-200",
                  active ? "text-primary" : "text-muted-foreground group-hover:text-foreground",
                )}
              />
              {!collapsed && t(item.labelKey)}
            </Link>
          );
        })}
      </nav>

      {/* Footer */}
      <div className={cn("space-y-2 border-t border-border py-3", collapsed ? "px-2" : "px-3")}>
        {!collapsed && <LanguageSwitcher />}

        {collapsed ? (
          <span
            title={user?.name}
            className="mx-auto flex size-9 items-center justify-center rounded-full bg-gradient-to-br from-primary to-primary/70 text-xs font-semibold text-primary-foreground shadow-sm"
          >
            {userInitials(user?.name)}
          </span>
        ) : (
          <div className="px-1">
            <p className="truncate text-sm font-medium">{user?.name}</p>
            <p className="truncate text-xs text-muted-foreground">{user?.email}</p>
          </div>
        )}

        {collapsed ? (
          <Button
            variant="outline"
            size="icon"
            className="mx-auto flex"
            title={t("common.signOut")}
            onClick={() => void logout()}
          >
            <LogOut className="size-4" />
          </Button>
        ) : (
          <Button variant="outline" size="sm" className="w-full" onClick={() => void logout()}>
            <LogOut className="size-4" />
            {t("common.signOut")}
          </Button>
        )}

        {/* Collapse/expand toggle (desktop only) */}
        {onToggle && (
          <button
            type="button"
            onClick={onToggle}
            title={collapsed ? t("common.expand") : t("common.collapse")}
            className={cn(
              "flex items-center rounded-md text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground",
              collapsed ? "mx-auto size-9 justify-center" : "w-full gap-2 px-3 py-2",
            )}
          >
            <ChevronsLeft className={cn("size-4 transition-transform", collapsed && "rotate-180")} />
            {!collapsed && t("common.collapse")}
          </button>
        )}
      </div>
    </div>
  );
}