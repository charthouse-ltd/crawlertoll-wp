#!/usr/bin/env bash
#
# CrawlerToll end-to-end test rig.
#
# Stands up a REAL WordPress (real PHP + SQLite, no MySQL) with the working-tree
# plugin copied in and Pro unlocked via CRAWLERTOLL_PRO_DEV, then exercises it
# over HTTP and asserts real responses. This is the gold-standard check — the
# mock test-harness can't see WP's request lifecycle, header emission, or DB.
#
#   e2e/run.sh           build, test, tear down (exit 0 = all passed)
#   e2e/run.sh --keep    leave the server running afterwards (prints the URL)
#
# WP core + the SQLite drop-in are cached under ~/.cache/crawlertoll-e2e so
# re-runs are fast. wp-cli is NOT used (it fatals on PHP 8.5) — see install.php.

set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="$(cd "$HERE/.." && pwd)"
WP_DIR="${CT_E2E_WP:-/tmp/ct-e2e-wp}"
PORT="${CT_E2E_PORT:-8099}"
HOST="127.0.0.1:${PORT}"
BASE="http://${HOST}"
CACHE="${HOME}/.cache/crawlertoll-e2e"
KEEP=0; [ "${1:-}" = "--keep" ] && KEEP=1

pass=0; fail=0
ok() { printf '  \033[32mPASS\033[0m %s\n' "$1"; pass=$((pass+1)); }
no() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; fail=$((fail+1)); }
die() { echo "e2e setup error: $1"; [ -f /tmp/ct-e2e-php.log ] && tail -20 /tmp/ct-e2e-php.log; exit 1; }

SRV=""
cleanup() { if [ "$KEEP" != "1" ] && [ -n "$SRV" ]; then kill "$SRV" 2>/dev/null; fi; }
trap cleanup EXIT

echo "== 1/8 cache WP core + SQLite drop-in =="
mkdir -p "$CACHE"
[ -f "$CACHE/wordpress.zip" ] || curl -fsSL https://wordpress.org/latest.zip -o "$CACHE/wordpress.zip" || die "WP download failed"
if [ ! -d "$CACHE/sqlite-database-integration" ]; then
	curl -fsSL https://downloads.wordpress.org/plugin/sqlite-database-integration.zip -o "$CACHE/sqlite.zip" || die "SQLite drop-in download failed"
	unzip -qo "$CACHE/sqlite.zip" -d "$CACHE" || die "SQLite unzip failed"
fi

