#!/bin/sh
# Copy the Kovalna Kalc build into kalc/ (fork-only). The Kalc's source is KOVALNA's
# tools-src/kalc; its build is client/public/tools/statlab-v3.1.html plus the files it
# loads from lib/. The files are read from KOVALNA's git at <rev> (default origin/main),
# as committed — never a working tree, whose line endings and stray builds differ.
# Usage: tools/sync-kalc.sh <path to a KOVALNA-2.0 clone> [rev]
# Then set KALC_VERSION in assessment/libs/nxkalc.js to the printed KALC_EMBED_VERSION.
set -e
SRC="$1"; REV="${2:-origin/main}"
git -C "$SRC" rev-parse --verify -q "$REV^{commit}" >/dev/null || { echo "no commit $REV in $SRC" >&2; exit 1; }
REV="$(git -C "$SRC" rev-parse "$REV^{commit}")"
HERE="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$HERE/kalc"
T=client/public/tools
blob() { git -C "$SRC" cat-file blob "$REV:$1" > "$2"; }
rm -rf "$OUT"
mkdir -p "$OUT/lib/font" "$OUT/lib/mathlive/fonts"
blob "$T/statlab-v3.1.html" "$OUT/index.html"
blob "$T/lib/mathlive/mathlive.min.js" "$OUT/lib/mathlive/mathlive.min.js"
blob "$T/lib/mathlive/mathlive-static.css" "$OUT/lib/mathlive/mathlive-static.css"
for f in $(git -C "$SRC" ls-tree --name-only "$REV" "$T/lib/mathlive/fonts/") \
         $(git -C "$SRC" ls-tree --name-only "$REV" "$T/lib/font/" | grep -E '/(DMSans|JetBrainsMono)-'); do
  case "$f" in */mathlive/fonts/*) d="$OUT/lib/mathlive/fonts";; *) d="$OUT/lib/font";; esac
  blob "$f" "$d/$(basename "$f")"
done
VER="$(git -C "$SRC" show "$REV:client/src/lib/kalcEmbed.js" | sed -n "s/.*KALC_EMBED_VERSION = '\([0-9]*\)'.*/\1/p")"
printf 'Kovalna Kalc, copied by tools/sync-kalc.sh\nsource: jschlama/KOVALNA-2.0 @ %s\nKALC_EMBED_VERSION: %s\n' "$REV" "$VER" > "$OUT/SOURCE.txt"
cat >> "$OUT/SOURCE.txt" <<'LIC'

Licences: the Kalc is KOVALNA's own. MathLive (lib/mathlive) is MIT; DM Sans and JetBrains Mono
(lib/font, and the KaTeX fonts in lib/mathlive/fonts) are under the SIL Open Font License 1.1.
LIC
echo "kalc/ <- KOVALNA $REV (KALC_EMBED_VERSION $VER)"
