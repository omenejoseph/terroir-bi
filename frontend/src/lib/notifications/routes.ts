import type { NotificationType } from "@/lib/types";

/**
 * The ONE place that maps a notification's (type, data) to a web route. The API
 * never sends paths — it sends a semantic `type` plus a `data` bag of params —
 * so this resolver is what makes a notification navigable on the web. A native
 * app would ship its own equivalent against the identical (type, data) contract.
 *
 * Returns null for notifications with no destination (e.g. announcements), which
 * the UI treats as display-only.
 */
export function resolveNotificationRoute(
  type: NotificationType,
  data: Record<string, string> | null | undefined,
): string | null {
  const params = data ?? {};

  switch (type) {
    case "NEW_ORDER":
    case "ORDER_STATUS":
    case "MENTION":
    case "REPLY":
      return params.order_id ? `/orders/${params.order_id}` : null;
    case "ANNOUNCEMENT":
      return null;
    default:
      return null;
  }
}
