#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

mapfile -t tests < <(
  find tests -maxdepth 1 -type f -name '*.php' \
    ! -name 'bootstrap.php' \
    ! -name 'run.php' \
    ! -name 'PublishRankTestCase.php' \
    -print | sort
)
if ((${#tests[@]} == 0)); then
  echo "No standalone PHP tests found." >&2
  exit 1
fi
for test in "${tests[@]}"; do
  printf '\n==> %s\n' "$test"
  php "$test"
done

if [[ -n "${PR_WP_PATH:-}" ]]; then
  printf '\n==> WordPress integration tests\n'
  php tests/run.php
else
  printf '\nSkipping WordPress integration tests (set PR_WP_PATH to a disposable WordPress install to enable them).\n'
fi

printf '\n==> PHP syntax lint\n'
find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
printf '\nAll %d standalone PHP tests and PHP syntax checks passed.\n' "${#tests[@]}"
