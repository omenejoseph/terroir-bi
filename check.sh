#!/usr/bin/env bash
#
# Runs the full check suite for both the Laravel backend and the Next.js
# frontend, then prints a combined pass/fail summary.
#
#   Backend  (./)         -> composer check   (Pint lint + PHPStan + parallel tests)
#   Inertia  (./)         -> npm run check    (vue-tsc typecheck + Vite build)
#   Frontend (./frontend) -> npm run check    (tsc typecheck + Vitest tests)
#
# The Inertia (Vue) app and the legacy Next.js app in ./frontend run side by
# side while modules are ported across; ./frontend goes away once parity lands.
#
# Usage:
#   ./check.sh           # all three
#   ./check.sh be        # backend only
#   ./check.sh in        # Inertia frontend only
#   ./check.sh fe        # legacy Next.js frontend only
#
# Exit code is non-zero if any side fails. Every side always runs (a backend
# failure does not skip the others), so you see every problem in one pass.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Colours (disabled when output isn't a terminal).
if [ -t 1 ]; then
  BOLD=$'\033[1m'; RED=$'\033[31m'; GREEN=$'\033[32m'; CYAN=$'\033[36m'; RESET=$'\033[0m'
else
  BOLD=""; RED=""; GREEN=""; CYAN=""; RESET=""
fi

target="${1:-all}"
run_be=false
run_in=false
run_fe=false
case "$target" in
  all) run_be=true; run_in=true; run_fe=true ;;
  be|backend) run_be=true ;;
  in|inertia) run_in=true ;;
  fe|frontend) run_fe=true ;;
  *)
    echo "Unknown target '$target' (expected: all | be | in | fe)" >&2
    exit 2
    ;;
esac

be_status="skipped"
in_status="skipped"
fe_status="skipped"

section() { printf '\n%s==> %s%s\n' "$BOLD$CYAN" "$1" "$RESET"; }

# ---- Backend -----------------------------------------------------------------
if $run_be; then
  section "Backend checks (composer check)"
  if ! command -v composer >/dev/null 2>&1; then
    echo "${RED}composer not found on PATH${RESET}" >&2
    be_status="fail"
  else
    if (cd "$ROOT" && composer check); then
      be_status="pass"
    else
      be_status="fail"
    fi
  fi
fi

# ---- Inertia frontend (root) -------------------------------------------------
if $run_in; then
  section "Inertia frontend checks (npm run check)"
  if ! command -v npm >/dev/null 2>&1; then
    echo "${RED}npm not found on PATH${RESET}" >&2
    in_status="fail"
  else
    if [ ! -d "$ROOT/node_modules" ]; then
      echo "Installing root dependencies (node_modules missing)…"
      (cd "$ROOT" && npm ci) || (cd "$ROOT" && npm install)
    fi
    if (cd "$ROOT" && npm run check); then
      in_status="pass"
    else
      in_status="fail"
    fi
  fi
fi

# ---- Legacy Next.js frontend -------------------------------------------------
if $run_fe; then
  section "Legacy Next.js frontend checks (npm run check)"
  if ! command -v npm >/dev/null 2>&1; then
    echo "${RED}npm not found on PATH${RESET}" >&2
    fe_status="fail"
  else
    # Install deps on first run so the script is usable from a clean checkout.
    if [ ! -d "$ROOT/frontend/node_modules" ]; then
      echo "Installing frontend dependencies (node_modules missing)…"
      (cd "$ROOT/frontend" && npm ci) || (cd "$ROOT/frontend" && npm install)
    fi
    if (cd "$ROOT/frontend" && npm run check); then
      fe_status="pass"
    else
      fe_status="fail"
    fi
  fi
fi

# ---- Summary -----------------------------------------------------------------
badge() {
  case "$1" in
    pass) printf '%sPASS%s' "$GREEN" "$RESET" ;;
    fail) printf '%sFAIL%s' "$RED" "$RESET" ;;
    *)    printf 'skipped' ;;
  esac
}

printf '\n%s==> Summary%s\n' "$BOLD$CYAN" "$RESET"
printf '  Backend  : %s\n' "$(badge "$be_status")"
printf '  Inertia  : %s\n' "$(badge "$in_status")"
printf '  Frontend : %s\n' "$(badge "$fe_status")"

if [ "$be_status" = "fail" ] || [ "$in_status" = "fail" ] || [ "$fe_status" = "fail" ]; then
  exit 1
fi
exit 0