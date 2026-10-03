<?php
/**
 * v0.8.64.0 — static guards for After-Sales Phase 2 (admin configuration foundation).
 * Runtime behaviour is covered on real WordPress by tests/update-simulation-v08640 (p2.php).
 * Usage: php tests/after-sales-config-v08640.php
 */
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function F($c, $label) { global $pass, $fail; echo ($c ? 'PASS  ' : 'FAIL  ') . $label . "\n"; $c ? $pass++ : $fail++; }
$read = static fn($rel) => (string)file_get_contents($root . '/' . $rel);
$strip = static fn($src) => preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $src);
$act = $read('includes/class-biawin-crm-activator.php');
$cfg = $read('includes/class-biawin-crm-after-sales-config.php');
$adm = $read('includes/class-biawin-crm-after-sales-config-admin.php');
$wf = $read('includes/class-biawin-crm-workflow-service.php');
$fe = $read('includes/class-biawin-crm-frontend.php');
$main = $read('biawin-crm-integration.php');
$admin = $read('admin/class-biawin-crm-admin.php');

echo "--- migration / schema\n";
F(strpos($act, "'0.8.64.0' => 'migrate_0864_0'") !== false, 'versioned migration 0.8.64.0 registered in MIGRATIONS');
F(strpos($act, "'0.8.63.0' => 'migrate_0863_0'") !== false, 'Phase 1 migration still registered');
preg_match('/function migrate_0864_0\(\): void\s*\{(.*?)\n    \}/s', $act, $m);
$mig = $m[1] ?? '';
F($mig !== '' && strpos($mig, 'ensure_after_sales_config_schema($p, true)') !== false, 'migration uses strict schema checks (throws → version not advanced)');
F(!preg_match('/\b(DELETE|TRUNCATE|DROP|UPDATE)\b/i', $mig) && !preg_match('/wp_insert_user|wp_create_user|wp_set_password|remove_role|remove_cap|seed_default_services/', $mig), 'migration body: no delete/update/drop, no users/passwords, no role reset, no service seeding');
F(strpos($act, 'CREATE TABLE {$p}catalog_items') !== false && strpos($act, 'UNIQUE KEY catalog_item (catalog,item_key)') !== false, 'catalog_items table with unique (catalog,item_key)');
F(strpos($act, 'CREATE TABLE {$p}complaint_inquiries') !== false && strpos($act, 'complaint_id BIGINT UNSIGNED NOT NULL') !== false && strpos($act, 'mode VARCHAR(20) NOT NULL') !== false && !preg_match('/UNIQUE KEY[^\n]*complaint_id/', $act), 'complaint_inquiries: N rows per complaint (no unique on complaint_id), mode column');
F(strpos($act, "ensure_column(\"{\$p}complaints\", 'subject_id', 'BIGINT UNSIGNED NULL')") !== false, 'complaints.subject_id added as nullable (historical rows untouched)');
F(strpos($act, "ensure_column(\"{\$p}service_units\", 'inquiry_responders'") !== false && strpos($act, 'CREATE TABLE {$p}inquiry_units') === false, 'inquiry providers extend existing service_units (no duplicate "inquiry units" table)');
F(strpos($act, 'self::after_sales_config_tables($p, $charset)') !== false, 'fresh installs get the Phase 2 tables from activate()');
preg_match('/function seed_legacy_complaint_outcomes.*?\n    \}/s', $act, $sd);
F(isset($sd[0]) && strpos($sd[0], 'CATALOG_SEED_LEDGER_OPTION') !== false && !preg_match('/->update\(|->delete\(|->replace\(/', $sd[0]), 'outcome seed: insert-once with ledger, never updates/deletes');
preg_match("/function legacy_complaint_outcomes\(\): array\s*\{\s*return \[(.*?)\];/s", $act, $lo);
preg_match_all("/'([a-z_]+)' => \[/", $lo[1] ?? '', $lk);
preg_match("/function complaint_outcome_handlers\(\): array\s*\{\s*return \[(.*?)\];/s", $wf, $ho);
preg_match_all("/'([a-z_]+)'/", $ho[1] ?? '', $hk);
$a = $lk[1]; $b = $hk[1]; sort($a); sort($b);
F($a && $a === $b, 'seeded outcome keys == keys the existing resolve flow executes (' . implode(',', $a) . ') — no invented business list');
F(!preg_match('/complaint_subject[\'"]\s*,\s*[\'"]item_key/', $act) && strpos($act, "'catalog' => 'complaint_subject'") === false, 'no complaint SUBJECT is seeded by code');

