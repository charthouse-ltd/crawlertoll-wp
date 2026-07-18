#!/usr/bin/env bash
#
# CrawlerToll FREE-BUILD end-to-end rig.
#
# Builds the stripped free wp.org zip (build.sh), stands up a REAL WordPress with
# THAT zip as the active plugin and Pro OFF (no CRAWLERTOLL_PRO_DEV, no Freemius),
# and asserts the plugin degrades gracefully: free enforcement still works, every
# premium feature is inert, and nothing fatals. This is the proof that the
# __premium_only strip is safe to ship to wp.org.
#
#   e2e/run-free.sh           build, test, tear down (exit 0 = all passed)
#   e2e/run-free.sh --keep    leave the server running (prints the URL)
#
set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="$(cd "$HERE/.." && pwd)"
WP_DIR="${CT_E2E_FREE_WP:-/tmp/ct-e2e-free-wp}"
PORT="${CT_E2E_FREE_PORT:-8100}"
HOST="127.0.0.1:${PORT}"
BASE="http://${HOST}"
CACHE="${HOME}/.cache/crawlertoll-e2e"
KEEP=0; [ "${1:-}" = "--keep" ] && KEEP=1

pass=0; fail=0
ok() { printf '  \033[32mPASS\033[0m %s\n' "$1"; pass=$((pass+1)); }
no() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; fail=$((fail+1)); }
die() { echo "free-e2e setup error: $1"; [ -f /tmp/ct-e2e-free-php.log ] && tail -20 /tmp/ct-e2e-free-php.log; exit 1; }

SRV=""
cleanup() { if [ "$KEEP" != "1" ] && [ -n "$SRV" ]; then kill "$SRV" 2>/dev/null; fi; }
trap cleanup EXIT

echo "== 1/8 build the free zip =="
( cd "$PLUGIN_DIR" && ./build.sh >/tmp/ct-e2e-free-build.log 2>&1 ) || { cat /tmp/ct-e2e-free-build.log; die "build.sh failed"; }
[ -f "$PLUGIN_DIR/build/crawlertoll.zip" ] || die "free zip not produced"

echo "== 2/8 cache WP core + SQLite drop-in =="
mkdir -p "$CACHE"
[ -f "$CACHE/wordpress.zip" ] || curl -fsSL https://wordpress.org/latest.zip -o "$CACHE/wordpress.zip" || die "WP download failed"
if [ ! -d "$CACHE/sqlite-database-integration" ]; then
	curl -fsSL https://downloads.wordpress.org/plugin/sqlite-database-integration.zip -o "$CACHE/sqlite.zip" || die "SQLite drop-in download failed"
	unzip -qo "$CACHE/sqlite.zip" -d "$CACHE" || die "SQLite unzip failed"
fi

