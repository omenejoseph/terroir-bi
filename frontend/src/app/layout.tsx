import type { Metadata, Viewport } from "next";
import { Inter } from "next/font/google";

import { APP_NAME } from "@/lib/config";
import { Providers } from "@/app/providers";
import "./globals.css";

export const metadata: Metadata = {
  title: {
    default: APP_NAME,
    template: `%s · ${APP_NAME}`,
  },
  description: `${APP_NAME} — business intelligence`,
  applicationName: APP_NAME,
  // PWA: standalone install on iOS, status-bar styling, icons.
  appleWebApp: {
    capable: true,
    statusBarStyle: "default",
    title: APP_NAME,
  },
  icons: {
     icon: "/icons/logo.png",
    apple: "/icons/logo.png",
  },
};

// Match the order-mgmt project's typeface.
const inter = Inter({ subsets: ["latin"] });

export const viewport: Viewport = {
  themeColor: "#60524d",
  width: "device-width",
  initialScale: 1,
  // Allow the standalone PWA to use the full viewport including notches.
  viewportFit: "cover",
};

/**
 * Captures Chrome's `beforeinstallprompt` synchronously in <head>, before React
 * hydrates. The event fires very early on load; a `useEffect` listener attaches
 * too late and misses it (the bug where the install prompt never appeared). We
 * stash the event on `window` and dispatch a custom event so `useInstallPrompt`
 * can read it back. Kept inline + dependency-free so it runs first.
 */
const INSTALL_CAPTURE_SCRIPT = `
(function () {
  window.__terroirInstallPrompt = null;
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    window.__terroirInstallPrompt = e;
    window.dispatchEvent(new Event('terroir:installavailable'));
  });
  window.addEventListener('appinstalled', function () {
    window.__terroirInstallPrompt = null;
    window.dispatchEvent(new Event('terroir:installed'));
  });
})();
`;

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en" suppressHydrationWarning>
      <head>
        <script dangerouslySetInnerHTML={{ __html: INSTALL_CAPTURE_SCRIPT }} />
      </head>
      <body className={`${inter.className} antialiased`}>
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}