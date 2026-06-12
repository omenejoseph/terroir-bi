import { VAPID_PUBLIC_KEY } from "@/lib/config";
import { pushApi, type PushSubscriptionJSON } from "@/lib/api/push";

/**
 * Browser-side web push plumbing: feature detection, subscribe (request a
 * PushSubscription from the SW and register it with the backend) and unsubscribe.
 * Kept separate from React so it can be unit-tested and reused.
 */

/** Push needs a service worker, the Push API, the Notification API and a key. */
export function isPushSupported(): boolean {
  return (
    typeof window !== "undefined" &&
    "serviceWorker" in navigator &&
    "PushManager" in window &&
    "Notification" in window &&
    VAPID_PUBLIC_KEY !== ""
  );
}

/** VAPID keys travel as base64url; the Push API wants a Uint8Array. */
export function urlBase64ToUint8Array(base64: string): Uint8Array {
  const padding = "=".repeat((4 - (base64.length % 4)) % 4);
  const normalized = (base64 + padding).replace(/-/g, "+").replace(/_/g, "/");
  const raw = atob(normalized);
  const output = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i += 1) output[i] = raw.charCodeAt(i);
  return output;
}

/** Normalise a browser PushSubscription to the JSON shape the API expects. */
function toJSON(subscription: PushSubscription): PushSubscriptionJSON {
  const json = subscription.toJSON();
  return {
    endpoint: subscription.endpoint,
    keys: {
      p256dh: json.keys?.p256dh ?? "",
      auth: json.keys?.auth ?? "",
    },
  };
}

/**
 * Subscribe this device: reuse the existing PushSubscription or create one, then
 * register it with the backend. Caller is responsible for having requested
 * Notification permission first.
 */
export async function subscribeToPush(): Promise<void> {
  const registration = await navigator.serviceWorker.ready;
  const existing = await registration.pushManager.getSubscription();

  const subscription =
    existing ??
    (await registration.pushManager.subscribe({
      userVisibleOnly: true,
      // Cast: the lib's BufferSource type narrows to ArrayBuffer-backed views.
      applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY) as BufferSource,
    }));

  await pushApi.register(toJSON(subscription), navigator.userAgent);
}

/** Unsubscribe this device from the browser and the backend. */
export async function unsubscribeFromPush(): Promise<void> {
  const registration = await navigator.serviceWorker.ready;
  const subscription = await registration.pushManager.getSubscription();
  if (!subscription) return;

  const { endpoint } = subscription;
  await subscription.unsubscribe();
  await pushApi.unregister(endpoint);
}

/** Whether this device currently has an active push subscription. */
export async function getPushSubscribed(): Promise<boolean> {
  if (!isPushSupported()) return false;
  const registration = await navigator.serviceWorker.ready;
  return (await registration.pushManager.getSubscription()) !== null;
}
