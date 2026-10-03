# Real WordPress update simulation — v0.8.63.0 (Phase 1)

Runs the OLD plugin (0.8.62.28) on real WordPress + MySQL, applies production-like admin
customizations, replaces the plugin files with the NEW ZIP **without deactivating**, loads
WordPress (which runs the upgrade), and diffs everything.

## Setup (throwaway — never point this at a real database)
1. Start a scratch MySQL 8 (e.g. `mysqld --initialize-insecure --datadir=<tmp>` then run on port 3399).
2. `CREATE DATABASE bcrm_upgrade_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
3. Copy WordPress core (wp-admin, wp-includes, root *.php) into a scratch dir, add
   `wp-config.sample.php` from here as `wp-config.php`, and copy `sim.php` + `verify.php` next to it.

## Run
```
php sim.php install
unzip biawin-crm-integration-v0.8.62.28.zip -d wp-content/plugins/
php sim.php old_activate
# fresh 0.8.62.28 installs never create refund_registers (invalid CREATE) — production has it; create it manually
php sim.php old_request && php sim.php customize && php sim.php old_request && php sim.php snapshot before
rm -rf wp-content/plugins/biawin-crm-integration && unzip biawin-crm-integration-v0.8.63.0.zip -d wp-content/plugins/
php sim.php request && php sim.php snapshot after1
php sim.php rerun && php sim.php request && php sim.php snapshot after2
php sim.php units
php sim.php post apply && php sim.php snapshot post_applied
php sim.php post && php sim.php request && php sim.php snapshot after3
php verify.php
```
Result on 2026-09-30: verify 39/39 PASS, units 10/10 PASS, failure/retry scenario PASS.