echo "== 3/8 fresh WP tree =="
pkill -f "php -S ${HOST}" 2>/dev/null || true
sleep 0.3
rm -rf "$WP_DIR"; mkdir -p "$WP_DIR"
unzip -qo "$CACHE/wordpress.zip" -d "$WP_DIR" || die "WP unzip failed"
mv "$WP_DIR"/wordpress/* "$WP_DIR"/ && rmdir "$WP_DIR"/wordpress
mkdir -p "$WP_DIR/wp-content/plugins"
cp -R "$CACHE/sqlite-database-integration" "$WP_DIR/wp-content/plugins/"
cp "$WP_DIR/wp-content/plugins/sqlite-database-integration/db.copy" "$WP_DIR/wp-content/db.php"

echo "== 4/8 install the FREE ZIP as the plugin (not the working tree) =="
unzip -qo "$PLUGIN_DIR/build/crawlertoll.zip" -d "$WP_DIR/wp-content/plugins/" || die "free zip unzip failed"
# Sanity: the unzipped free plugin must carry NO premium class files.
if ls "$WP_DIR"/wp-content/plugins/crawlertoll/includes/class-crawlertoll-{db,logger,pricing,alerts,provenance,catalogue-updater}.php >/dev/null 2>&1; then
	die "free plugin tree still contains premium class files"
fi

echo "== 5/8 wp-config (Pro OFF — no CRAWLERTOLL_PRO_DEV) =="
cat > "$WP_DIR/wp-config.php" <<'WPCONF'
<?php
define('DB_NAME','wordpress'); define('DB_USER','root'); define('DB_PASSWORD',''); define('DB_HOST','localhost');
define('DB_CHARSET','utf8'); define('DB_COLLATE','');
define('AUTH_KEY','ct-e2e'); define('SECURE_AUTH_KEY','ct-e2e'); define('LOGGED_IN_KEY','ct-e2e'); define('NONCE_KEY','ct-e2e');
define('AUTH_SALT','ct-e2e'); define('SECURE_AUTH_SALT','ct-e2e'); define('LOGGED_IN_SALT','ct-e2e'); define('NONCE_SALT','ct-e2e');
$table_prefix='wp_';
define('WP_DEBUG', true); define('WP_DEBUG_DISPLAY', false); define('WP_DEBUG_LOG', false);
// NOTE: deliberately NO CRAWLERTOLL_PRO_DEV — this proves the free build with Pro OFF.
if ( ! defined('ABSPATH') ) define('ABSPATH', __DIR__ . '/');
require_once ABSPATH . 'wp-settings.php';
WPCONF

echo "== 6/8 php -S router =="
cat > "$WP_DIR/router.php" <<'ROUTER'
<?php
$root = __DIR__;
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$abs  = realpath($root . $path);
if ($path !== '/' && $abs && strpos($abs, $root) === 0 && is_file($abs)) {
	return false;
}
require $root . '/index.php';
ROUTER

echo "== 7/8 install + activate (activation must not fatal with DB stripped) =="
php "$HERE/install.php" "$WP_DIR" "$PORT" || die "install/activation failed (free build fataled on activate?)"

php -S "$HOST" -t "$WP_DIR" "$WP_DIR/router.php" >/tmp/ct-e2e-free-php.log 2>&1 &
SRV=$!
up=0
for _ in $(seq 1 40); do
	if curl -fsS -o /dev/null "$BASE/wp-login.php" 2>/dev/null; then up=1; break; fi
	sleep 0.3
done
[ "$up" = "1" ] || die "server did not come up on $BASE"
php "$HERE/configure.php" "$WP_DIR" || die "configure failed"
php "$HERE/set-permalinks.php" "$WP_DIR" || die "permalink setup failed"

echo "== 8/8 assertions (free build, Pro OFF) =="
code()   { curl -sS -o /dev/null -w '%{http_code}' -H "user-agent: $1" "$BASE$2"; }
hdr()    { curl -sS -D - -o /dev/null -H "user-agent: $1" "$BASE$2" | tr -d '\r' | awk -F': ' -v h="$3" '$1==h{print $2; exit}'; }
body()   { curl -sS -H "user-agent: $1" "$BASE$2"; }

# — free enforcement core still works —
c="$(code 'Mozilla/5.0' '/')";                                  [ "$c" = "200" ] && ok "browser / → 200" || no "browser / → 200 (got $c)"
c="$(code 'GPTBot/1.2' '/about/')";                             [ "$c" = "402" ] && ok "GPTBot /about/ → 402" || no "GPTBot /about/ → 402 (got $c)"
a="$(hdr 'GPTBot/1.2' '/wp-content/uploads/x.jpg' 'X-CrawlerToll-Action')"; [ "$a" = "allow" ] && ok "GPTBot allowed path → action=allow" || no "GPTBot allowed path → action=allow (got '$a')"

# — per-path pricing is PRO: free build must use the FLAT price everywhere (NOT 50000/2000) —
p="$(hdr 'GPTBot/1.2' '/premium/report/' 'Crawler-Price')";    [ "$p" = "5000 micros USD" ] && ok "/premium/* FLAT 5000 (per-path pricing OFF)" || no "/premium/* should be flat 5000 (got '$p')"
p="$(hdr 'GPTBot/1.2' '/blog/post/' 'Crawler-Price')";         [ "$p" = "5000 micros USD" ] && ok "/blog/ FLAT 5000 (per-path pricing OFF)"     || no "/blog/ should be flat 5000 (got '$p')"
p="$(hdr 'GPTBot/1.2' '/about/' 'Crawler-Price')";             [ "$p" = "5000 micros USD" ] && ok "default 5000"                                || no "default price (got '$p')"

# — rail falls back to the flat site rail (multi-rail is Pro) —
r="$(hdr 'GPTBot/1.2' '/about/' 'Crawler-Price-Rail')";        [ -n "$r" ] && ok "402 still carries a rail ('$r')" || no "402 missing rail header"

# — discovery endpoints (free) —
c="$(code 'Mozilla/5.0' '/robots.txt')";                       [ "$c" = "200" ] && ok "robots.txt → 200" || no "robots.txt → 200 (got $c)"
b="$(body 'Mozilla/5.0' '/robots.txt')";                       echo "$b" | grep -q "CrawlerToll" && ok "robots.txt carries CrawlerToll directives" || no "robots.txt missing CrawlerToll block"
c="$(code 'Mozilla/5.0' '/.well-known/context-license.json')"; [ "$c" = "200" ] && ok "context-license → 200" || no "context-license → 200 (got $c)"
b="$(body 'Mozilla/5.0' '/.well-known/context-license.json')"; echo "$b" | grep -q "admin_email\|@" && no "context-license leaks an email" || ok "context-license has no PII"

echo
echo "== degradation invariants (premium classes absent, gate false, no fatal) =="
php "$HERE/test-free-degradation.php" "$WP_DIR" || fail=$((fail+1))

echo
echo "== WS3 premium-gate leak probe (sealed body must never reach a non-editor) =="
php "$HERE/test-premium-gate.php" "$WP_DIR" || fail=$((fail+1))

echo
echo "== React free-app mount (real wp-admin emits scaffolding + serves bundle) =="
php "$HERE/test-free-react-mount.php" "$WP_DIR" || fail=$((fail+1))
# The bundle the settings page references must be SERVED by real WP (php -S static route).
bundle_js="$(ls "$WP_DIR"/wp-content/plugins/crawlertoll/assets/app/free-app/*.js 2>/dev/null | head -1)"
if [ -n "$bundle_js" ]; then
	rel="/wp-content/plugins/crawlertoll/assets/app/free-app/$(basename "$bundle_js")"
	c="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE$rel")"
	[ "$c" = "200" ] && ok "free-app bundle served over HTTP (…/$(basename "$bundle_js") → 200)" || no "free-app bundle HTTP (…/$(basename "$bundle_js") → $c)"
else
	no "free-app bundle missing from installed free plugin"
fi

echo
echo "free-e2e: ${pass} HTTP assertions passed, ${fail} failed (incl. degradation + mount blocks)"
[ "$KEEP" = "1" ] && echo "server kept at ${BASE} (login admin/password)"
[ "$fail" = "0" ]
