# Phase 2 (v0.8.64.0) real-WordPress tests

Throwaway only. These scripts create their own databases and site folders. Never point them at a real install.

## Requirements
- Docker.
- A network and a scratch MySQL:
  ```
  docker network create bcrm-qa
  docker run -d --name bcrm-qa-mysql --network bcrm-qa -e MYSQL_ALLOW_EMPTY_PASSWORD=yes mysql:8
  ```
- A folder with WordPress core only: `wp-admin`, `wp-includes` and the root `*.php` files, without
  `wp-config.php`.

## Run
- `run-p2.sh <scratch> <core> <0.8.63.2 zip> <new zip> <db>`. It runs these steps:
  - Builds the baseline: `simb.php customize` and `p2.php history`, then takes a snapshot.
  - Updates in place with a forced migration failure, then checks concurrency and the retry.
  - Runs `p2.php diff`, which checks preservation.
  - Re-runs the migration twice with `p2.php same`.
  - Runs `p2.php functional`, which covers tests 1–12 and permissions through the real admin handlers.
- `run-p1.sh <scratch> <core> <0.8.62.28 zip> <new zip> <db> <refund_registers DDL>` runs the Phase 1
  `verify.php` (39 checks) and the units checks (10) against the new build.

Results for 2026-10-01 are in `QA-REPORT-v0.8.64.0.md`.
