#!/usr/bin/env bash
# wp.org-style static checks, errors only: WordPress security + DB sniffs and
# PHPCompatibility for the readme's PHP floor. Needs phpcs with WPCS 3 and
# PHPCompatibility 9 (CI installs them; locally: composer global require
# squizlabs/php_codesniffer wp-coding-standards/wpcs phpcompatibility/php-compatibility
# dealerdirect/phpcodesniffer-composer-installer).
set -uo pipefail
cd "$(dirname "$0")/.."
PHPCS="${PHPCS:-$(command -v phpcs || echo "$HOME/.composer/vendor/bin/phpcs")}"
FLOOR="$(grep -m1 -E '^Requires PHP:' readme.txt | awk '{print $3}')"
SRC=( crawlertoll.php uninstall.php includes admin )
fail=0
echo "== WordPress security + database sniffs =="
"$PHPCS" -q -n --extensions=php --report=full --standard=WordPress \
	--sniffs=WordPress.Security.EscapeOutput,WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput,WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery,WordPress.PHP.DevelopmentFunctions,WordPress.WP.AlternativeFunctions,Generic.PHP.ForbiddenFunctions \
	"${SRC[@]}" || fail=$((fail+1))
echo "== PHPCompatibility ${FLOOR}+ =="
"$PHPCS" -q -n --extensions=php --report=full --standard=PHPCompatibility --runtime-set testVersion "${FLOOR}-" "${SRC[@]}" || fail=$((fail+1))
[ "$fail" = 0 ] && echo "sniffs: clean" || echo "sniffs: FAILED ($fail)"
[ "$fail" = 0 ]
