<?php
/**
 * v0.8.66.1 — static guards for the Phase 2 correction pass.
 * Runtime behaviour: tests/update-simulation-v08661/run-p2correction.sh (correction.php on real WordPress).
 * Usage: php tests/journey-correction-v08661.php
 */
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function F($c, $label) { global $pass, $fail; echo ($c ? 'PASS  ' : 'FAIL  ') . $label . "\n"; $c ? $pass++ : $fail++; }
$read = static fn($rel) => (string)file_get_contents($root . '/' . $rel);
$strip = static fn($src) => preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $src);
$fn = static function (string $src, string $name) { return preg_match('/function ' . preg_quote($name, '/') . '\(.*?\n    \}/s', $src, $m) ? $m[0] : ''; };
$wf = $read('includes/class-biawin-crm-workflow-service.php');
$def = $read('includes/class-biawin-crm-deficiency-workflow.php');
$cs = $read('includes/class-biawin-crm-customer-service.php');
$act = $read('includes/class-biawin-crm-activator.php');
$front = $read('includes/class-biawin-crm-frontend.php');
$rep = $read('includes/class-biawin-crm-journey-reports.php');
$main = $read('biawin-crm-integration.php');

echo "--- release\n";
F(strpos($main, "define('BIAWIN_CRM_VERSION', '0.8.66.1')") !== false, 'plugin version 0.8.66.1');
F(substr_count($act, 'CREATE TABLE') === 32 && strpos($act, "'0.8.66.1' =>") === false && strpos($act, "'0.8.66.0' =>") === false, 'no schema change, no migration');

echo "--- D-01 zero mismatch through ONE rule\n";
F(strpos($fn($wf, 'completeness_rule'), "=== 'zero' ? 'zero' : 'legacy_tolerance'") !== false, 'two values only: zero | legacy_tolerance');
F(strpos($fn($wf, 'completeness_tolerance'), "=== 'zero' ? 0.0 :") !== false, 'zero rule → tolerance 0');
F(strpos($fn($wf, 'amounts_match'), 'self::completeness_tolerance() + 0.004') !== false, 'amounts_match = |declared − invoice total| ≤ tolerance (+ DECIMAL rounding only)');
F(!preg_match('/max\(500000/', $strip($def . $front . $cs)) && strpos($act, "\$tolerance = Biawin_CRM_Workflow_Service::completeness_tolerance();") !== false, 'no inline tolerance left in deficiency, frontend, customer service or backfill');
F(substr_count($def, 'Biawin_CRM_Workflow_Service::completeness_tolerance()') === 4, 'all 4 deficiency tolerance checks use the shared rule');
F(strpos($fn($front, 'ajax_preview_reconciliation'), 'amounts_match($declared, $total)') !== false, 'preview uses the same rule');
$a = $fn($act, 'activate');
F(strpos($a, "get_option('biawin_crm_db_version', '') === ''") !== false && strpos($a, "add_option(Biawin_CRM_Workflow_Service::COMPLETENESS_RULE_OPTION, 'zero'") !== false, 'fresh install starts with zero; existing installs are never switched on upgrade');
F(!preg_match("/update_option\([^)]*COMPLETENESS_RULE_OPTION/", $strip($act . $wf . $read('admin/class-biawin-crm-admin.php'))), 'no code path switches an existing install (approved step only)');
F(strpos($rep, "'historical_after_deficiency_without_reallocation'") !== false && strpos($rep, 'service_cases_total') !== false && strpos($rep, 'KPIهای متأثر') !== false, 'impact report: would-change, new cases, KPIs, historical completions, service-case totals');

echo "--- D-07 route authority\n";
$g = $fn($wf, 'journey_gate');
F(strpos($g, 'bcrm_manage_cases') === false && strpos($g, "\$gate['can_select'] = \$active && (int)\$active->agent_user_id === \$user_id;") !== false, 'only the active assigned contact agent');
F(strpos($read('includes/class-biawin-crm-activator.php'), "'bcrm_supervisor' => ['label' => 'سرپرست CRM', 'caps' => \$crm_all]") !== false, 'bcrm_manage_cases / supervisor capabilities untouched');

echo "--- D-08 open deficiency case locks\n";
F(strpos($g, "\$gate['state'] = 'deficiency_open';") !== false && strpos($g, "status NOT IN ('COMPLETED','ARCHIVED')") !== false && strpos($g, 'deficiency_open') < strpos($g, 'reallocation_marker('), 'open case checked after completeness and before reallocation/new contact');

echo "--- new contact = successful contact\n";
F(strpos($fn($wf, 'successful_contact_keys'), "=== 'contact_success'") !== false && strpos($g, 'outcome_key IN (') !== false, 'only outcomes with operational_status contact_success count (admin-configurable catalog respected)');

echo "--- D-09 declared amount source of truth\n";
$rc = $fn($cs, 'recalculate_customer');
F(strpos($rc, "action_type = 'CASE_COMPLETED_WITH_CORRECTED_DECLARED'") !== false && strpos($rc, "strcmp((string)\$correction->created_at, (string)\$declared_row->created_at) > 0") !== false, 'a valid correction newer than the last statement supersedes it; a newer statement wins');
F(!preg_match('/->insert\(|->update\([^;]*interactions|ALTER TABLE|ensure_column/', $strip(substr($rc, 0, strpos($rc, '$updated = $wpdb->update')))), 'no new field, no copied interaction, no history rewrite');

echo "--- D-10 finance double count\n";
F(strpos($def, "maybe_auto_complete_after_finance(\$fresh, \$finance_approved_amount, \$result === 'with_invoice')") !== false, 'with_invoice flagged as already part of the invoice total');
$m = $fn($def, 'maybe_auto_complete_after_finance');
F(preg_match('/if \(\$amount_is_invoice\) \{\s*\$reconciled = \(float\)\$customer->total_paid;/', $m) === 1 && strpos($m, '$reconciled = $registered + max(0, $finance_approved_amount);') !== false, 'invoice counted once; payment without invoice still added on top');
F(strpos($fn($def, 'send_to_finance'), "current_user_can('send_case_to_finance')") !== false && strpos($fn($def, 'record_finance_result'), "['FINANCE_REVIEW_PENDING','FINANCE_ASSIGNED','FINANCE_UNDER_REVIEW']") !== false, 'finance architecture unchanged (optional step, status guard = idempotency)');

echo "--- archive / boundary unchanged\n";
F(!preg_match("/status='completed'|'status'\s*=>\s*'completed'/", $fn($wf, 'select_customer_route') . $fn($wf, 'on_deficiency_completed')), 'no assignment.status=completed (D-05 reported, not implemented)');
F(strpos($fn($wf, 'record_additional_services_boundary'), "'biawin_status' => 'NOT_SENT'") !== false && !preg_match('/wp_remote_|curl_init/', $strip($wf)), 'boundary internal only, no HTTP');

echo "TOTAL_FAIL=$fail (passed $pass)\n";
exit($fail ? 1 : 0);