echo "== 2/8 fresh WP tree =="
pkill -f "php -S ${HOST}" 2>/dev/null || true
sleep 0.3
rm -rf "$WP_DIR"; mkdir -p "$WP_DIR"
unzip -qo "$CACHE/wordpress.zip" -d "$WP_DIR" || die "WP unzip failed"
mv "$WP_DIR"/wordpress/* "$WP_DIR"/ && rmdir "$WP_DIR"/wordpress
mkdir -p "$WP_DIR/wp-content/plugins"
cp -R "$CACHE/sqlite-database-integration" "$WP_DIR/wp-content/plugins/"
cp "$WP_DIR/wp-content/plugins/sqlite-database-integration/db.copy" "$WP_DIR/wp-content/db.php"

echo "== 3/8 copy working-tree plugin =="
mkdir -p "$WP_DIR/wp-content/plugins/crawlertoll"
( cd "$PLUGIN_DIR" && tar --exclude='./e2e' --exclude='./tests' --exclude='./.git' --exclude='./*.zip' -cf - . ) \
	| ( cd "$WP_DIR/wp-content/plugins/crawlertoll" && tar -xf - ) || die "plugin copy failed"

echo "== 4/8 wp-config (Pro unlocked) =="
cat > "$WP_DIR/wp-config.php" <<'WPCONF'
<?php
define('DB_NAME','wordpress'); define('DB_USER','root'); define('DB_PASSWORD',''); define('DB_HOST','localhost');
define('DB_CHARSET','utf8'); define('DB_COLLATE','');
define('AUTH_KEY','ct-e2e'); define('SECURE_AUTH_KEY','ct-e2e'); define('LOGGED_IN_KEY','ct-e2e'); define('NONCE_KEY','ct-e2e');
define('AUTH_SALT','ct-e2e'); define('SECURE_AUTH_SALT','ct-e2e'); define('LOGGED_IN_SALT','ct-e2e'); define('NONCE_SALT','ct-e2e');
$table_prefix='wp_';
define('WP_DEBUG', true); define('WP_DEBUG_DISPLAY', false); define('WP_DEBUG_LOG', false);
define('CRAWLERTOLL_PRO_DEV', true);  // unlock Pro for e2e without a Freemius license
if ( ! defined('ABSPATH') ) define('ABSPATH', __DIR__ . '/');
require_once ABSPATH . 'wp-settings.php';
WPCONF

echo "== 5/8 php -S router =="
cat > "$WP_DIR/router.php" <<'ROUTER'
<?php
// php -S router: serve real static files, route everything else to WP so
// non-existent paths (e.g. /premium/report/) still hit parse_request.
$root = __DIR__;
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$abs  = realpath($root . $path);
if ($path !== '/' && $abs && strpos($abs, $root) === 0 && is_file($abs)) {
	return false;
}
require $root . '/index.php';
ROUTER

echo "== 6/8 install + activate =="
php "$HERE/install.php" "$WP_DIR" "$PORT" || die "install failed"

echo "== 7/8 start server + configure =="
php -S "$HOST" -t "$WP_DIR" "$WP_DIR/router.php" >/tmp/ct-e2e-php.log 2>&1 &
SRV=$!
up=0
for _ in $(seq 1 40); do
	if curl -fsS -o /dev/null "$BASE/wp-login.php" 2>/dev/null; then up=1; break; fi
	sleep 0.3
done
[ "$up" = "1" ] || die "server did not come up on $BASE"
php "$HERE/configure.php" "$WP_DIR" || die "configure failed"

echo "== 8/8 assertions =="
code()   { curl -sS -o /dev/null -w '%{http_code}' -H "user-agent: $1" "$BASE$2"; }
hdr()    { curl -sS -D - -o /dev/null -H "user-agent: $1" "$BASE$2" | tr -d '\r' | awk -F': ' -v h="$3" '$1==h{print $2; exit}'; }

# — enforcement core —
c="$(code 'Mozilla/5.0' '/')";                                  [ "$c" = "200" ] && ok "browser / → 200" || no "browser / → 200 (got $c)"
c="$(code 'GPTBot/1.2' '/about/')";                             [ "$c" = "402" ] && ok "GPTBot /about/ → 402" || no "GPTBot /about/ → 402 (got $c)"
a="$(hdr 'GPTBot/1.2' '/wp-content/uploads/x.jpg' 'X-CrawlerToll-Action')"; [ "$a" = "allow" ] && ok "GPTBot allowed path → action=allow" || no "GPTBot allowed path → action=allow (got '$a')"

# — per-path pricing (§2.4) —
p="$(hdr 'GPTBot/1.2' '/premium/report/' 'Crawler-Price')";    [ "$p" = "50000 micros USD" ] && ok "/premium/* priced 50000" || no "/premium/* price (got '$p')"
p="$(hdr 'GPTBot/1.2' '/blog/post/' 'Crawler-Price')";         [ "$p" = "2000 micros USD" ]  && ok "/blog/ priced 2000"     || no "/blog/ price (got '$p')"
p="$(hdr 'GPTBot/1.2' '/about/' 'Crawler-Price')";             [ "$p" = "5000 micros USD" ]  && ok "default priced 5000"    || no "default price (got '$p')"

echo
echo "== cron + email e2e (alerts §2.3) =="
php "$HERE/test-alerts.php" "$WP_DIR" || fail=$((fail+1))

echo
echo "== retention purge e2e (§2.2) =="
php "$HERE/test-retention.php" "$WP_DIR" || fail=$((fail+1))

echo
echo "== React pro-app mount (revenue tab + REST seam + /stats live) =="
php "$HERE/test-pro-react-mount.php" "$WP_DIR" || fail=$((fail+1))
# The pro-app bundle the revenue tab references must be SERVED by real WP.
bundle_js="$(ls "$WP_DIR"/wp-content/plugins/crawlertoll/assets/app/pro-app/*.js 2>/dev/null | head -1)"
if [ -n "$bundle_js" ]; then
	rel="/wp-content/plugins/crawlertoll/assets/app/pro-app/$(basename "$bundle_js")"
	c="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE$rel")"
	[ "$c" = "200" ] && ok "pro-app bundle served over HTTP (…/$(basename "$bundle_js") → 200)" || no "pro-app bundle HTTP (…/$(basename "$bundle_js") → $c)"
else
	no "pro-app bundle missing from installed plugin"
fi

echo
echo "e2e: ${pass} HTTP assertions passed, ${fail} failed (incl. alerts + retention + pro-mount blocks)"
[ "$KEEP" = "1" ] && echo "server kept at ${BASE} (login admin/password)"
[ "$fail" = "0" ]
