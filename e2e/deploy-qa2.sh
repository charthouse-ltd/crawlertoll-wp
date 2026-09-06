#!/usr/bin/env bash
#
# Deploy the current working tree's PREMIUM build to the live QA WordPress
# (W9, 2026-09-06). Run by the operator — the box only accepts the operator's
# SSH key. Idempotent: builds both zips, ships the premium one, swaps the
# plugin directory atomically (old copy kept as crawlertoll.prev), then
# smoke-checks the site.
#
#   QA2_SSH=deploy@85.10.200.55 QA2_WP=/var/www/qa2/htdocs e2e/deploy-qa2.sh
#
# Required env:
#   QA2_SSH   ssh target (user@host)
#   QA2_WP    absolute path of the WordPress root on the box (contains wp-content)
# Optional:
#   QA2_URL   public URL for the smoke check (default https://qa2.85.10.200.55.nip.io)
#   QA2_AUTH  basic-auth user:pass for the smoke check (the e2e/browser suites carry it)
#
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
: "${QA2_SSH:?set QA2_SSH=user@host}"
: "${QA2_WP:?set QA2_WP=/path/to/wordpress}"
QA2_URL="${QA2_URL:-https://qa2.85.10.200.55.nip.io}"

echo "== build =="
( cd "$HERE" && ./build.sh >/tmp/ct-deploy-build.log 2>&1 ) || { echo "build failed:"; tail -20 /tmp/ct-deploy-build.log; exit 1; }
ZIP="$HERE/build/crawlertoll-pro.zip"
[ -f "$ZIP" ] || { echo "missing $ZIP"; exit 1; }

echo "== ship $(du -h "$ZIP" | cut -f1) to $QA2_SSH =="
scp -q "$ZIP" "$QA2_SSH:/tmp/crawlertoll-pro.zip"

echo "== swap plugin on the box =="
ssh "$QA2_SSH" bash -s <<EOF
set -euo pipefail
cd "$QA2_WP/wp-content/plugins"
rm -rf crawlertoll.new && mkdir crawlertoll.new
unzip -q /tmp/crawlertoll-pro.zip -d crawlertoll.new
rm -rf crawlertoll.prev
[ -d crawlertoll ] && mv crawlertoll crawlertoll.prev
mv crawlertoll.new/crawlertoll crawlertoll && rmdir crawlertoll.new
rm -f /tmp/crawlertoll-pro.zip
# keep the local dev constants the QA box relies on (registry override etc.) — they live in wp-config, untouched
command -v wp >/dev/null 2>&1 && wp --path="$QA2_WP" cache flush >/dev/null 2>&1 || true
echo "deployed: \$(grep -m1 'Version:' crawlertoll/crawlertoll.php)"
EOF

echo "== smoke =="
AUTH=()
[ -n "${QA2_AUTH:-}" ] && AUTH=( -u "$QA2_AUTH" )
code="$(curl -sk -m 20 -o /dev/null -w '%{http_code}' "${AUTH[@]}" "$QA2_URL/")"
echo "GET $QA2_URL/ → $code"
bot="$(curl -sk -m 20 -o /dev/null -w '%{http_code}' "${AUTH[@]}" -A 'GPTBot/1.2' "$QA2_URL/?s=x")"
echo "GPTBot → $bot (402 expected on a charged path)"
[ "$code" = "200" ] || { echo "site not healthy — roll back with: ssh $QA2_SSH 'cd $QA2_WP/wp-content/plugins && rm -rf crawlertoll && mv crawlertoll.prev crawlertoll'"; exit 1; }
echo "ok — run the browser suites: cd e2e/browser && node renewal-e2e.js"
