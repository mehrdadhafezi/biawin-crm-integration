#!/bin/bash
# Phase 2 correction (v0.8.66.1): full Phase 2 run (0.8.65.0 → new) + correction tests + D-01 switch + fresh install.
# usage: run-p2correction.sh <scratch dir> <wp core dir> <0.8.65.0 zip> <new zip> <db name>
S=$1; CORE=$2; OLD=$3; NEW=$4; DB=$5
HERE="$(cd "$(dirname "$0")" && pwd)"; V63="$HERE/../update-simulation-v08630"; V64="$HERE/../update-simulation-v08640"; V65="$HERE/../update-simulation-v08650"; V66="$HERE/../update-simulation-v08660"
site(){ D="$S/site_$1"; rm -rf "$D"; mkdir -p "$D/wp-content/plugins"; cp -r "$CORE"/. "$D/"
  cp "$V63/sim.php" "$V64/simb.php" "$V64/p21.php" "$V65/org.php" "$V66/journey.php" "$HERE/correction.php" "$D/"
  sed -e "s/'127.0.0.1:3399'/'bcrm-qa-mysql'/" -e "s/bcrm_upgrade_test/$1/" -e "s#__DIR__ . '/debug.log'#'/var/www/html/debug.log'#" "$V63/wp-config.sample.php" > "$D/wp-config.php"
  WD=$(cygpath -w "$D" 2>/dev/null || echo "$D")
  MSYS_NO_PATHCONV=1 docker exec bcrm-qa-mysql mysql -e "DROP DATABASE IF EXISTS $1; CREATE DATABASE $1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"; }
php_(){ echo "[php $*] $(date +%T)"; MSYS_NO_PATHCONV=1 docker run --rm --network bcrm-qa -v "$WD:/var/www/html" -w /var/www/html wordpress:cli-php8.1 php "$@" 2>&1 | grep -v sendmail; }
swap(){ rm -rf "$D/wp-content/plugins/biawin-crm-integration"; unzip -q "$1" -d "$D/wp-content/plugins/"; echo "files now: $(grep -m1 'Version:' "$D/wp-content/plugins/biawin-crm-integration/biawin-crm-integration.php")"; }

site "$DB"
echo "### baseline $(basename "$OLD")"
php_ sim.php install; swap "$OLD"; php_ sim.php old_activate; php_ simb.php customize; php_ p21.php history64; php_ journey.php legacy; php_ sim.php old_request; php_ journey.php snap before
echo "### UPDATE"
swap "$NEW"; php_ sim.php request; php_ journey.php snap after; php_ journey.php same before after
php_ simb.php force_cycle 0.8.64.1; php_ sim.php request; php_ journey.php snap after2; php_ journey.php same after after2
echo "### Phase 2 functional (A–K)"
php_ journey.php functional
echo "### Correction functional"
php_ correction.php functional
echo "### D-01 (existing install, then the approved switch simulated)"
php_ correction.php zero
echo "### Phase 1 regression"
php_ org.php snap p1; php_ simb.php force_cycle 0.8.65.0; php_ sim.php request; php_ org.php snap p1b; php_ org.php same p1 p1b
echo "### debug.log (plugin lines): $(grep -c 'biawin' "$D/debug.log" 2>/dev/null)"

site "${DB}_fresh"
echo "### FRESH install $(basename "$NEW")"
php_ sim.php install; swap "$NEW"; php_ sim.php old_activate; php_ sim.php request
php_ correction.php fresh
php_ org.php fresh
echo "### debug.log (plugin lines): $(grep -c 'biawin' "$D/debug.log" 2>/dev/null)"
