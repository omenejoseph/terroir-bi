import { api } from "@/lib/api/client";
import type { Notification } from "@/lib/types";

/** In-app notifications. Mirrors routes/api.php (notifications). */
export const notificationsApi = {
  /** GET /notifications — newest first; pass unread to filter. */
  list: (params: { unread?: boolean } = {}) =>
    api.get<Notification[]>("/notifications", { unread: params.unread }),

  /** POST /notifications/read — mark given ids (or all when omitted) as read. */
  markRead: (ids?: string[]) => api.post<void>("/notifications/read", ids ? { ids } : {}),
};
