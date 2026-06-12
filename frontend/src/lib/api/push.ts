import { api } from "@/lib/api/client";

/**
 * Web push subscription endpoints. Mirrors routes/api.php (push-subscriptions).
 * The body shape is the browser's PushSubscription JSON ({ endpoint, keys }).
 */
export const pushApi = {
  /** POST /push-subscriptions — register this device for the current user. */
  register: (subscription: PushSubscriptionJSON, ua?: string) =>
    api.post<void>("/push-subscriptions", {
      endpoint: subscription.endpoint,
      keys: subscription.keys,
      ua: ua ?? null,
    }),

  /** DELETE /push-subscriptions — unregister this device by endpoint. */
  unregister: (endpoint: string) =>
    api.delete<void>("/push-subscriptions", { endpoint }),
};

/** The subset of the browser PushSubscription.toJSON() shape we send. */
export interface PushSubscriptionJSON {
  endpoint: string;
  keys: { p256dh: string; auth: string };
}
