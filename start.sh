#!/usr/bin/env bash
#
# Starts the Next.js frontend dev server from the repo root, after verifying the
# backend API it's configured to talk to is actually reachable.
#
# The API URL is read from the frontend env files (same precedence Next uses):
#   frontend/.env.local  >  frontend/.env  >  frontend/.env.local.example
#
# Usage:
#   ./start.sh                # check backend, then start the FE dev server
#   ./start.sh --check-only   # only verify the backend is up, don't start the FE
#
# Exits non-zero (without starting the FE) if the backend can't be reached.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FE="$ROOT/frontend"

if [ -t 1 ]; then
  BOLD=$'\033[1m'; RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; CYAN=$'\033[36m'; RESET=$'\033[0m'
else
  BOLD=""; RED=""; GREEN=""; YELLOW=""; CYAN=""; RESET=""
fi

check_only=false
[ "${1:-}" = "--check-only" ] && check_only=true

# Read a KEY=value from an env file (last match wins, quotes/CR stripped).
read_env() {
  local key="$1" file="$2"
  [ -f "$file" ] || return 1
  local line
  line="$(grep -E "^[[:space:]]*${key}=" "$file" | tail -n1)" || return 1
  [ -n "$line" ] || return 1
  local val="${line#*=}"
  val="${val%$'\r'}"                 # strip CR
  val="${val%\"}"; val="${val#\"}"   # strip surrounding double quotes
  val="${val%\'}"; val="${val#\'}"   # strip surrounding single quotes
  printf '%s' "$val"
}

# Resolve NEXT_PUBLIC_API_URL across the env files in Next's precedence order.
API_URL=""
for f in "$FE/.env.local" "$FE/.env" "$FE/.env.local.example"; do
  if API_URL="$(read_env "NEXT_PUBLIC_API_URL" "$f")"; then
    echo "Using NEXT_PUBLIC_API_URL from ${f#"$ROOT"/} -> $API_URL"
    break
  fi
done

if [ -z "$API_URL" ]; then
  echo "${RED}Could not find NEXT_PUBLIC_API_URL in any frontend env file.${RESET}" >&2
  echo "Create $FE/.env.local (see .env.local.example)." >&2
  exit 1
fi

# Derive the backend origin (scheme://host[:port]) from the API URL.
scheme="${API_URL%%://*}"
rest="${API_URL#*://}"
hostport="${rest%%/*}"
ORIGIN="$scheme://$hostport"

printf '\n%s==> Checking backend at %s%s\n' "$BOLD$CYAN" "$ORIGIN" "$RESET"

# Laravel exposes a health route at /up. 000 means the connection failed.
code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$ORIGIN/up" 2>/dev/null || true)"

if [ "$code" = "000" ] || [ -z "$code" ]; then
  echo "${RED}Backend not reachable at $ORIGIN${RESET}" >&2
  echo "Start it first, e.g.:" >&2
  echo "  • Herd/Valet: ensure the site is served (open $ORIGIN)" >&2
  echo "  • or: composer dev   (from $ROOT)" >&2
  echo "  • or: php artisan serve" >&2
  exit 1
elif [ "$code" = "200" ]; then
  echo "${GREEN}Backend is up (/up -> 200).${RESET}"
else
  echo "${YELLOW}Backend reachable but /up returned $code — continuing.${RESET}"
fi

if $check_only; then
  exit 0
fi

# Install deps on first run so this works from a clean checkout.
if [ ! -d "$FE/node_modules" ]; then
  echo "Installing frontend dependencies (node_modules missing)…"
  (cd "$FE" && npm ci) || (cd "$FE" && npm install)
fi

# Find a free port, incrementing from the desired one (like `php artisan serve`).
port_in_use() { lsof -iTCP:"$1" -sTCP:LISTEN -t >/dev/null 2>&1; }

PORT="${PORT:-3000}"
desired="$PORT"
attempts=0
while port_in_use "$PORT"; do
  attempts=$((attempts + 1))
  if [ "$attempts" -gt 50 ]; then
    echo "${RED}No free port found in ${desired}-$((desired + 50)).${RESET}" >&2
    exit 1
  fi
  PORT=$((PORT + 1))
done

if [ "$PORT" != "$desired" ]; then
  echo "${YELLOW}Port $desired in use — using $PORT instead.${RESET}"
fi

printf '\n%s==> Starting frontend dev server on http://localhost:%s%s\n' "$BOLD$CYAN" "$PORT" "$RESET"
exec npm --prefix "$FE" run dev -- --port "$PORT"