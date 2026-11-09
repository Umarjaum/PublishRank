#!/usr/bin/env bash
# Fails when an active PublishRank version marker disagrees with the plugin header.
# Historical release notes may legitimately mention older versions.
# Usage: tools/check-version.sh (run from anywhere)
set -u
root="$(cd "$(dirname "$0")/.." && pwd)"
header=$(grep -m1 -E '^ \* Version:|^Version:' "$root/publishrank.php" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r ')
status=0
check() { # label value
  if [ "$2" = "$header" ]; then printf 'ok    %-34s %s\n' "$1" "$2"; else printf 'FAIL  %-34s %s (expected %s)\n' "$1" "${2:-missing}" "$header"; status=1; fi
}
check "publishrank.php constant" "$(grep -m1 "PUBLISHRANK_SEO_VERSION'" "$root/publishrank.php" | sed -E "s/.*'PUBLISHRANK_SEO_VERSION',[[:space:]]*'([^']+)'.*/\1/")"
check "readme.txt Stable tag" "$(grep -m1 '^Stable tag:' "$root/readme.txt" | sed -E 's/Stable tag:[[:space:]]*//' | tr -d '\r ')"
check "readme.txt top changelog" "$(grep -m1 -E '^= [0-9]' "$root/readme.txt" | sed -E 's/= ([^ ]+) =.*/\1/')"
check "changelog.txt top entry" "$(grep -m1 -E '^= [0-9]' "$root/changelog.txt" | sed -E 's/= ([^ ]+) =.*/\1/')"
check "Branding fallback" "$(grep -m1 -E "'[0-9]+\.[0-9]+\.[0-9]+';" "$root/src/Core/Branding.php" | sed -E "s/.*'([0-9.]+)';.*/\1/")"
stale=$(grep -rnE --exclude='RELEASE-*' --exclude='AUDIT-INVENTORY.md' '\b2\.[0-9]+\.[0-9]+\b' "$root/docs" "$root/src/Admin/HelpDocumentationPage.php" 2>/dev/null \
  | grep -vE "\b$header\b|changelog|Schema|WordPress|PHP" \
  | grep -vEi '(^|:)[[:space:]]*(#{1,6}[[:space:]]*)?(New in|Added in)|[0-9]+\.[0-9]+\.[0-9]+[[:space:]]+(adds?|additions?|build note|introduced)\b' \
  | grep -iE 'version|release|package|through' || true)
if [ -n "$stale" ]; then echo "FAIL  stale version text in active docs:"; echo "$stale"; status=1; else echo "ok    docs and help page"; fi
exit $status
