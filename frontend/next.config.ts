import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Pure SPA posture: data is fetched client-side against the Laravel API, so the
  // app stays fully detachable (deploy as a static/standalone bundle anywhere and
  // just point NEXT_PUBLIC_API_URL at the API). Flip `output` to "export" if you
  // want a 100% static export with no Node server.
  reactStrictMode: true,
};

export default nextConfig;