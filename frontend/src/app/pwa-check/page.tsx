"use client";

// Public (no-auth) PWA diagnostic. Open this on the phone and screenshot it to
// see exactly why the install / notification affordances are or aren't showing.
// Safe to leave deployed; it reads state only. Remove when no longer needed.

import * as React from "react";

const DISMISS_KEY = "terroir.pwa.install-dismissed";

function Row({ label, value }: { label: string; value: React.ReactNode }) {
  const ok = value === true || value === "yes";
  const bad = value === false || value === "no";
  return (
    <div
      style={{
        display: "flex",
        justifyContent: "space-between",
        gap: 12,
        padding: "8px 0",
        borderBottom: "1px solid #e5e5e5",
        fontFamily: "ui-monospace, monospace",
        fontSize: 13,
      }}
    >
      <span style={{ color: "#555" }}>{label}</span>
      <span
        style={{
          fontWeight: 600,
          textAlign: "right",
          wordBreak: "break-all",
          color: ok ? "#137333" : bad ? "#c5221f" : "#111",
        }}
      >
        {String(value)}
      </span>
    </div>
  );
}

export default function PwaCheckPage() {
  const [state, setState] = React.useState<Record<string, unknown> | null>(null);

  const read = React.useCallback(async () => {
    const nav = navigator as unknown as { standalone?: boolean };
    let swController = false;
    let swBuildId = "n/a";
    let swCount = 0;
    try {
      const regs = await navigator.serviceWorker?.getRegistrations?.();
      swCount = regs?.length ?? 0;
      swController = !!navigator.serviceWorker?.controller;
      const txt = await (await fetch("/sw.js", { cache: "no-store" })).text();
      swBuildId = (txt.match(/BUILD_ID = "([^"]+)"/) || [])[1] || "not-found";
    } catch {
      /* ignore */
    }
    setState({
      userAgent: navigator.userAgent,
      isIphoneIpad: /iPad|iPhone|iPod/.test(navigator.userAgent),
      displayModeStandalone:
        window.matchMedia?.("(display-mode: standalone)").matches === true,
      navigatorStandalone: nav.standalone === true,
      installDismissedFlag: window.localStorage.getItem(DISMISS_KEY) === "true",
      hasBeforeInstallPrompt: "onbeforeinstallprompt" in window,
      capturedInstallEvent: !!(window as unknown as { __terroirInstallPrompt?: unknown })
        .__terroirInstallPrompt,
      pushManager: "PushManager" in window,
      notificationApi: "Notification" in window,
      notificationPermission: "Notification" in window ? Notification.permission : "n/a",
      vapidKeyPresent: (process.env.NEXT_PUBLIC_VAPID_PUBLIC_KEY ?? "") !== "",
      serviceWorkerSupported: "serviceWorker" in navigator,
      serviceWorkerRegistrations: swCount,
      serviceWorkerControlling: swController,
      deployedSwBuildId: swBuildId,
      nodeEnv: process.env.NODE_ENV,
    });
  }, []);

  React.useEffect(() => {
    void read();
  }, [read]);

  return (
    <main style={{ maxWidth: 520, margin: "0 auto", padding: 20 }}>
      <h1 style={{ fontSize: 18, fontWeight: 700, marginBottom: 4 }}>PWA diagnostic</h1>
      <p style={{ fontSize: 12, color: "#666", marginBottom: 16 }}>
        Screenshot this and send it. Green = good, red = the likely blocker.
      </p>
      {state ? (
        Object.entries(state).map(([k, v]) => (
          <Row key={k} label={k} value={v as React.ReactNode} />
        ))
      ) : (
        <p>Reading…</p>
      )}
      <button
        type="button"
        onClick={() => {
          window.localStorage.removeItem(DISMISS_KEY);
          void read();
        }}
        style={{
          marginTop: 16,
          padding: "10px 14px",
          borderRadius: 8,
          border: "1px solid #ccc",
          fontSize: 14,
          width: "100%",
        }}
      >
        Clear &quot;install dismissed&quot; flag &amp; re-read
      </button>
      <button
        type="button"
        onClick={async () => {
          const regs = (await navigator.serviceWorker?.getRegistrations?.()) ?? [];
          await Promise.all(regs.map((r) => r.unregister()));
          const keys = (await caches?.keys?.()) ?? [];
          await Promise.all(keys.map((k) => caches.delete(k)));
          window.location.reload();
        }}
        style={{
          marginTop: 10,
          padding: "10px 14px",
          borderRadius: 8,
          border: "1px solid #c5221f",
          color: "#c5221f",
          fontSize: 14,
          width: "100%",
        }}
      >
        Nuke service workers + caches, then reload
      </button>
    </main>
  );
}
