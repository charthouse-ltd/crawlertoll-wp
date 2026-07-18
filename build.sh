#!/usr/bin/env bash
#
# Build CrawlerToll WP artifacts — two zips from one working tree:
#
#   build/crawlertoll.zip       FREE  → wp.org SVN (trunk). Freemius licensing
#                               block + vendor/freemius/ STRIPPED. Pro is off
#                               (is_pro_active() falls through function_exists).
#   build/crawlertoll-pro.zip   PREMIUM → upload to Freemius. Full tree.
#
# The free build is the dangerous one: it must NEVER contain the
# wp_org_gatekeeper secret. This script FAILS HARD if any secret marker
# survives into the free artifact — so a bad build can't be shipped by accident.
#
# Ship (operator): svn the contents of build/free/crawlertoll into the wp.org
# trunk; upload build/crawlertoll-pro.zip to the Freemius dashboard.
#
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"   # plugin root
OUT="${HERE}/build"
SLUG="crawlertoll"

# Strings that must NEVER appear in the free build. The build aborts if any do.
SECRETS=( 'wp_org_gatekeeper' 'fs_dynamic_init' 'pk_30f053' 'vendor/freemius' )

# Dev-only / never-ship paths excluded from BOTH artifacts.
EXCLUDES=( '.git' '.gitignore' '.github' '.gstack' 'build' 'build.sh' 'e2e' 'tests' 'test-harness.php' 'docs' 'node_modules' 'ui/node_modules' )

# WS3 (2026-06-29): the sealing engine — class-crawlertoll-{sealed,sealed-gate,registry}.php
# — now SHIPS in BOTH artifacts (free included; "free = real sealing" decision). It is
# free-safe (no Pro-only class deps; the registry client's dead $db param was removed).
# The verify block below asserts both artifacts carry it and that it stays free-safe.

# Pro feature code — stripped from the FREE build ONLY (kept in the premium build).
# wp.org rule: paid code must not be served from WordPress.org. The free plugin
# degrades gracefully without these (file_exists/class_exists guards in
# crawlertoll.php + class-crawlertoll-plugin.php; is_pro_active() is always false
# in the free build, where Freemius is absent).
PREMIUM_ONLY=(
	'includes/class-crawlertoll-db.php'
	'includes/class-crawlertoll-logger.php'
	'includes/class-crawlertoll-provenance.php'
	'includes/class-crawlertoll-pricing.php'
	'includes/class-crawlertoll-alerts.php'
	'includes/class-crawlertoll-catalogue-updater.php'
	'admin/views/pro-dashboard.php'
	'admin/views/pro-logs.php'
	'admin/views/pro-pricing.php'
	'admin/views/pro-alerts.php'
	'admin/views/pro-rails.php'
)

# Pro React bundle + Pro app source — stripped from the FREE zip only. The free
# zip keeps ui/src/free + the Vite config for wp.org source disclosure (#4);
# the pro bundle + pro source must not ship to wordpress.org.
PREMIUM_ONLY_DIRS=( 'assets/app/pro-app' 'ui/src/pro' )

rm -rf "$OUT"
mkdir -p "$OUT"

echo "== build the React UI (Vite) =="
if [ -d "$HERE/ui" ]; then
	[ -d "$HERE/ui/node_modules" ] || ( cd "$HERE/ui" && npm install --no-audit --no-fund >/dev/null 2>&1 )
	( cd "$HERE/ui" && npm run build >/tmp/ct-ui-build.log 2>&1 ) || { echo "  FAIL: vite build failed"; tail -20 /tmp/ct-ui-build.log; exit 1; }
	echo "  ok: free-app + pro-app built into assets/app/"
fi

stage() {  # $1 = variant; copies the tree (minus excludes, minus *.zip) into build/<variant>/crawlertoll
	local variant="$1"
	local dest="$OUT/$variant/$SLUG"
	mkdir -p "$dest"
	local args=()
	for e in "${EXCLUDES[@]}"; do args+=( --exclude="./$e" ); done
	args+=( --exclude='./*.zip' --exclude='.DS_Store' )
	( cd "$HERE" && tar "${args[@]}" -cf - . ) | ( cd "$dest" && tar -xf - )
	printf '%s' "$dest"
}

echo "== stage premium (full tree) =="
PREM="$(stage premium)"
rm -rf "$PREM/ui"   # premium ships only the built assets/app bundles, not the React source

echo "== stage free (strip Freemius) =="
FREE="$(stage free)"
# Cut the licensing block between the sentinels (inclusive) and drop the SDK.
perl -0pi -e 's{[ \t]*//[^\n]*\@ct-build:freemius-start.*?\@ct-build:freemius-end[^\n]*\n}{}s' "$FREE/crawlertoll.php"
rm -rf "$FREE/vendor/freemius"
rmdir "$FREE/vendor" 2>/dev/null || true
# Strip Pro feature code from the FREE build only.
for f in "${PREMIUM_ONLY[@]}"; do rm -f "$FREE/$f"; done
# Strip the Pro React bundle + Pro app source from the FREE build only.
for d in "${PREMIUM_ONLY_DIRS[@]}"; do rm -rf "$FREE/$d"; done

bad=0

echo "== verify free build is secret-free =="
for s in "${SECRETS[@]}"; do
	if grep -rqF "$s" "$FREE"; then
		echo "  FAIL: free build still contains '$s':"
		grep -rnF "$s" "$FREE" | sed 's/^/    /' | head
		bad=1
	else
		echo "  ok: no '$s'"
	fi
done

