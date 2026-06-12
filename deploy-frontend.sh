#!/usr/bin/env bash
#
# Deploy the Next.js frontend (frontend/) to Cloudflare via OpenNext.
#
# The build-time NEXT_PUBLIC_API_URL is derived from REMOTE_BACKEND_URL, resolved
# from (in order): an exported env var, then the root .env. It must be set —
# otherwise we refuse to deploy (a wrong/empty API URL bakes into the bundle).
#
# Usage:
#   ./deploy-frontend.sh
#   REMOTE_BACKEND_URL=https://api.example.com ./deploy-frontend.sh
#
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FRONTEND_DIR="$ROOT_DIR/frontend"

# --- Resolve REMOTE_BACKEND_URL ------------------------------------------------
backend_url="${REMOTE_BACKEND_URL:-}"

if [[ -z "$backend_url" && -f "$ROOT_DIR/.env" ]]; then
  line="$(grep -E '^[[:space:]]*REMOTE_BACKEND_URL=' "$ROOT_DIR/.env" | tail -n1 || true)"
  backend_url="${line#*=}"
  # Strip surrounding single/double quotes and whitespace.
  backend_url="${backend_url%\"}"; backend_url="${backend_url#\"}"
  backend_url="${backend_url%\'}"; backend_url="${backend_url#\'}"
  backend_url="$(printf '%s' "$backend_url" | tr -d '[:space:]')"
fi

if [[ -z "$backend_url" ]]; then
  echo "Error: REMOTE_BACKEND_URL is not set." >&2
  echo "       Export it, or add it to $ROOT_DIR/.env, e.g.:" >&2
  echo "       REMOTE_BACKEND_URL=https://api.your-domain.com" >&2
  exit 1
fi

# Normalise: drop a trailing slash, then append the API path the frontend expects.
backend_url="${backend_url%/}"
export NEXT_PUBLIC_API_URL="$backend_url/api/v1"

# --- Deploy --------------------------------------------------------------------
echo "==> Deploying frontend to Cloudflare"
echo "    REMOTE_BACKEND_URL = $backend_url"
echo "    NEXT_PUBLIC_API_URL = $NEXT_PUBLIC_API_URL"
echo

cd "$FRONTEND_DIR"
# `npm run deploy` = opennextjs-cloudflare build && opennextjs-cloudflare deploy.
# NEXT_PUBLIC_API_URL is inlined into the bundle during the build step.
npm run deploy
