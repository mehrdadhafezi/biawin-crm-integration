#!/bin/bash
# Phase 2.1 (v0.8.64.1) real-WordPress update simulation from a Phase 2 (0.8.64.0) baseline.
# usage: run-p21.sh <scratch dir> <wp core dir> <0.8.64.0 zip> <new zip> <db name>
# Docker: wordpress:cli-php8.1 + mysql:8 on network bcrm-qa. Throwaway database/site only.
S=$1; CORE=$2; OLD=$3; NEW=$4; DB=$5
HERE="$(cd "$(dirname "$0")" && pwd)"; V63="$HERE/../update-simulation-v08630"
D="$S/site_$DB"; rm -rf "$D"; mkdir -p "$D/wp-content/plugins"; cp -r "$CORE"/. "$D/"
cp "$V63/sim.php" "$HERE/simb.php" "$HERE/p2.php" "$HERE/p21.php" "$D/"
sed -e "s/'127.0.0.1:3399'/'bcrm-qa-mysql'/" -e "s/bcrm_upgrade_test/$DB/" -e "s#__DIR__ . '/debug.log'#'/var/www/html/debug.log'#" "$V63/wp-config.sample.php" > "$D/wp-config.php"
WD=$(cygpath -w "$D" 2>/dev/null || echo "$D")
php_(){ echo "[php $*]"; MSYS_NO_PATHCONV=1 docker run --rm --network bcrm-qa -v "$WD:/var/www/html" -w /var/www/html wordpress:cli-php8.1 php "$@" 2>&1 | grep -v sendmail; }
swap(){ rm -rf "$D/wp-content/plugins/biawin-crm-integration"; unzip -q "$1" -d "$D/wp-content/plugins/"; echo "files now: $(grep -m1 'Version:' "$D/wp-content/plugins/biawin-crm-integration/biawin-crm-integration.php")"; }
MSYS_NO_PATHCONV=1 docker exec bcrm-qa-mysql mysql -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "### baseline $(basename "$OLD") (db $DB)"
php_ sim.php install
swap "$OLD"
php_ sim.php old_activate
php_ simb.php customize
php_ p21.php history64
php_ sim.php old_request
php_ p2.php snap before

echo "### UPDATE to the new build (files replaced, never deactivated)"
swap "$NEW"
php_ sim.php request
php_ p2.php snap after
echo "### TEST 10–15: strict identity 0.8.64.0 → new (users, passwords, roles, services, units, members, complaints, inquiries, catalog, business, ledgers)"
php_ p2.php same before after

echo "### TEST 16: migrations re-run (forced) — identical state"
php_ simb.php force_cycle 0.8.64.0; php_ sim.php request; php_ p2.php snap after2; php_ p2.php same after after2
php_ simb.php force_cycle 0.8.63.2; php_ sim.php request; php_ p2.php snap after3; php_ p2.php same after after3

echo "### TEST 1–9 (+10/11 after functional changes)"
php_ p21.php functional
echo "### plugins dir: $(ls "$D/wp-content/plugins" | tr '\n' ' ')"
echo "### debug.log (plugin lines): $(grep -c 'biawin' "$D/debug.log" 2>/dev/null)"
