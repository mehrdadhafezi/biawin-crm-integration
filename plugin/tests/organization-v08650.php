<?php
/**
 * v0.8.65.0 — static guards for Master Phase 1 (organization / units / roles / permissions).
 * Runtime behaviour: tests/update-simulation-v08650/run-p1org.sh (org.php on real WordPress).
 * Usage: php tests/organization-v08650.php
 */
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function F($c, $label) { global $pass, $fail; echo ($c ? 'PASS  ' : 'FAIL  ') . $label . "\n"; $c ? $pass++ : $fail++; }
$read = static fn($rel) => (string)file_get_contents($root . '/' . $rel);
$strip = static fn($src) => preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $src);
$fn = static function (string $src, string $name) { return preg_match('/function ' . preg_quote($name, '/') . '\(.*?\n    \}/s', $src, $m) ? $m[0] : ''; };
$act = $read('includes/class-biawin-crm-activator.php');
$auth = $read('includes/class-biawin-crm-auth.php');
$adm = $read('admin/class-biawin-crm-admin.php');
$front = $read('includes/class-biawin-crm-frontend.php');
$wf = $read('includes/class-biawin-crm-workflow-service.php');
$main = $read('biawin-crm-integration.php');

// Load the pure definition methods without WordPress (they only build arrays).
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
require_once $root . '/includes/class-biawin-crm-activator.php';
$defs = Biawin_CRM_Activator::role_definitions();
$extra = Biawin_CRM_Activator::after_sales_caps();
$caps = Biawin_CRM_Activator::after_sales_capabilities();
$seeds = Biawin_CRM_Activator::after_sales_unit_seeds();
$all = static fn(string $role) => array_values(array_unique(array_merge((array)($defs[$role]['caps'] ?? []), (array)($extra[$role] ?? []))));
$same = static function (array $a, array $b) { sort($a); sort($b); return $a === $b; };

echo "--- version + migration\n";
F(strpos($main, 'Version: 0.8.66.1') !== false && strpos($main, "define('BIAWIN_CRM_VERSION', '0.8.66.1')") !== false, 'plugin version 0.8.66.1');
F(strpos($act, "'0.8.65.0' => 'migrate_0865_0'") !== false, 'versioned migration 0.8.65.0 registered');
F(strpos($act, 'CAPS_MARKER_VERSION = 6') !== false, 'caps marker 6 (definitions changed → one additive ledger sync)');
$mig = $fn($act, 'migrate_0865_0');
F($mig !== '' && strpos($mig, 'seed_after_sales_units') !== false && strpos($mig, 'sync_roles_and_caps') !== false && strpos($mig, 'relabel_legacy_complaint_roles_0865') !== false, 'migration = unit seed + relabel + additive role/cap sync');
F(!preg_match('/customers|complaints|refund|treasury_|invoices|assignments|service_unit_members|service_catalog|wp_insert_user|wp_create_user|remove_role|remove_cap|->delete\(|DELETE |DROP |TRUNCATE/i', $strip($mig)), 'migration touches no business table, user, membership or catalog; removes nothing');
F(substr_count($act, 'CREATE TABLE') === 32, 'no new table (32 CREATE TABLE statements, unchanged)');

echo "--- ledger safety (unchanged mechanism)\n";
$sync = $fn($act, 'sync_roles_and_caps');
F($sync !== '' && !preg_match('/remove_cap|remove_role|set_role/', $strip($sync)), 'role/cap sync never removes a capability or role');
F(strpos($sync, "!empty(\$ledger['roles'][\$role_name])") !== false, 'deleted roles are never recreated (role ledger)');
F(strpos($sync, "if (!empty(\$ledger['caps'][\$role_name][\$cap])) continue;") !== false, 'caps granted before (and possibly removed by an admin) are never re-granted');
$seedfn = $fn($act, 'seed_after_sales_units');
F(strpos($seedfn, 'if ($existing) {') !== false && strpos($seedfn, "if (!empty(\$ledger[\$key])) continue;") !== false && !preg_match('/->update\(/', $seedfn), 'units inserted once by unit_key; existing never updated; deleted never recreated');
F(!preg_match('/service_unit_members/', $strip($seedfn)), 'unit seed writes no membership');

