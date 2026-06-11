import { defineCloudflareConfig } from "@opennextjs/cloudflare";

// Default Cloudflare config. The app is a client-fetching SPA, so we don't need
// incremental cache / tag revalidation wired to KV or R2 — the defaults are fine.
export default defineCloudflareConfig();