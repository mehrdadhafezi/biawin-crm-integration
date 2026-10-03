#!/bin/bash
# Phase 1 regression (tests/update-simulation-v08630: verify.php 39 checks + units 10) against a NEW build,
# starting from the 0.8.62.28 production baseline. Docker: wordpress:cli-php8.1 + mysql:8 (network bcrm-qa).
# usage: run-p1.sh <scratch dir> <wp core dir> <0.8.62.28 zip> <new zip> <db name> <refund_registers DDL file>
S=$1; CORE=$2; OLD=$3; NEW=$4; DB=$5; RR=$6
HERE="$(cd "$(dirname "$0")" && pwd)"; V63="$HERE/../update-simulation-v08630"
D="$S/site_$DB"; rm -rf "$D"; mkdir -p "$D/wp-content/plugins"; cp -r "$CORE"/. "$D/"; cp "$V63/sim.php" "$V63/verify.php" "$D/"
sed -e "s/'127.0.0.1:3399'/'bcrm-qa-mysql'/" -e "s/bcrm_upgrade_test/$DB/" -e "s#__DIR__ . '/debug.log'#'/var/www/html/debug.log'#" "$V63/wp-config.sample.php" > "$D/wp-config.php"
WD=$(cygpath -w "$D" 2>/dev/null || echo "$D")
php_(){ MSYS_NO_PATHCONV=1 docker run --rm --network bcrm-qa -v "$WD:/var/www/html" -w /var/www/html wordpress:cli-php8.1 php "$@" 2>&1 | grep -v sendmail; }
sql(){ MSYS_NO_PATHCONV=1 docker exec bcrm-qa-mysql mysql -N "$DB" -e "$1"; }
swap(){ rm -rf "$D/wp-content/plugins/biawin-crm-integration"; unzip -q "$1" -d "$D/wp-content/plugins/"; }
MSYS_NO_PATHCONV=1 docker exec bcrm-qa-mysql mysql -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php_ sim.php install; swap "$OLD"; php_ sim.php old_activate
sql "$(cat "$RR")" # 0.8.62.28 fresh installs never created refund_registers (invalid CREATE) — production has it
php_ sim.php old_request; php_ sim.php customize; php_ sim.php old_request; php_ sim.php snapshot before
swap "$NEW"; php_ sim.php request; php_ sim.php snapshot after1
php_ sim.php rerun; php_ sim.php request; php_ sim.php snapshot after2
php_ sim.php units; php_ sim.php post apply; php_ sim.php snapshot post_applied; php_ sim.php post; php_ sim.php request; php_ sim.php snapshot after3
php_ verify.php
echo "plugins dir: $(ls "$D/wp-content/plugins" | tr '\n' ' ')"