echo "--- final roles (D-04)\n";
foreach (['bcrm_after_sales_manager', 'bcrm_dissatisfaction_supervisor', 'bcrm_after_sales_agent', 'bcrm_it_supervisor', 'bcrm_it_agent', 'bcrm_treasury_supervisor', 'bcrm_treasury_agent'] as $r) {
    F(isset($defs[$r]), "role $r defined");
    F(preg_match("/'" . $r . "'\s*=>\s*'[^']+'/u", $auth) === 1, "role $r listed in portal role_labels (users page + unit membership)");
}
F($same($all('bcrm_dissatisfaction_supervisor'), ['read','bcrm_work_cases','bcrm_complaints','view_after_sales','view_dissatisfaction','manage_dissatisfaction','assign_dissatisfaction','process_dissatisfaction','view_after_sales_reports']), 'dissatisfaction supervisor: exactly the supervisor dissatisfaction caps');
F($same($all('bcrm_after_sales_agent'), ['read','bcrm_work_cases','bcrm_complaints','view_after_sales','view_dissatisfaction','process_dissatisfaction']), 'after-sales agent: exactly the agent dissatisfaction caps');
F($same($all('bcrm_it_supervisor'), ['read','bcrm_work_cases','view_it_inquiries','respond_it_inquiries']) && $same($all('bcrm_it_agent'), ['read','bcrm_work_cases','view_it_inquiries','respond_it_inquiries']), 'IT supervisor/agent: portal login + IT inquiry caps only');
F(in_array('view_it_inquiries', $all('bcrm_after_sales_manager'), true) && !in_array('respond_it_inquiries', $all('bcrm_after_sales_manager'), true), 'after-sales manager views (not answers) IT inquiries, like finance/loan/service');
$broad = ['bcrm_manage_cases','bcrm_import','bcrm_reports','bcrm_sensitive_merge','bcrm_financial_verify','bcrm_services','bcrm_refunds','bcrm_refund_register','manage_after_sales_settings','manage_inquiry_units','manage_treasury','process_refund_payment'];
foreach (['bcrm_dissatisfaction_supervisor','bcrm_after_sales_agent','bcrm_it_supervisor','bcrm_it_agent'] as $r) F(!array_intersect($all($r), $broad), "$r has no broad/unrelated capability");
F(!array_intersect($all('bcrm_it_supervisor'), ['bcrm_complaints','view_dissatisfaction','view_finance_inquiries','view_treasury']), 'IT roles see no complaint/finance/treasury data');

echo "--- legacy compatibility\n";
F(isset($defs['bcrm_complaint_supervisor'], $defs['bcrm_complaint_agent']), 'legacy complaint roles still defined (not deleted)');
F($same($all('bcrm_complaint_supervisor'), $all('bcrm_dissatisfaction_supervisor')) && $same($all('bcrm_complaint_agent'), $all('bcrm_after_sales_agent')), 'legacy roles keep exactly their previous caps (= final equivalents; no duplicate semantics beyond the compatibility alias)');
$relabel = $fn($act, 'relabel_roles');
F(strpos($relabel, "=== \$old") !== false, 'relabel only when the label is still the plugin default');
F(preg_match("/in_array\('bcrm_complaint_supervisor'/", $strip($front . $wf . $adm)) === 0, 'no hard-coded legacy supervisor check left (front/workflow/admin)');
F(substr_count($front, 'Biawin_CRM_Auth::is_dissatisfaction_supervisor(') === 2 && substr_count($wf, 'Biawin_CRM_Auth::is_dissatisfaction_supervisor(') === 1 && substr_count($adm, 'Biawin_CRM_Auth::is_dissatisfaction_supervisor(') === 1, 'the 4 former call sites use the shared helper');
F(strpos($fn($auth, 'dissatisfaction_supervisor_roles'), "['bcrm_dissatisfaction_supervisor', 'bcrm_complaint_supervisor']") !== false, 'helper = final + legacy supervisor only (no widening to manager/general supervisor)');

echo "--- general supervisor (behaviour preserved; reduction deferred to Phase 3 permission migration)\n";
F(isset($extra['bcrm_supervisor']) && $same($extra['bcrm_supervisor'], ['view_after_sales','view_dissatisfaction','manage_dissatisfaction','assign_dissatisfaction','process_dissatisfaction','view_after_sales_reports']), 'bcrm_supervisor keeps its six after-sales dissatisfaction caps (same as 0.8.64.1, fresh installs included)');
F(in_array('bcrm_complaints', (array)$defs['bcrm_supervisor']['caps'], true) && in_array('bcrm_manage_cases', (array)$defs['bcrm_supervisor']['caps'], true), 'bcrm_supervisor keeps bcrm_complaints + bcrm_manage_cases');
F(strpos($act, 'Deferred to Phase 3 permission migration') !== false, 'deferral documented in code');

