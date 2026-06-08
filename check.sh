#!/usr/bin/env bash
#
# Runs the full check suite for both the Laravel backend and the Next.js
# frontend, then prints a combined pass/fail summary.
#
#   Backend  (./)         -> composer check   (Pint lint + PHPStan + parallel tests)
#   Frontend (./frontend) -> npm run check    (tsc typecheck + Vitest tests)
#
# Usage:
#   ./check.sh           # both
#   ./check.sh be        # backend only
#   ./check.sh fe        # frontend only
#
# Exit code is non-zero if either side fails. Both sides always run (a backend
# failure does not skip the frontend), so you see every problem in one pass.

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
run_fe=false
case "$target" in
  all) run_be=true; run_fe=true ;;
  be|backend) run_be=true ;;
  fe|frontend) run_fe=true ;;
  *)
    echo "Unknown target '$target' (expected: all | be | fe)" >&2
    exit 2
    ;;
esac

be_status="skipped"
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

# ---- Frontend ----------------------------------------------------------------
if $run_fe; then
  section "Frontend checks (npm run check)"
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
printf '  Frontend : %s\n' "$(badge "$fe_status")"

if [ "$be_status" = "fail" ] || [ "$fe_status" = "fail" ]; then
  exit 1
fi
exit 0