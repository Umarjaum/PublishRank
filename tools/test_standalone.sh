#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
mapfile -t tests < <(find tests -maxdepth 1 -type f -name '*.php' -print | sort)
if ((${#tests[@]} == 0)); then
  echo "No standalone PHP tests found." >&2
  exit 1
fi
for test in "${tests[@]}"; do
  printf '\n==> %s\n' "$test"
  php "$test"
done
printf '\n==> PHP syntax lint\n'
find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
printf '\nAll %d standalone PHP tests and PHP syntax checks passed.\n' "${#tests[@]}"
