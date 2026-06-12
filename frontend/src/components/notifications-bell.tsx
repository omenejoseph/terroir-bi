"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { Bell } from "lucide-react";

import { useMarkNotificationsRead, useNotifications } from "@/hooks/use-notifications";
import { usePushNotifications } from "@/hooks/use-push-notifications";
import { useTranslation } from "@/i18n/context";
import { resolveNotificationRoute } from "@/lib/notifications/routes";
import type { Notification } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

export function NotificationsBell() {
  const { t } = useTranslation();
  const router = useRouter();
  const { data } = useNotifications();
  const markRead = useMarkNotificationsRead();
  const push = usePushNotifications();
  const [open, setOpen] = React.useState(false);
  const ref = React.useRef<HTMLDivElement>(null);

  const notifications = data ?? [];
  const unread = notifications.filter((n) => !n.is_read).length;

  React.useEffect(() => {
    if (!open) return;
    const onClick = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener("mousedown", onClick);
    return () => document.removeEventListener("mousedown", onClick);
  }, [open]);

  function openNotification(n: Notification) {
    if (!n.is_read) markRead.mutate([n.id]);
    setOpen(false);
    const route = resolveNotificationRoute(n.type, n.data);
    if (route) router.push(route);
  }

  // Offer to turn on push when the browser supports it and we're not subscribed
  // and the user hasn't denied permission.
  const showEnablePush = push.supported && !push.isSubscribed && push.permission !== "denied";

  return (
    <div ref={ref} className="relative">
      <Button
        variant="ghost"
        size="icon"
        aria-label={t("notifications.title")}
        onClick={() => setOpen((o) => !o)}
      >
        <Bell className="size-5" />
      </Button>
      {unread > 0 && (
        <span className="pointer-events-none absolute right-1 top-1 flex size-4 items-center justify-center rounded-full bg-destructive text-[10px] font-medium text-destructive-foreground">
          {unread > 9 ? "9+" : unread}
        </span>
      )}

      {open && (
        <div className="absolute right-0 z-50 mt-1 w-80 overflow-hidden rounded-md border border-border bg-popover shadow-md">
          <div className="flex items-center justify-between border-b border-border px-3 py-2">
            <span className="text-sm font-medium">{t("notifications.title")}</span>
            {unread > 0 && (
              <button
                type="button"
                className="text-xs text-primary hover:underline"
                onClick={() => markRead.mutate(undefined)}
              >
                {t("notifications.markAllRead")}
              </button>
            )}
          </div>
          {showEnablePush && (
            <button
              type="button"
              disabled={push.busy}
              onClick={() => void push.enable()}
              className="flex w-full items-center gap-2 border-b border-border bg-primary/5 px-3 py-2 text-left text-xs text-primary hover:bg-primary/10 disabled:opacity-60"
            >
              <Bell className="size-3.5 shrink-0" />
              {t("notifications.enablePush")}
            </button>
          )}
          <ul className="max-h-80 overflow-auto">
            {notifications.length === 0 ? (
              <li className="px-3 py-6 text-center text-sm text-muted-foreground">
                {t("notifications.empty")}
              </li>
            ) : (
              notifications.map((n) => (
                <li key={n.id}>
                  <button
                    type="button"
                    onClick={() => openNotification(n)}
                    className={cn(
                      "flex w-full items-start gap-2 px-3 py-2 text-left transition-colors hover:bg-accent",
                      !n.is_read && "bg-primary/5",
                    )}
                  >
                    <span className={cn("mt-1.5 size-2 shrink-0 rounded-full", n.is_read ? "bg-transparent" : "bg-primary")} />
                    <span className="min-w-0">
                      <span className="block truncate text-sm font-medium">{n.title}</span>
                      {n.body && <span className="block text-xs text-muted-foreground">{n.body}</span>}
                    </span>
                  </button>
                </li>
              ))
            )}
          </ul>
        </div>
      )}
    </div>
  );
}
