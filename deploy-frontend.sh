#!/usr/bin/env bash
#
# Deploy the Next.js frontend (frontend/) to Cloudflare via OpenNext.
#
# Build-time NEXT_PUBLIC_* values are baked into the client bundle here:
#   - NEXT_PUBLIC_API_URL          derived from REMOTE_BACKEND_URL (required)
#   - NEXT_PUBLIC_VAPID_PUBLIC_KEY copied from the backend's VAPID_PUBLIC_KEY
#                                  (optional; without it Web Push is inert and
#                                  the UI reports push as unsupported)
# Each is resolved from (in order): an exported env var, then the root .env.
#
# Usage:
#   ./deploy-frontend.sh
#   REMOTE_BACKEND_URL=https://api.example.com ./deploy-frontend.sh
#
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FRONTEND_DIR="$ROOT_DIR/frontend"

# Read KEY from the root .env (last assignment wins), stripping surrounding
# quotes and whitespace. Prints nothing if the file or key is absent.
read_env() {
  local key="$1" line
  [[ -f "$ROOT_DIR/.env" ]] || return 0
  line="$(grep -E "^[[:space:]]*${key}=" "$ROOT_DIR/.env" | tail -n1 || true)"
  [[ -n "$line" ]] || return 0
  line="${line#*=}"
  line="${line%\"}"; line="${line#\"}"
  line="${line%\'}"; line="${line#\'}"
  printf '%s' "$line" | tr -d '[:space:]'
}

# --- Resolve REMOTE_BACKEND_URL ------------------------------------------------
backend_url="${REMOTE_BACKEND_URL:-}"
[[ -n "$backend_url" ]] || backend_url="$(read_env REMOTE_BACKEND_URL)"

if [[ -z "$backend_url" ]]; then
  echo "Error: REMOTE_BACKEND_URL is not set." >&2
  echo "       Export it, or add it to $ROOT_DIR/.env, e.g.:" >&2
  echo "       REMOTE_BACKEND_URL=https://api.your-domain.com" >&2
  exit 1
fi

# Normalise: drop a trailing slash, then append the API path the frontend expects.
backend_url="${backend_url%/}"
export NEXT_PUBLIC_API_URL="$backend_url/api/v1"

# --- Resolve VAPID public key (Web Push) ---------------------------------------
# Single source of truth is the backend's VAPID_PUBLIC_KEY; the public key is
# safe to ship in the client bundle. Keeping both sides off the same value means
# the frontend subscription key can never drift from the server's signing key.
vapid_public_key="${NEXT_PUBLIC_VAPID_PUBLIC_KEY:-}"
[[ -n "$vapid_public_key" ]] || vapid_public_key="$(read_env VAPID_PUBLIC_KEY)"
export NEXT_PUBLIC_VAPID_PUBLIC_KEY="$vapid_public_key"

# --- Deploy --------------------------------------------------------------------
echo "==> Deploying frontend to Cloudflare"
echo "    REMOTE_BACKEND_URL = $backend_url"
echo "    NEXT_PUBLIC_API_URL = $NEXT_PUBLIC_API_URL"
if [[ -n "$vapid_public_key" ]]; then
  echo "    NEXT_PUBLIC_VAPID_PUBLIC_KEY = ${vapid_public_key:0:12}… (Web Push enabled)"
else
  echo "    NEXT_PUBLIC_VAPID_PUBLIC_KEY = (unset — Web Push disabled; run 'php artisan push:vapid')"
fi
echo

cd "$FRONTEND_DIR"
# `npm run deploy` = opennextjs-cloudflare build && opennextjs-cloudflare deploy.
# NEXT_PUBLIC_* values are inlined into the bundle during the build step.
npm run deploy
