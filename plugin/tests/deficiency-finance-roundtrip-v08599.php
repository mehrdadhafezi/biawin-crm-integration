<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/biawin-crm-integration.php');
$activator = file_get_contents($root . '/includes/class-biawin-crm-activator.php');
$workflow = file_get_contents($root . '/includes/class-biawin-crm-deficiency-workflow.php');
$frontend_js = file_get_contents($root . '/assets/frontend.js');
$fix_js = file_get_contents($root . '/assets/portal-fixes.js');
$readme = file_get_contents($root . '/readme.txt');

$pass = 0;
$fail = 0;
function d599_assert_true($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: $message\n";
    } else {
        $fail++;
        echo "FAIL: $message\n";
    }
}
function d599_body($source, $start, $end) {
    $pos = strpos($source, $start);
    if ($pos === false) return '';
    $next = strpos($source, $end, $pos + strlen($start));
    return $next === false ? substr($source, $pos) : substr($source, $pos, $next - $pos);
}

$process_deficiency = d599_body($workflow, 'private static function process_deficiency_submit', 'private static function process_finance_submit');
$helpers = d599_body($workflow, 'private static function uploaded_file_indexes', 'private static function save_uploaded_document');
$send_to_finance = d599_body($workflow, 'private static function send_to_finance', 'private static function record_returned_invoice');
$returned_invoice = d599_body($workflow, 'private static function record_returned_invoice', 'private static function complete_with_corrected_declared_amount');
$render_forms = d599_body($workflow, 'private static function render_action_forms', 'private static function form_base');
$format_meta = d599_body($workflow, 'private static function format_timeline_meta', 'private static function render_reports');
$event_label = d599_body($workflow, 'private static function event_label', 'private static function money');

// Updated for v0.8.60.1: version chain moved forward; upload-semantics assertions below
// were rewritten for the order-pairing fix (see QA-REPORT-v0.8.60.0.md).
d599_assert_true(strpos($main, 'Version: 0.8.66.1') !== false && strpos($main, "BIAWIN_CRM_VERSION', '0.8.66.1'") !== false, 'version bumped to 0.8.66.1');
d599_assert_true(strpos($readme, 'Stable tag: 0.8.66.1') !== false, 'readme stable tag bumped');
// v0.8.62.14: legacy equality-chain upgrade was replaced by sequential idempotent migrations (bcrm_upgrade_lock).
d599_assert_true(strpos($activator, "MIGRATIONS = [") !== false && strpos($activator, "'0.8.62.0' => 'migrate_0862_0'") !== false && strpos($activator, "bcrm_upgrade_lock") !== false, 'upgrade is code-only, migration-driven, locked');

d599_assert_true(strpos($helpers, 'Biawin_CRM_Upload_Normalizer::normalize_field') !== false, 'scalar upload field is normalized canonically (no per-row positional guessing)');
d599_assert_true(strpos($helpers, 'Biawin_CRM_Upload_Normalizer::content_hash') !== false, 'upload file signature helper exists (SHA-256 content identity)');
d599_assert_true(strpos($send_to_finance, '$seen_receipt_files = []') !== false, 'finance handoff tracks seen receipt files per request');
d599_assert_true(strpos($send_to_finance, 'isset($seen_receipt_files[$signature])') !== false, 'duplicate receipt file signatures are skipped');
d599_assert_true(strpos($send_to_finance, "'RETURNED_TO_DEFICIENCY'") !== false, 'returned deficiency cases can be sent back to finance');
d599_assert_true(strpos($send_to_finance, 'Biawin_CRM_Upload_Normalizer::normalize_field') !== false, 'receipt pairing derives from canonical $_FILES normalization');

d599_assert_true(strpos($process_deficiency, "if (\$step === 'return_invoice') return self::record_returned_invoice") !== false, 'deficiency submit routes returned-invoice action');
d599_assert_true(strpos($returned_invoice, "(string)\$case->status !== 'RETURNED_TO_DEFICIENCY'") !== false, 'returned-invoice action is scoped to returned cases');
d599_assert_true(strpos($returned_invoice, 'create_manual_invoice_with_phone') !== false && strpos($returned_invoice, 'create_manual_invoice(') !== false, 'returned-invoice action can create invoice with or without phone');
d599_assert_true(strpos($returned_invoice, "'READY_FOR_FINAL_DECISION'") !== false && strpos($returned_invoice, "'DEFICIENCY_UNDER_REVIEW'") !== false, 'returned-invoice action moves case to a follow-up deficiency status');
d599_assert_true(strpos($returned_invoice, 'registered_invoice_amount') !== false && strpos($returned_invoice, 'difference_amount') !== false, 'returned-invoice action refreshes case financial fields');

d599_assert_true(strpos($render_forms, 'RETURNED_TO_DEFICIENCY') !== false && strpos($render_forms, 'value="return_invoice"') !== false, 'returned-invoice option appears only for returned cases');
d599_assert_true(strpos($render_forms, 'data-bcrm-step="return_invoice"') !== false && strpos($render_forms, 'return_invoice_number') !== false && strpos($render_forms, 'return_invoice_amount') !== false, 'returned-invoice form fields render');
d599_assert_true(strpos($fix_js, 'data-bcrm-add-receipt') !== false && strpos($fix_js, 'initReceiptUploader(form);') !== false, 'receipt add controls remain wired (single uploader in portal-fixes.js since v0.8.62.27)');
// v0.8.62.24: the diagnostics panel MENTIONS max_file_uploads as an ini readout; the cap must
// never be ENFORCED in code — assert the guard family (check_server_limits + a numeric cap
// on staged rows) stays confined to the normalizer/staging services, not the workflow render.
$d599_norm = file_get_contents($root . '/includes/class-biawin-crm-upload-normalizer.php');
d599_assert_true(strpos($workflow, 'LIMIT 4') === false && strpos($d599_norm, 'max_file_uploads') !== false && strpos($workflow, 'MAX_STAGED_FILES') === false, 'workflow enforces no code-level receipt cap (cap guards live in the normalizer/staging services)');
d599_assert_true(strpos($format_meta, "'return_invoice_number'=>'شماره فاکتور برگشتی'") !== false, 'timeline has returned-invoice metadata labels');
d599_assert_true(strpos($event_label, "'RETURNED_INVOICE_RECORDED'") !== false, 'timeline event label exists for returned invoice');

echo "TOTAL_PASS=$pass\nTOTAL_FAIL=$fail\n";
exit($fail ? 1 : 0);

