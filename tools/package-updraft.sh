#!/bin/bash
# Generate a REAL UpdraftPlus backup — by the UpdraftPlus plugin itself, NOT by
# hand-zipping files into backup_..._-{db,plugins,themes,uploads,others}.* names.
#
# WHY: a hand-crafted set does NOT restore correctly. UpdraftPlus drives the
# theme/plugin restore from its own log/manifest; without it the restorer SKIPS
# themes and plugins (restorer.php move_backup_in / themes_to_restore). You get a
# site with the DB restored but the *default* theme active — no CSS, shuffled menu,
# dead shortcodes. Verified the hard way: only the plugin-generated backup restores
# themes + plugins. (The previous version of this script hand-zipped and was broken.)
#
# HOW it works: triggers UpdraftPlus' own "backup now" (do_action), waits for the
# log to say it completed, then collects the produced files. The backup is a
# resumable wp-cron job, so the site must be RUNNING behind a webserver
# (apache/php-fpm) — a headless wp-cli box with no HTTP loopback won't finish it.
#
# Usage:
#   tools/package-updraft.sh "<wp-cmd>" [<updraft-dir>|docker:NAME] [<out-dir>]
#     <wp-cmd>      how to run wp-cli on the site, quoted. Examples:
#                     "wp"
#                     "wp --path=/var/www/html --allow-root"
#                     "docker exec mysite wp --allow-root"
#     <updraft-dir> host path to wp-content/updraft to copy files from; or
#                   "docker:CONTAINER" to docker-cp them out of a container.
#                   Omit to just print where the files are.
#     <out-dir>     where to collect the files (default: ./updraft-backup)
#
# Ship ALL collected files together (the backup_..._* AND the log.*.txt). Restore =
# drop them in wp-content/updraft/ on the target, UpdraftPlus -> Existing backups ->
# Rescan local folder -> Restore (tick Database + Plugins + Themes + Uploads + Others).
set -uo pipefail

WP="${1:?usage: package-updraft.sh \"<wp-cmd>\" [<updraft-dir>|docker:NAME] [<out-dir>]}"
UPDR="${2:-}"
OUT="${3:-./updraft-backup}"

run() { eval "$WP $*"; }

echo "[1/4] ensuring UpdraftPlus is active"
run plugin is-active updraftplus >/dev/null 2>&1 \
  || run plugin install updraftplus --activate >/dev/null 2>&1 \
  || run plugin activate updraftplus >/dev/null 2>&1 || true

echo "[2/4] triggering a full backup (db + plugins + themes + uploads + others)"
run eval "'do_action(\"updraft_backupnow_backup_all\", array());'" >/dev/null 2>&1

SITE_UPDRAFT=$(run eval "'echo trailingslashit(WP_CONTENT_DIR).\"updraft\";'" 2>/dev/null | tr -d '\r')

echo "[3/4] waiting for completion (up to ~10 min)"
DONE=""
for i in $(seq 1 120); do
  LINE=$(run eval "'\$d=trailingslashit(WP_CONTENT_DIR).\"updraft\"; foreach(glob(\$d.\"/log.*.txt\") as \$f){ if(strpos(file_get_contents(\$f),\"backup succeeded and is now complete\")!==false){ echo basename(\$f); break; } }'" 2>/dev/null | tr -d '\r')
  [ -n "$LINE" ] && { DONE="$LINE"; break; }
  sleep 5
done
[ -z "$DONE" ] && echo "  WARNING: completion not seen in the log. Run this on a live apache/php-fpm site (the backup needs an HTTP loopback to finish)."

echo "[4/4] collecting backup files -> $OUT"
mkdir -p "$OUT"
FILES=$(run eval "'\$d=trailingslashit(WP_CONTENT_DIR).\"updraft/\"; \$l=glob(\$d.\"backup_*\"); usort(\$l, function(\$a,\$b){return filemtime(\$b)-filemtime(\$a);}); if(\$l){ preg_match(\"/backup_[0-9-]+_.+?_[a-f0-9]+/\", basename(\$l[0]), \$m); foreach(glob(\$d.\$m[0].\"*\") as \$f) echo basename(\$f).\"\\n\"; foreach(glob(\$d.\"log.*.txt\") as \$f) echo basename(\$f).\"\\n\"; }'" 2>/dev/null | tr -d '\r')

if [ "${UPDR:0:7}" = "docker:" ]; then
  C="${UPDR#docker:}"
  for f in $FILES; do docker cp "$C:$SITE_UPDRAFT/$f" "$OUT/" 2>/dev/null; done
elif [ -n "$UPDR" ]; then
  for f in $FILES; do cp "$UPDR/$f" "$OUT/" 2>/dev/null; done
else
  echo "  files are on the site at: $SITE_UPDRAFT/"
  echo "$FILES" | sed 's/^/    /'
  echo "  (pass <updraft-dir> or docker:NAME to auto-collect them)"
  exit 0
fi

echo "Done. Backup set in $OUT:"
ls -1 "$OUT" 2>/dev/null | sed 's/^/  /'
echo "Ship ALL of these together (backup_..._* AND log.*.txt)."
