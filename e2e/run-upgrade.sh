#!/usr/bin/env bash
#
# REAL upgrade rig (2026-09-16): install the last published tree (wp.org
# tags/0.1.1 by default), configure it, then swap in the working tree the way
# a WordPress auto-update does — files replaced, NO activation hook — and
# assert the site keeps working and the upgrader repaired what activation
# would have done.
#
#   e2e/run-upgrade.sh                 (exit 0 = all passed)
#   CT_UPGRADE_FROM=/path/to/old/tree e2e/run-upgrade.sh
#
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="$(cd "$HERE/.." && pwd)"
FROM="${CT_UPGRADE_FROM:-$HOME/crawlertoll-svn/tags/0.1.1}"
WP_DIR="${CT_E2E_UPGRADE_WP:-/tmp/ct-e2e-upgrade-wp}"
PORT="${CT_E2E_UPGRADE_PORT:-8101}"
HOST="127.0.0.1:${PORT}"; BASE="http://${HOST}"
CACHE="${HOME}/.cache/crawlertoll-e2e"
pass=0; fail=0
ok() { printf '  \033[32mPASS\033[0m %s\n' "$1"; pass=$((pass+1)); }
no() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; fail=$((fail+1)); }
die() { echo "upgrade-e2e setup error: $1"; [ -f /tmp/ct-e2e-upgrade-php.log ] && tail -20 /tmp/ct-e2e-upgrade-php.log; exit 1; }
[ -f "$FROM/crawlertoll.php" ] || die "old tree not found at $FROM (set CT_UPGRADE_FROM)"
SRV=""; cleanup() { [ -n "$SRV" ] && kill "$SRV" 2>/dev/null; }; trap cleanup EXIT

