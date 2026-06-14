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

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en" suppressHydrationWarning>
      <body className={`${inter.className} antialiased`}>
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}