echo "== verify free build carries NO Pro code =="
for f in "${PREMIUM_ONLY[@]}"; do
	if [ -e "$FREE/$f" ]; then echo "  FAIL: Pro file present in free build: $f"; bad=1; else echo "  ok: stripped $f"; fi
done
# Belt-and-braces: no Pro class DEFINITION may survive anywhere in the free tree.
for cls in CrawlerToll_DB CrawlerToll_Logger CrawlerToll_Provenance CrawlerToll_Pricing CrawlerToll_Alerts CrawlerToll_CatalogueUpdater; do
	if grep -rqE "class[[:space:]]+${cls}\b" "$FREE"; then echo "  FAIL: free build defines Pro class ${cls}"; bad=1; fi
done

echo "== verify free build still lints (strip must not break it) =="
while IFS= read -r f; do
	php -l "$f" >/dev/null 2>&1 || { echo "  FAIL: $f does not lint after strip"; php -l "$f" 2>&1 | tail -2 | sed 's/^/    /'; bad=1; }
done < <(find "$FREE" -name '*.php')
echo "  ok: $(find "$FREE" -name '*.php' | wc -l | tr -d ' ') PHP files checked"

echo "== verify PREMIUM build retains Pro code =="
for f in "${PREMIUM_ONLY[@]}"; do
	if [ -e "$OUT/premium/$SLUG/$f" ]; then echo "  ok: premium has $f"; else echo "  FAIL: premium MISSING $f"; bad=1; fi
done

echo "== verify React bundles split correctly =="
if [ -e "$FREE/assets/app/pro-app" ]; then echo "  FAIL: pro-app bundle present in free build"; bad=1; else echo "  ok: no pro-app bundle in free"; fi
if [ -e "$FREE/ui/src/pro" ]; then echo "  FAIL: pro app source present in free build"; bad=1; else echo "  ok: no pro source in free"; fi
if [ -n "$(ls -A "$FREE/assets/app/free-app" 2>/dev/null)" ]; then echo "  ok: free-app bundle present in free"; else echo "  FAIL: free-app bundle missing from free build"; bad=1; fi
if grep -rqF 'pro-app' "$FREE/assets" 2>/dev/null; then echo "  FAIL: stray pro-app reference in free assets"; bad=1; else echo "  ok: no pro-app reference in free assets"; fi
if [ -e "$PREM/assets/app/pro-app" ]; then echo "  ok: premium has pro-app bundle"; else echo "  FAIL: premium MISSING pro-app bundle"; bad=1; fi
if [ -e "$FREE/ui/src/free" ] && [ -e "$FREE/ui/vite.config.ts" ]; then echo "  ok: free build carries ui/src/free + vite config (wp.org source disclosure)"; else echo "  FAIL: free build missing source-disclosure files"; bad=1; fi
# Unlock app (WS3 3.4) — front-end public bundle, ships in BOTH artifacts, free-safe.
if [ -n "$(ls -A "$FREE/assets/app/unlock-app" 2>/dev/null)" ]; then echo "  ok: unlock-app bundle present in free"; else echo "  FAIL: unlock-app bundle missing from free build"; bad=1; fi
if [ -e "$PREM/assets/app/unlock-app" ]; then echo "  ok: premium has unlock-app bundle"; else echo "  FAIL: premium MISSING unlock-app bundle"; bad=1; fi
if [ -e "$FREE/ui/src/unlock" ]; then echo "  ok: free build carries ui/src/unlock (source disclosure)"; else echo "  FAIL: free build missing ui/src/unlock"; bad=1; fi
if grep -rqE 'CrawlerToll_(DB|Pricing|Alerts|Logger|Provenance|CatalogueUpdater)' "$FREE/ui/src/unlock" 2>/dev/null; then echo "  FAIL: unlock source references a Pro-only class"; bad=1; else echo "  ok: ui/src/unlock is free-safe"; fi

echo "== verify the sealing engine ships in BOTH artifacts + stays free-safe (WS3) =="
for f in includes/class-crawlertoll-sealed.php includes/class-crawlertoll-sealed-gate.php includes/class-crawlertoll-registry.php includes/class-crawlertoll-cut.php includes/class-crawlertoll-premium-gate.php; do
	if [ -e "$FREE/$f" ]; then echo "  ok: free has $f"; else echo "  FAIL: free MISSING $f"; bad=1; fi
	if [ -e "$PREM/$f" ]; then echo "  ok: premium has $f"; else echo "  FAIL: premium MISSING $f"; bad=1; fi
	# Free-safe: the sealing engine must not depend on Pro-only classes (stripped from free).
	if grep -qE 'CrawlerToll_(DB|Pricing|Alerts|Logger|Provenance|CatalogueUpdater)' "$FREE/$f"; then
		echo "  FAIL: sealing file references a Pro-only class (not free-safe): $f"
		grep -nE 'CrawlerToll_(DB|Pricing|Alerts|Logger|Provenance|CatalogueUpdater)' "$FREE/$f" | sed 's/^/      /'
		bad=1
	else
		echo "  ok: $f is free-safe"
	fi
done

[ "$bad" = 0 ] || { echo "ABORT: build is not safe to ship"; exit 1; }

echo "== zip =="
( cd "$OUT/free"    && zip -qr "../${SLUG}.zip"     "$SLUG" )
( cd "$OUT/premium" && zip -qr "../${SLUG}-pro.zip" "$SLUG" )

echo
echo "free    → build/${SLUG}.zip       (wp.org SVN trunk — verified secret-free)"
echo "premium → build/${SLUG}-pro.zip   (Freemius dashboard upload)"
