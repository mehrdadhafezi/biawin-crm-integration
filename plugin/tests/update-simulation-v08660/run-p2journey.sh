#!/bin/bash
# Master Phase 2 (v0.8.66.0) real-WordPress update simulation from a 0.8.65.0 production-like baseline.
# usage: run-p2journey.sh <scratch dir> <wp core dir> <0.8.65.0 zip> <new zip> <db name>
# Docker: wordpress:cli-php8.1 + mysql:8 on network bcrm-qa. Throwaway databases/sites only.
S=$1; CORE=$2; OLD=$3; NEW=$4; DB=$5
HERE="$(cd "$(dirname "$0")" && pwd)"; V63="$HERE/../update-simulation-v08630"; V64="$HERE/../update-simulation-v08640"; V65="$HERE/../update-simulation-v08650"
D="$S/site_$DB"; rm -rf "$D"; mkdir -p "$D/wp-content/plugins"; cp -r "$CORE"/. "$D/"
cp "$V63/sim.php" "$V64/simb.php" "$V64/p21.php" "$V65/org.php" "$HERE/journey.php" "$D/"
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
php_ journey.php legacy
php_ sim.php old_request
php_ journey.php snap before

echo "### UPDATE to the new build (files replaced, never deactivated)"
swap "$NEW"
php_ sim.php request
php_ journey.php snap after
echo "### J: update preserves every business table (no migration in 0.8.66.0)"
php_ journey.php same before after
echo "### re-run: forced migration replay — identical"
php_ simb.php force_cycle 0.8.64.1; php_ sim.php request; php_ journey.php snap after2; php_ journey.php same after after2

echo "### A–J functional"
php_ journey.php functional
echo "### Phase 1 regression on the same site (roles/units)"
php_ org.php snap p1; php_ simb.php force_cycle 0.8.65.0; php_ sim.php request; php_ org.php snap p1b; php_ org.php same p1 p1b
echo "### debug.log (plugin lines): $(grep -c 'biawin' "$D/debug.log" 2>/dev/null)"
grep -i 'biawin\|fatal' "$D/debug.log" 2>/dev/null | head -20