echo "--- capabilities\n";
F(strpos($act, "'manage_inquiry_units' =>") !== false && preg_match('/CAPS_MARKER_VERSION = (\d+)/', $act, $mk) && (int)$mk[1] >= 5, 'new cap manage_inquiry_units + caps marker >= 5 (additive ledger sync; 6 since 0.8.65.0)');
F(substr_count($act, "'manage_dissatisfaction_subjects'") >= 2 && strpos($act, 'bcrm_manage_complaint_subjects') === false, 'existing Phase 1 caps reused for subjects/outcomes (no duplicate caps)');
preg_match("/'bcrm_complaint_agent' => \[(.*?)\]/", substr($act, strpos($act, 'function after_sales_caps')), $ca);
preg_match("/'bcrm_after_sales_manager' => \[(.*?)\]/", substr($act, strpos($act, 'function after_sales_caps')), $am);
F(isset($ca[1]) && strpos($ca[1], 'manage_') === false, 'complaint agents get no manage_* capability');
F(isset($am[1]) && strpos($am[1], 'manage_inquiry_units') === false, 'manage_inquiry_units not auto-granted beyond administrator / portal admin');
foreach (['handle_catalog_save' => 'bcrm_cfg_catalog_save', 'handle_catalog_toggle' => 'bcrm_cfg_catalog_toggle', 'handle_catalog_move' => 'bcrm_cfg_catalog_move'] as $fn => $nonce) {
    preg_match("/function $fn\(\): void\s*\{(.*?)\n    \}/s", $adm, $h);
    F(isset($h[1]) && strpos($h[1], "self::guard(\$tab, '$nonce')") !== false, "$fn: capability (per tab) + nonce guard");
}
F(strpos($adm, "self::guard('units', 'bcrm_cfg_units_save')") !== false, 'handle_units_save: manage_inquiry_units + nonce guard');
F(strpos($adm, "'subjects' => [") !== false && strpos($adm, "'manage_dissatisfaction_subjects']") !== false && strpos($adm, "'manage_inquiry_units']") !== false, 'each tab mapped to its dedicated capability');

echo "--- data safety / no hard-coding\n";
F(!preg_match('/DELETE\s+FROM|->delete\(/i', $strip($cfg . $adm)), 'catalog/inquiry code never hard-deletes (deactivate only)');
F(strpos($cfg, "'item_key' => \$key") !== false && !preg_match("/fields\['item_key'\]/", $cfg), 'item key set on insert only (immutable on edit)');
preg_match('/function save_unit_inquiry_config.*?\n    \}/s', $cfg, $su);
F(isset($su[0]) && !preg_match('/unit_name|parent_unit_id|supervisor_user_id|service_unit_members/', $su[0]), 'unit inquiry save touches only inquiry fields (never name/parent/supervisor/members)');
F(strpos($fe, '<input name="subject" required></label>') === false && strpos($fe, 'complaint_subject_field()') !== false, 'profile complaint form: subject from catalog (free text only while none configured)');
F(strpos($fe, '<select name="outcome"><option value="satisfied">مشتری راضی شد') === false && strpos($fe, 'complaint_outcome_select()') !== false, 'resolve form: outcome labels/order/active from catalog');
F(strpos($wf, "\$row['subject_id'] = \$subject_id") !== false && strpos($wf, "'subject' => \$subject") !== false, 'new complaints store subject_id + label snapshot');
F(strpos($fe, "'bcrm_customer'") !== false || strpos($fe, 'bcrm_customer') !== false, 'customer profile route ?bcrm_customer= still present');

echo "--- wiring\n";
F(strpos($main, 'class-biawin-crm-after-sales-config.php') !== false && strpos($main, 'Biawin_CRM_After_Sales_Config_Admin::init()') !== false, 'classes loaded + admin handlers registered');
F(strpos($admin, "Biawin_CRM_After_Sales_Config_Admin::PAGE") !== false && strpos($adm, "PAGE = 'biawin-crm-complaint-config'") !== false, 'menu «تنظیمات نارضایتی» under the CRM menu (slug keeps CRM admin assets)');
echo "TOTAL_FAIL=$fail (passed $pass)\n";
exit($fail ? 1 : 0);
