/*
  Minimal service worker for the Terroir BI PWA.

  Strategy:
  - Navigations  → network-first, falling back to a cached app shell when offline.
  - Static assets → stale-while-revalidate.
  - API requests (/api/*) are NEVER cached here — data freshness + auth are handled
    by React Query and the Bearer token; caching them would risk cross-tenant leaks.

  Web push:
  - "push" shows an OS notification from the payload ({title, body, type, data}).
  - "notificationclick" stays ROUTING-DUMB: it hands {type, data} back to the app
    (focus + postMessage, or open with a ?notif= param) and the app resolves the
    route. No paths live here — a future native app reuses the same payload.

  No build step needed; bump CACHE_VERSION to invalidate old caches.
*/
const CACHE_VERSION = "terroir-v2";
const APP_SHELL = "/";

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_VERSION).then((cache) => cache.addAll([APP_SHELL])),
  );
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(keys.filter((key) => key !== CACHE_VERSION).map((key) => caches.delete(key))),
      )
      .then(() => self.clients.claim()),
  );
});

self.addEventListener("fetch", (event) => {
  const { request } = event;
  if (request.method !== "GET") return;

  const url = new URL(request.url);

  // Never cache API or cross-origin requests.
  if (url.origin !== self.location.origin) return;
  if (url.pathname.startsWith("/api/")) return;

  // App navigations: network-first with offline fallback.
  if (request.mode === "navigate") {
    event.respondWith(
      fetch(request)
        .then((response) => {
          const copy = response.clone();
          caches.open(CACHE_VERSION).then((cache) => cache.put(APP_SHELL, copy));
          return response;
        })
        .catch(() => caches.match(APP_SHELL).then((r) => r ?? Response.error())),
    );
    return;
  }

  // Static assets: stale-while-revalidate.
  event.respondWith(
    caches.match(request).then((cached) => {
      const network = fetch(request)
        .then((response) => {
          const copy = response.clone();
          caches.open(CACHE_VERSION).then((cache) => cache.put(request, copy));
          return response;
        })
        .catch(() => cached);
      return cached ?? network;
    }),
  );
});

// ── Web push ────────────────────────────────────────────────────────────────

// Show the OS notification. The payload is path-free: { title, body, type, data,
// icon, tag }. We stash { type, data } on the notification so the click handler
// can hand it back to the app for routing.
self.addEventListener("push", (event) => {
  let payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch {
    payload = { title: "Notification" };
  }

  const title = payload.title || "Terroir BI";
  const options = {
    body: payload.body || undefined,
    icon: payload.icon || "/icons/logo.png",
    badge: "/icons/logo.png",
    tag: payload.tag || undefined,
    data: { type: payload.type || null, data: payload.data || {} },
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

// On click: focus an open app window and post { type, data } to it (the app
// resolves the route), or open the app with the payload encoded so it can route
// on load. The service worker never knows any paths.
self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const { type = null, data = {} } = event.notification.data || {};

  event.waitUntil(
    self.clients
      .matchAll({ type: "window", includeUncontrolled: true })
      .then((clients) => {
        for (const client of clients) {
          if ("focus" in client) {
            client.postMessage({ kind: "notification-click", type, data });
            return client.focus();
          }
        }
        const url = "/?notif=" + encodeURIComponent(JSON.stringify({ type, data }));
        return self.clients.openWindow(url);
      }),
  );
});