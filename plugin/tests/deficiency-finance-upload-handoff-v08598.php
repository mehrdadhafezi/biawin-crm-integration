<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/biawin-crm-integration.php');
$activator = file_get_contents($root . '/includes/class-biawin-crm-activator.php');
$workflow = file_get_contents($root . '/includes/class-biawin-crm-deficiency-workflow.php');
$rest = file_get_contents($root . '/includes/class-biawin-crm-rest.php');
$frontend_js = file_get_contents($root . '/assets/frontend.js');
$fix_js = file_get_contents($root . '/assets/portal-fixes.js');
$css = file_get_contents($root . '/assets/portal-fixes.css');
$readme = file_get_contents($root . '/readme.txt');

$pass = 0;
$fail = 0;
function d598_assert_true($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: $message\n";
    } else {
        $fail++;
        echo "FAIL: $message\n";
    }
}
function d598_body($source, $start, $end) {
    $pos = strpos($source, $start);
    if ($pos === false) return '';
    $next = strpos($source, $end, $pos + strlen($start));
    return $next === false ? substr($source, $pos) : substr($source, $pos, $next - $pos);
}

$send_to_finance = d598_body($workflow, 'private static function send_to_finance', 'private static function complete_with_corrected_declared_amount');
$render_forms = d598_body($workflow, 'private static function render_action_forms', 'private static function form_base');
$format_meta = d598_body($workflow, 'private static function format_timeline_meta', 'private static function render_reports');
$render_reports = d598_body($workflow, 'private static function render_reports', 'public static function export_csv');
$export_csv = d598_body($workflow, 'public static function export_csv', 'private static function tab_where');
$rest_export = d598_body($rest, 'public static function workflow_export', 'public static function deficiency_case_action');

d598_assert_true(strpos($main, "Version: 0.8.66.1") !== false && strpos($main, "define('BIAWIN_CRM_VERSION', '0.8.66.1')") !== false, 'version bumped to 0.8.66.1');
d598_assert_true(strpos($readme, 'Stable tag: 0.8.66.1') !== false, 'readme stable tag bumped');
// v0.8.62.14: legacy equality-chain upgrade was replaced by sequential idempotent migrations (bcrm_upgrade_lock).
d598_assert_true(strpos($activator, "MIGRATIONS = [") !== false && strpos($activator, "'0.8.62.0' => 'migrate_0862_0'") !== false && strpos($activator, "bcrm_upgrade_lock") !== false, 'upgrade is code-only, migration-driven, locked');

d598_assert_true(strpos($render_forms, 'ارسال به تایید مالی با فیش') === false, 'old with-receipt option label removed');
d598_assert_true(strpos($render_forms, '<option value="send_finance">ارسال به تایید مالی</option>') !== false, 'finance handoff option has new label');
d598_assert_true(strpos($render_forms, 'name="send_receipt"') !== false && strpos($render_forms, 'data-bcrm-receipt-toggle') !== false, 'send receipt checkbox rendered');
d598_assert_true(strpos($render_forms, 'name="refund_status" value="refunded"') !== false && strpos($render_forms, 'وضعیت عودت: عودت شده') !== false, 'refunded status checkbox rendered');
d598_assert_true(strpos($render_forms, 'data-bcrm-receipt-fields') !== false && strpos($render_forms, 'data-bcrm-receipts') !== false, 'receipt upload fields are nested behind toggle container');

d598_assert_true(strpos($workflow, 'Biawin_CRM_Upload_Normalizer::normalize_field') !== false, 'backend has canonical $_FILES normalization helpers');
d598_assert_true(strpos($send_to_finance, 'if ($send_receipt)') !== false, 'backend only processes receipt uploads when requested');
d598_assert_true(strpos($send_to_finance, 'if (!$documents)') !== false, 'receipt requirement remains scoped to send_receipt');
d598_assert_true(strpos($send_to_finance, 'Biawin_CRM_Upload_Normalizer::normalize_field') !== false, 'backend processes receipts through canonical file normalization');
d598_assert_true(strpos($send_to_finance, 'فایل همه فیش‌های ارسال به مالی الزامی است') !== false, 'receipt handoff requires a file for each receipt amount');
d598_assert_true(strpos($send_to_finance, 'REFUND_COMPLETED_SENT_TO_FINANCE') !== false, 'refunded handoff adds customer tag');
d598_assert_true(strpos($send_to_finance, "'refund_status'=>\$refund_status") !== false && strpos($send_to_finance, "'send_receipt'=>\$send_receipt ? 1 : 0") !== false, 'finance handoff metadata stores receipt and refund status');

d598_assert_true(strpos($frontend_js, 'data-bcrm-receipt-toggle') !== false && strpos($frontend_js, 'data-bcrm-receipt-fields') !== false, 'main frontend JS toggles receipt fields');
d598_assert_true(strpos($fix_js, 'data-bcrm-receipt-toggle') !== false && strpos($fix_js, 'data-bcrm-receipt-fields') !== false, 'portal fixes JS toggles receipt fields');
d598_assert_true(strpos($css, '[data-bcrm-receipt-fields]{') !== false && strpos($css, '[data-bcrm-receipt-fields].is-active') !== false, 'receipt fields hidden until active');

d598_assert_true(strpos($format_meta, "'send_receipt'=>'ارسال فیش'") !== false && strpos($format_meta, "'refund_status'=>'وضعیت عودت'") !== false, 'timeline metadata labels receipt and refund flags');
d598_assert_true(strpos($format_meta, "\$value = 'عودت شده'") !== false, 'timeline renders refunded status in Persian');
d598_assert_true(strpos($render_reports, 'ارسال به مالی با وضعیت عودت شده') !== false && strpos($render_reports, 'metadata_json LIKE') !== false, 'reports count refunded finance handoffs');
d598_assert_true(strpos($export_csv, 'وضعیت عودت') !== false && strpos($export_csv, 'عودت شده') !== false, 'CSV export includes refund status');
d598_assert_true(strpos($rest_export, 'Refund Status') !== false && strpos($rest_export, "['refund_status']") !== false, 'REST export includes refund status');

echo "TOTAL_PASS=$pass\nTOTAL_FAIL=$fail\n";
exit($fail ? 1 : 0);

