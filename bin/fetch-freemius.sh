#!/usr/bin/env bash
# Fetch the Freemius SDK bundled in the Pro build, pinned by version AND sha256,
# into vendor/freemius (gitignored). Idempotent: does nothing when the pinned
# version is already present. build.sh calls it, so any machine or CI runner
# produces the same Pro zip.
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="2.13.2"
SHA256="77aaafe0ce2c5b5b28309f5a187f47d4a5992174fd3413679262989d8eec7beb"
DEST="$HERE/vendor/freemius"
if [ -f "$DEST/start.php" ] && grep -q "this_sdk_version = '${VERSION}'" "$DEST/start.php"; then
	cp "$HERE/bin/freemius-icon.png" "$DEST/assets/img/crawlertoll.png"
	exit 0
fi
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
curl -fsSL "https://github.com/Freemius/wordpress-sdk/archive/refs/tags/${VERSION}.tar.gz" -o "$TMP/sdk.tgz"
got="$(shasum -a 256 "$TMP/sdk.tgz" 2>/dev/null | awk '{print $1}' || sha256sum "$TMP/sdk.tgz" | awk '{print $1}')"
[ "$got" = "$SHA256" ] || { echo "Freemius SDK checksum mismatch: got $got, want $SHA256" >&2; exit 1; }
tar -xzf "$TMP/sdk.tgz" -C "$TMP"
rm -rf "$DEST"; mkdir -p "$(dirname "$DEST")"
mv "$TMP/wordpress-sdk-${VERSION}" "$DEST"
# Freemius shows assets/img/<slug>.png on its opt-in / licence screens.
cp "$HERE/bin/freemius-icon.png" "$DEST/assets/img/crawlertoll.png"
echo "  ok: Freemius SDK ${VERSION} (sha256 verified) → vendor/freemius"
