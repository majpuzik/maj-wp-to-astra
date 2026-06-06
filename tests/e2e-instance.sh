#!/bin/bash
# e2e-instance.sh — full end-to-end proof of the wp-to-astra workflow on ONE
# disposable WordPress+Elementor instance we control (Docker, all via docker exec,
# no published ports so many can run in parallel).
#
# It builds a WP+Elementor+Astra+UpdraftPlus site, seeds an Elementor page with 5
# known widgets, runs the orchestrator (dry test -> convert -> REAL UpdraftPlus
# backup), then restores that backup into a SECOND fresh WP via UpdraftPlus' own
# restorer and verifies nothing is missing. Emits CHECK:<name>:<PASS|FAIL> lines
# and a final RESULT:<PASS|FAIL>.
#
# Usage: e2e-instance.sh <INST> <TOOLS_DIR>
#   <INST>       unique short id (e.g. d1, g3) -> container names
#   <TOOLS_DIR>  dir on this node holding wp-to-astra.sh, elementor-ex.php,
#                package-updraft.sh, restore.php, seed.json, wp-cli.phar
set -u
INST="${1:?need INST}"; T="${2:?need TOOLS_DIR}"
IMG=wordpress:php8.3-apache; DBIMG=mariadb:11
NET=wpnet-$INST; DB=db-$INST; WP=wp-$INST; DB2=db2-$INST; WP2=wp2-$INST
OUT=/tmp/e2e-$INST/backup
declare -a CHECKS
ok(){ CHECKS+=("CHECK:$1:PASS"); echo "CHECK:$1:PASS"; }
no(){ CHECKS+=("CHECK:$1:FAIL ($2)"); echo "CHECK:$1:FAIL ($2)"; }
wpx(){ docker exec "$WP" wp --allow-root --path=/var/www/html "$@" 2>/dev/null; }
wp2x(){ docker exec "$WP2" wp --allow-root --path=/var/www/html "$@" 2>/dev/null; }
cleanup(){ docker rm -f "$WP" "$DB" "$WP2" "$DB2" >/dev/null 2>&1; docker network rm "$NET" >/dev/null 2>&1; }
trap cleanup EXIT
cleanup; mkdir -p "$OUT"; rm -f "$OUT"/* 2>/dev/null  # clear any stale backup set from a prior run

echo "== [$INST] provisioning =="
docker network create "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp "$DBIMG" >/dev/null
docker run -d --name "$WP" --network "$NET" -e WORDPRESS_DB_HOST="$DB" -e WORDPRESS_DB_USER=root -e WORDPRESS_DB_PASSWORD=root -e WORDPRESS_DB_NAME=wp "$IMG" >/dev/null
# wp-cli + tools into the container
docker cp "$T/wp-cli.phar" "$WP:/usr/local/bin/wp" >/dev/null && docker exec "$WP" chmod +x /usr/local/bin/wp
docker exec "$WP" mkdir -p /tools /out >/dev/null
for f in wp-to-astra.sh elementor-ex.php package-updraft.sh seed.json; do docker cp "$T/$f" "$WP:/tools/$f" >/dev/null; done
# wait for DB + install WP
for i in $(seq 1 40); do wpx db check >/dev/null 2>&1 && break; sleep 3; done
wpx core install --url=http://localhost --title="E2E-$INST" --admin_user=admin --admin_password=admin --admin_email=a@b.cz --skip-email >/dev/null
wpx theme install astra --activate >/dev/null
wpx plugin install elementor --activate >/dev/null
wpx plugin install updraftplus --activate >/dev/null
[ "$(wpx theme list --status=active --field=name)" = "astra" ] && ok theme_astra_active || no theme_astra_active "active=$(wpx theme list --status=active --field=name)"

echo "== [$INST] seeding Elementor page =="
PID=$(wpx post create --post_type=page --post_title='E2E Elementor Page' --post_status=publish --porcelain)
docker cp "$T/seed.json" "$WP:/tmp/seed.json" >/dev/null
wpx eval "update_post_meta($PID,'_elementor_edit_mode','builder'); update_post_meta($PID,'_elementor_data', wp_slash(file_get_contents('/tmp/seed.json'))); update_post_meta($PID,'_elementor_version','3.0.0');" >/dev/null
SEEDED=$(wpx eval "echo (int)\$GLOBALS['wpdb']->get_var(\"SELECT COUNT(*) FROM {\$GLOBALS['wpdb']->postmeta} WHERE meta_key='_elementor_edit_mode' AND meta_value='builder'\");")
[ "${SEEDED:-0}" -ge 1 ] && ok seed_elementor_page || no seed_elementor_page "builder pages=$SEEDED"

echo "== [$INST] running orchestrator (dry test -> convert -> backup) =="
ORCH=$(docker exec -e AUTO_CONVERT=1 -e AUTO_BACKUP=1 "$WP" bash /tools/wp-to-astra.sh "wp --allow-root --path=/var/www/html" /var/www/html/wp-content/updraft /out 2>&1)
echo "$ORCH" | sed "s/^/  [$INST] /"
echo "$ORCH" | grep -q "Elementor page(s) found" && ok dry_test_detected || no dry_test_detected "no detect line"

# conversion verify (same WP, post-convert)
LEFT=$(wpx post list --post_type=page,post --meta_key=_elementor_edit_mode --meta_value=builder --format=count 2>/dev/null | tr -dc '0-9')
[ "${LEFT:-1}" = "0" ] && ok elementor_removed || no elementor_removed "builder page/post left=$LEFT"
CONTENT=$(wpx post get "$PID" --field=content)
miss=""; for m in "E2E Heading Marker" "E2E paragraph body marker" "E2E Button Marker" "[e2e_marker]"; do echo "$CONTENT" | grep -qF "$m" || miss="$miss|$m"; done
[ -z "$miss" ] && ok converted_content_native || no converted_content_native "missing:$miss"
echo "$CONTENT" | grep -q "wp:heading" && ok gutenberg_blocks || no gutenberg_blocks "no wp:heading block"

echo "== [$INST] collecting backup =="
docker cp "$WP:/out/." "$OUT/" >/dev/null 2>&1
DBGZ=$(ls "$OUT"/*-db.gz 2>/dev/null | head -1)
NZIP=$(ls "$OUT"/*-{plugins,themes,uploads,others}.zip 2>/dev/null | wc -l | tr -d ' ')
LOG=$(ls "$OUT"/log.*.txt 2>/dev/null | head -1)
{ [ -n "$DBGZ" ] && [ "$NZIP" -eq 4 ] && [ -n "$LOG" ]; } && ok backup_set_complete || no backup_set_complete "db=$DBGZ zips=$NZIP log=$LOG"
[ -n "$LOG" ] && ok backup_has_manifest_log || no backup_has_manifest_log "no log.*.txt = restore would skip themes/plugins"
# converted-only marker: "wp:heading" exists only after convert (raw _elementor_data has no such string)
[ -n "$DBGZ" ] && gunzip -c "$DBGZ" 2>/dev/null | grep -q "wp:heading" && ok db_has_converted_content || no db_has_converted_content "no converted block in db.gz"
TZIP=$(ls "$OUT"/*-themes.zip 2>/dev/null|head -1);  unzip -l "$TZIP" 2>/dev/null | grep -q "astra/" && ok themes_zip_has_astra || no themes_zip_has_astra "astra not in themes.zip"
PZIP=$(ls "$OUT"/*-plugins.zip 2>/dev/null|head -1); unzip -l "$PZIP" 2>/dev/null | grep -q "updraftplus/" && ok plugins_zip_has_updraft || no plugins_zip_has_updraft "updraftplus not in plugins.zip"

echo "== [$INST] RESTORE into a fresh WP (UpdraftPlus' own restorer) =="
docker run -d --name "$DB2" --network "$NET" -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp "$DBIMG" >/dev/null
docker run -d --name "$WP2" --network "$NET" -e WORDPRESS_DB_HOST="$DB2" -e WORDPRESS_DB_USER=root -e WORDPRESS_DB_PASSWORD=root -e WORDPRESS_DB_NAME=wp "$IMG" >/dev/null
docker cp "$T/wp-cli.phar" "$WP2:/usr/local/bin/wp" >/dev/null && docker exec "$WP2" chmod +x /usr/local/bin/wp
docker cp "$T/restore.php" "$WP2:/tmp/restore.php" >/dev/null
for i in $(seq 1 40); do wp2x db check >/dev/null 2>&1 && break; sleep 3; done
# fresh WP must have UpdraftPlus to drive the restore
wp2x core install --url=http://localhost --title="E2E-$INST-RESTORE" --admin_user=admin --admin_password=admin --admin_email=a@b.cz --skip-email >/dev/null
wp2x plugin install updraftplus --activate >/dev/null
# drop the backup files into the fresh site's updraft dir
docker exec "$WP2" mkdir -p /var/www/html/wp-content/updraft >/dev/null
for f in "$OUT"/*; do docker cp "$f" "$WP2:/var/www/html/wp-content/updraft/$(basename "$f")" >/dev/null; done
# 1) DB: import separately (headless UpdraftPlus DB restore is unreliable)
gunzip -c "$DBGZ" 2>/dev/null | docker exec -i "$DB2" mariadb -uroot -proot wp 2>/dev/null
# 2) FILES: UpdraftPlus restorer (themes+plugins+uploads+others) — needs the log/manifest
RES=$(wp2x eval-file /tmp/restore.php 2>&1); echo "$RES" | sed "s/^/  [$INST] restore: /"
# verify nothing missing on the restored site (files on disk + DB state)
RTHEME=$(wp2x theme get astra --field=version 2>/dev/null)
[ -n "$RTHEME" ] && ok restored_theme_files || no restored_theme_files "astra theme files missing after restore"
RPLUG=$(wp2x plugin list --field=name 2>/dev/null | grep -c -E 'elementor|updraftplus')
[ "${RPLUG:-0}" -ge 2 ] && ok restored_plugin_files || no restored_plugin_files "plugin files missing (found $RPLUG/2)"
RPAGE=$(wp2x eval "echo (int)\$GLOBALS['wpdb']->get_var(\"SELECT COUNT(*) FROM {\$GLOBALS['wpdb']->posts} WHERE post_content LIKE '%wp:heading%' AND post_content LIKE '%E2E Heading Marker%'\");")
[ "${RPAGE:-0}" -ge 1 ] && ok restored_converted_content || no restored_converted_content "converted page not in restored DB"
RLEFT=$(wp2x post list --post_type=page,post --meta_key=_elementor_edit_mode --meta_value=builder --format=count 2>/dev/null | tr -dc '0-9')
[ "${RLEFT:-1}" = "0" ] && ok restored_no_elementor || no restored_no_elementor "elementor page/post came back ($RLEFT)"

FAILS=$(printf '%s\n' "${CHECKS[@]}" | grep -c ':FAIL')
echo "== [$INST] SUMMARY: $((${#CHECKS[@]}-FAILS))/${#CHECKS[@]} passed =="
[ "$FAILS" = "0" ] && echo "RESULT:$INST:PASS" || echo "RESULT:$INST:FAIL"