echo "--- IT isolation / after-sales manager boundary\n";
$mgr = $all('bcrm_after_sales_manager');
F(!array_intersect($mgr, ['bcrm_manage_cases','manage_inquiry_units','respond_it_inquiries','bcrm_import','bcrm_reports']), 'after-sales manager: no unit/member/user/assignment admin (bcrm_manage_cases), no inquiry-unit config (manage_inquiry_units), cannot answer IT inquiries');
F(strpos($adm, "'واحدها و اعضا', 'واحدها و اعضا', 'bcrm_manage_cases'") !== false && strpos($adm, "'کاربران پرتال', 'کاربران پرتال', 'bcrm_manage_cases'") !== false && strpos($adm, "'تخصیص پرونده', 'تخصیص پرونده', 'bcrm_manage_cases'") !== false, 'unit/member, user and assignment management stay behind bcrm_manage_cases');
F(strpos($read('includes/class-biawin-crm-after-sales-config-admin.php'), "'units' => ['واحدهای قابل استعلام', 'manage_inquiry_units']") !== false, 'inquiry-unit configuration stays behind manage_inquiry_units');
$it_holders = array_keys(array_filter($extra, static fn($c) => array_intersect((array)$c, ['view_it_inquiries','respond_it_inquiries'])));
sort($it_holders);
F($it_holders === ['administrator','bcrm_after_sales_manager','bcrm_it_agent','bcrm_it_supervisor','bcrm_portal_admin'], 'IT inquiry caps only on IT roles, admin, portal admin and (view) after-sales manager: ' . implode(',', $it_holders));
F($seeds['it_engineering'][2] === '' && $seeds['dissatisfaction'][2] === 'after_sales', 'IT unit independent (no parent); not inside after-sales');

echo "--- capabilities\n";
F(isset($caps['view_it_inquiries'], $caps['respond_it_inquiries']), 'IT inquiry capabilities defined');
F($same(array_intersect(['view_it_inquiries','respond_it_inquiries'], $extra['administrator']), ['view_it_inquiries','respond_it_inquiries']) && $same(array_intersect(['view_it_inquiries','respond_it_inquiries'], $extra['bcrm_portal_admin']), ['view_it_inquiries','respond_it_inquiries']), 'administrator + portal admin receive IT caps');
F(strpos($adm, '+ Biawin_CRM_Activator::after_sales_capabilities()') !== false, 'IT caps grantable per user in «کاربران پرتال» (existing mechanism)');
foreach (['view_finance_inquiries','respond_finance_inquiries','view_loan_inquiries','respond_loan_inquiries','view_service_inquiries','respond_service_inquiries','view_treasury','manage_treasury','manage_treasury_capacity','approve_refund_schedule','process_refund_payment','manage_inquiry_units'] as $c) F(isset($caps[$c]), "existing cap $c still defined");

echo "--- units\n";
F(isset($seeds['it_engineering']) && $seeds['it_engineering'][0] === 'مهندسین IT' && $seeds['it_engineering'][1] === 'it' && $seeds['it_engineering'][2] === '' && $seeds['it_engineering'][3] === 1 && $seeds['it_engineering'][4] === 1, 'IT unit: top-level, type it, inquiry-enabled, portal response');
F(isset($seeds['treasury']) && $seeds['treasury'][1] === 'treasury' && $seeds['treasury'][2] === '', 'treasury seeded top-level (fresh installs only)');
F(isset($seeds['after_sales'], $seeds['dissatisfaction']) && $seeds['dissatisfaction'][2] === 'after_sales', 'after-sales unit with dissatisfaction child (manager = after_sales supervisor; dissatisfaction supervisor + agents = child unit)');
F(count($seeds) === 7 && count(array_unique(array_keys($seeds))) === 7, 'seven stable unit keys, no duplicates');
F(strpos($adm, "'it' => 'مهندسین IT'") !== false, 'unit type label it');
F(strpos($fn($adm, 'unit_member_roles'), 'Biawin_CRM_Auth::role_labels()') !== false, 'new roles eligible as unit members through the existing role list');

echo "TOTAL_FAIL=$fail (passed $pass)\n";
exit($fail ? 1 : 0);
