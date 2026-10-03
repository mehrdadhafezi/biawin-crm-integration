#!/bin/bash
# Phase 2 real-WordPress update simulation (Docker: wordpress:cli-php8.1 + mysql:8 on network bcrm-qa).
# usage: run-p2.sh <scratch dir> <wp core dir> <baseline zip> <candidate zip> <db name>
# Throwaway only: creates its own database and site folder; never point it at a real install.
S=$1; CORE=$2; OLD=$3; NEW=$4; DB=$5
HERE="$(cd "$(dirname "$0")" && pwd)"; V63="$HERE/../update-simulation-v08630"
D="$S/site_$DB"; rm -rf "$D"; mkdir -p "$D/wp-content/plugins"; cp -r "$CORE"/. "$D/"
cp "$V63/sim.php" "$V63/verify.php" "$HERE/simb.php" "$HERE/p2.php" "$D/"
sed -e "s/'127.0.0.1:3399'/'bcrm-qa-mysql'/" -e "s/bcrm_upgrade_test/$DB/" -e "s#__DIR__ . '/debug.log'#'/var/www/html/debug.log'#" "$V63/wp-config.sample.php" > "$D/wp-config.php"
WD=$(cygpath -w "$D" 2>/dev/null || echo "$D")
php_(){ echo "[php $*]"; MSYS_NO_PATHCONV=1 docker run --rm --network bcrm-qa -v "$WD:/var/www/html" -w /var/www/html wordpress:cli-php8.1 php "$@" 2>&1 | grep -v sendmail; }
sql(){ MSYS_NO_PATHCONV=1 docker exec bcrm-qa-mysql mysql -N "$DB" -e "$1"; }
ver(){ sql "SELECT option_value FROM wp_options WHERE option_name='biawin_crm_db_version'"; }
swap(){ rm -rf "$D/wp-content/plugins/biawin-crm-integration"; unzip -q "$1" -d "$D/wp-content/plugins/"; echo "files now: $(grep -m1 'Version:' "$D/wp-content/plugins/biawin-crm-integration/biawin-crm-integration.php")"; }
LOCK="bcrm_upgrade_$(printf '%s' "$DB|wp_" | md5sum | cut -c1-32)"

MSYS_NO_PATHCONV=1 docker exec bcrm-qa-mysql mysql -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
echo "### baseline $(basename "$OLD") (db $DB)"
php_ sim.php install
swap "$OLD"
php_ sim.php old_activate
php_ sim.php old_request
php_ simb.php customize
php_ p2.php history
php_ sim.php old_request
php_ p2.php snap before

echo "### UPDATE (files replaced, never deactivated) — first attempt with a forced migration failure"
swap "$NEW"
sql "RENAME TABLE wp_bcrm_complaints TO wp_bcrm_complaints_hidden"
php_ sim.php request
echo "RESULT failed attempt: db_version=$(ver) option_lock=$(sql "SELECT COUNT(*) FROM wp_options WHERE option_name='bcrm_upgrade_lock'") named_lock_free=$(sql "SELECT IS_FREE_LOCK('$LOCK')")"
echo "RESULT failure log: $(sql "SELECT LEFT(after_json,200) FROM wp_bcrm_audit_log WHERE action='upgrade_migration_failed' ORDER BY id DESC LIMIT 1")"
sql "RENAME TABLE wp_bcrm_complaints_hidden TO wp_bcrm_complaints"

echo "### concurrency: another session holds the migration lock"
(MSYS_NO_PATHCONV=1 docker exec bcrm-qa-mysql mysql -N "$DB" -e "SELECT GET_LOCK('$LOCK',0), SLEEP(20), RELEASE_LOCK('$LOCK')" >/dev/null &)
sleep 3
php_ sim.php request
echo "RESULT while locked elsewhere: db_version=$(ver)"
sleep 20

echo "### retry (next normal request)"
php_ sim.php request
echo "RESULT after retry: db_version=$(ver)"
php_ p2.php snap after
php_ p2.php diff before after

echo "### re-run migrations (forced) — must change nothing"
php_ simb.php force_cycle 0.8.63.2; php_ sim.php request; php_ p2.php snap after2; php_ p2.php same after after2
php_ simb.php force_cycle 0.8.62.28; php_ sim.php request; php_ p2.php snap after3; php_ p2.php same after after3

echo "### functional (admin handlers + services)"
php_ p2.php functional
echo "### plugins dir: $(ls "$D/wp-content/plugins" | tr '\n' ' ')"
echo "### debug.log (plugin lines): $(grep -c 'biawin' "$D/debug.log" 2>/dev/null)"; grep 'biawin' "$D/debug.log" 2>/dev/null | cut -c1-220 | sort | uniq -c | head
