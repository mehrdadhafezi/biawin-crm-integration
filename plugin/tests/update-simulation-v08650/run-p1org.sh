#!/bin/bash
# Master Phase 1 (v0.8.65.0) real-WordPress update simulation from a 0.8.64.1 production-like baseline,
# plus a fresh install of the new build.
# usage: run-p1org.sh <scratch dir> <wp core dir> <0.8.64.1 zip> <new zip> <db name>
# Docker: wordpress:cli-php8.1 + mysql:8 on network bcrm-qa. Throwaway databases/sites only.
S=$1; CORE=$2; OLD=$3; NEW=$4; DB=$5
HERE="$(cd "$(dirname "$0")" && pwd)"; V63="$HERE/../update-simulation-v08630"; V64="$HERE/../update-simulation-v08640"
site(){ # $1 = db name → prepares a clean site folder + database
  D="$S/site_$1"; rm -rf "$D"; mkdir -p "$D/wp-content/plugins"; cp -r "$CORE"/. "$D/"
  cp "$V63/sim.php" "$V64/simb.php" "$V64/p21.php" "$HERE/org.php" "$D/"
  sed -e "s/'127.0.0.1:3399'/'bcrm-qa-mysql'/" -e "s/bcrm_upgrade_test/$1/" -e "s#__DIR__ . '/debug.log'#'/var/www/html/debug.log'#" "$V63/wp-config.sample.php" > "$D/wp-config.php"
  WD=$(cygpath -w "$D" 2>/dev/null || echo "$D")
  MSYS_NO_PATHCONV=1 docker exec bcrm-qa-mysql mysql -e "DROP DATABASE IF EXISTS $1; CREATE DATABASE $1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
}
php_(){ echo "[php $*]"; MSYS_NO_PATHCONV=1 docker run --rm --network bcrm-qa -v "$WD:/var/www/html" -w /var/www/html wordpress:cli-php8.1 php "$@" 2>&1 | grep -v sendmail; }
swap(){ rm -rf "$D/wp-content/plugins/biawin-crm-integration"; unzip -q "$1" -d "$D/wp-content/plugins/"; echo "files now: $(grep -m1 'Version:' "$D/wp-content/plugins/biawin-crm-integration/biawin-crm-integration.php")"; }

site "$DB"
echo "### baseline $(basename "$OLD") (db $DB)"
php_ sim.php install
swap "$OLD"
php_ sim.php old_activate
php_ simb.php customize
php_ p21.php history64
php_ org.php prep
php_ sim.php old_request
php_ org.php snap before

echo "### UPDATE to the new build (files replaced, never deactivated)"
swap "$NEW"
php_ sim.php request
php_ org.php snap after
echo "### TESTS 4/7/9–13: exact additive change 0.8.64.1 → new"
php_ org.php verify before after

echo "### TEST 14: migrations re-run (forced) — identical state"
php_ simb.php force_cycle 0.8.64.1; php_ sim.php request; php_ org.php snap after2; php_ org.php same after after2
php_ simb.php force_cycle 0.8.62.28; php_ sim.php request; php_ org.php snap after3; php_ org.php same after after3

echo "### TESTS 1–9 functional (real admin handlers)"
php_ org.php functional
echo "### debug.log (plugin lines): $(grep -c 'biawin' "$D/debug.log" 2>/dev/null)"

site "${DB}_fresh"
echo "### FRESH install of $(basename "$NEW")"
php_ sim.php install
swap "$NEW"
php_ sim.php old_activate
php_ sim.php request
php_ org.php fresh
echo "### debug.log (plugin lines): $(grep -c 'biawin' "$D/debug.log" 2>/dev/null)"
