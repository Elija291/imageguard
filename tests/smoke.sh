#!/bin/bash
# Run after starting the container. Uses only local HTTP; no detector quota consumed.
set -euo pipefail
base="${1:-http://127.0.0.1:8080}"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
curl -fsS -D "$tmp/headers" -c "$tmp/cookies" "$base/" > "$tmp/page"
grep -q 'Sign in to analyse' "$tmp/page"
grep -qi 'X-Content-Type-Options: nosniff' "$tmp/headers"
grep -qi 'Content-Security-Policy:' "$tmp/headers"
code=$(curl -sS -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -d 'action=analyse' "$base/")
[ "$code" = 403 ]
for path in /.env /app/bootstrap.php /docker/init.php /uploads/test.php; do
 code=$(curl -sS -o /dev/null -w '%{http_code}' "$base$path")
 [[ "$code" == 403 || "$code" == 404 ]]
done
echo 'PASS: login page, security headers, CSRF rejection, private-path denial'
