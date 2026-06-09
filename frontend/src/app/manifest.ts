import type { MetadataRoute } from "next";

import { APP_NAME } from "@/lib/config";

/**
 * PWA web app manifest (served at /manifest.webmanifest). Makes the app
 * installable on mobile/desktop. Replace the icons in /public with real assets.
 */
export default function manifest(): MetadataRoute.Manifest {
  return {
    name: APP_NAME,
    short_name: APP_NAME,
    description: `${APP_NAME} — business intelligence`,
    start_url: "/",
    scope: "/",
    display: "standalone",
    orientation: "portrait",
    background_color: "#ffffff",
    theme_color: "#7a1f2b",
    icons: [
      { src: "/icons/logo.png", sizes: "512x512", type: "image/png", purpose: "any" },
      { src: "/icons/logo.png", sizes: "192x192", type: "image/png", purpose: "any" },
    ],
  };
}