echo "== 1/6 fresh WP tree =="
[ -f "$CACHE/wordpress.zip" ] && [ -d "$CACHE/sqlite-database-integration" ] || die "run e2e/run.sh once first (caches WP core + SQLite drop-in)"
pkill -f "php -S ${HOST}" 2>/dev/null || true; sleep 0.3
rm -rf "$WP_DIR"; mkdir -p "$WP_DIR"
unzip -qo "$CACHE/wordpress.zip" -d "$WP_DIR" || die "WP unzip failed"
mv "$WP_DIR"/wordpress/* "$WP_DIR"/ && rmdir "$WP_DIR"/wordpress
mkdir -p "$WP_DIR/wp-content/plugins"
cp -R "$CACHE/sqlite-database-integration" "$WP_DIR/wp-content/plugins/"
cp "$WP_DIR/wp-content/plugins/sqlite-database-integration/db.copy" "$WP_DIR/wp-content/db.php"
cat > "$WP_DIR/wp-config.php" <<'WPCONF'
<?php
define('DB_NAME','wordpress'); define('DB_USER','root'); define('DB_PASSWORD',''); define('DB_HOST','localhost');
define('DB_CHARSET','utf8'); define('DB_COLLATE','');
define('AUTH_KEY','ct-e2e'); define('SECURE_AUTH_KEY','ct-e2e'); define('LOGGED_IN_KEY','ct-e2e'); define('NONCE_KEY','ct-e2e');
define('AUTH_SALT','ct-e2e'); define('SECURE_AUTH_SALT','ct-e2e'); define('LOGGED_IN_SALT','ct-e2e'); define('NONCE_SALT','ct-e2e');
$table_prefix='wp_';
define('WP_DEBUG', true); define('WP_DEBUG_DISPLAY', false); define('WP_DEBUG_LOG', false);
define('CRAWLERTOLL_PRO_DEV', true);
if ( ! defined('ABSPATH') ) define('ABSPATH', __DIR__ . '/');
require_once ABSPATH . 'wp-settings.php';
WPCONF
cat > "$WP_DIR/router.php" <<'ROUTER'
<?php
$root = __DIR__; $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); $abs = realpath($root . $path);
if ($path !== '/' && $abs && strpos($abs, $root) === 0 && is_file($abs)) { return false; }
require $root . '/index.php';
ROUTER

echo "== 2/6 install OLD plugin ($(grep -m1 'Version:' "$FROM/crawlertoll.php" | tr -s ' ')) + activate =="
mkdir -p "$WP_DIR/wp-content/plugins/crawlertoll"
( cd "$FROM" && tar -cf - . ) | ( cd "$WP_DIR/wp-content/plugins/crawlertoll" && tar -xf - ) || die "old plugin copy failed"
php "$HERE/install.php" "$WP_DIR" "$PORT" || die "install failed"

echo "== 3/6 configure the old version (publisher state that must survive) =="
php -r '
$_SERVER["HTTP_HOST"]="127.0.0.1"; require $argv[1]."/wp-load.php";
$s = get_option("crawlertoll_settings", array()); $s["price_micros"] = 7777; $s["enabled"] = true; update_option("crawlertoll_settings", $s);
echo "  old settings keys: " . implode(",", array_keys($s)) . "\n";
' "$WP_DIR" || die "configure failed"

echo "== 4/6 swap in the CURRENT tree (files only — no activation hook, like an auto-update) =="
rm -rf "$WP_DIR/wp-content/plugins/crawlertoll"; mkdir -p "$WP_DIR/wp-content/plugins/crawlertoll"
( cd "$PLUGIN_DIR" && tar --exclude='./e2e' --exclude='./tests' --exclude='./.git' --exclude='./*.zip' -cf - . ) | ( cd "$WP_DIR/wp-content/plugins/crawlertoll" && tar -xf - ) || die "plugin copy failed"
mkdir -p "$WP_DIR/wp-content/mu-plugins"; cp "$HERE/mu-ct-stubs.php" "$WP_DIR/wp-content/mu-plugins/ct-e2e-stubs.php"

echo "== 5/6 start server + first requests =="
php -S "$HOST" -t "$WP_DIR" "$WP_DIR/router.php" >/tmp/ct-e2e-upgrade-php.log 2>&1 &
SRV=$!
up=0; for _ in $(seq 1 40); do curl -fsS -o /dev/null "$BASE/wp-login.php" 2>/dev/null && { up=1; break; }; sleep 0.3; done
[ "$up" = "1" ] || die "server did not come up"
code() { curl -sS -o /dev/null -w '%{http_code}' -H "user-agent: $1" "$BASE$2"; }
c="$(code 'Mozilla/5.0' '/')";                                   [ "$c" = "200" ] && ok "browser / → 200 on the first request after the swap" || no "browser / (got $c)"
c="$(code 'Mozilla/5.0' '/.well-known/context-license.json')";  [ "$c" = "200" ] && ok "/.well-known/context-license.json → 200 (served even with plain permalinks + stale rules)" || no "well-known (got $c)"
c="$(code 'GPTBot/1.2' '/?p=1')";                                [ "$c" = "402" ] && ok "GPTBot → 402 (0.1.1 enforcement setting carried over)" || no "GPTBot 402 (got $c)"
p="$(curl -sS -D - -o /dev/null -H 'user-agent: GPTBot/1.2' "$BASE/?p=1" | tr -d '\r' | awk -F': ' '$1=="Crawler-Price"{print $2; exit}')"
[ "$p" = "7777 micros USD" ] && ok "Crawler-Price quotes the 0.1.1 price (7777)" || no "Crawler-Price (got '$p')"
fatal="$(grep -ci 'PHP Fatal' /tmp/ct-e2e-upgrade-php.log || true)"; [ "$fatal" = "0" ] && ok "no PHP fatals in the server log" || { no "PHP fatals: $fatal"; grep -i 'PHP Fatal' /tmp/ct-e2e-upgrade-php.log | head -3; }

echo "== 6/6 state checks =="
php "$HERE/check-upgrade.php" "$WP_DIR" 7777 || fail=$((fail+1))
echo; echo "upgrade rig: ${pass} HTTP assertions passed, ${fail} failed"
[ "$fail" = "0" ]
