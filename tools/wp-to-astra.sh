#!/bin/bash
# wp-to-astra — end-to-end orchestrator for de-Elementorizing a WordPress site and
# (optionally) shipping it as a REAL UpdraftPlus backup.
#
# Flow:
#   START  dry test (elementor-ex.php test) — does the site still have Elementor pages?
#          if yes  -> convert them to native Gutenberg (elementor-ex.php convert)
#          if no   -> nothing to remove, continue
#   MIDDLE remind to verify (visual-diff.js / site-verify.js)
#   END    prompt: "Save as UpdraftPlus backup?"  -> if yes, generate a REAL backup
#          via the UpdraftPlus plugin (package-updraft.sh, NOT a hand-crafted zip)
#
# Usage:
#   tools/wp-to-astra.sh "<wp-cmd>" [<updraft-dir>|docker:NAME] [<out-dir>]
#     <wp-cmd>  how to run wp-cli on the site, quoted. Examples:
#                 "wp"
#                 "wp --path=/var/www/html --allow-root"
#                 "docker exec mysite wp --allow-root"
#     the other two args are forwarded to package-updraft.sh (where the backup lands).
#
# ALWAYS run against a COPY of the site. Non-interactive? set AUTO_CONVERT=1 and/or
# AUTO_BACKUP=1 to skip the prompts (AUTO_BACKUP=1 implies you want the backup).
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
WP="${1:?usage: wp-to-astra.sh \"<wp-cmd>\" [<updraft-dir>|docker:NAME] [<out-dir>]}"
UPDR="${2:-}"; OUT="${3:-./updraft-backup}"
run() { eval "$WP $*"; }

ask() { # ask "question" -> returns 0 for yes; honours $2 as auto-yes override
  [ "${2:-0}" = "1" ] && return 0
  printf '%s [y/N] ' "$1" >&2; read -r a </dev/tty 2>/dev/null || read -r a
  case "$a" in y|Y|yes|YES|a|A|ano|ANO) return 0;; *) return 1;; esac
}

echo "=== wp-to-astra: $WP ==="

# ---- START: dry test ----------------------------------------------------------
echo
echo ">> START: dry test — looking for Elementor pages (changes nothing)"
EL=$(run eval "'echo (int)\$GLOBALS[\"wpdb\"]->get_var(\"SELECT COUNT(DISTINCT post_id) FROM {\$GLOBALS[\\\"wpdb\\\"]->postmeta} WHERE meta_key=\\\"_elementor_edit_mode\\\" AND meta_value=\\\"builder\\\"\");'" 2>/dev/null | tr -dc '0-9')
EL="${EL:-0}"
run eval-file "$HERE/elementor-ex.php" test 2>/dev/null || true

if [ "$EL" -gt 0 ]; then
  echo
  echo ">> $EL Elementor page(s) found — they must be removed before going native."
  if ask "Remove Elementor now (convert to Gutenberg + write MANUAL-TODO.md)?" "${AUTO_CONVERT:-0}"; then
    run eval-file "$HERE/elementor-ex.php" convert
    echo ">> converted. Review MANUAL-TODO.md for anything that needs a hand."
  else
    echo ">> skipped conversion (left Elementor in place)."
  fi
else
  echo ">> no Elementor pages — nothing to remove."
fi

# ---- MIDDLE: verify reminder --------------------------------------------------
echo
echo ">> VERIFY before shipping:"
echo "     node $HERE/visual-diff.js  <orig-url> <converted-url>     # pixel diff"
echo "     node $HERE/site-verify.js  <orig-base> <converted-base>   # text+assets+forms+DOM"

# ---- END: UpdraftPlus backup prompt -------------------------------------------
echo
if ask "Save the site as an UpdraftPlus backup now?" "${AUTO_BACKUP:-0}"; then
  bash "$HERE/package-updraft.sh" "$WP" "$UPDR" "$OUT"
else
  echo ">> no backup made. (Run tools/package-updraft.sh later to produce one.)"
fi
echo "=== done ==="
