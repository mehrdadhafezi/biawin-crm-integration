<?php
$pass = 0;
$fail = 0;

function d594_assert_true($condition, string $name): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}

function d594_body(string $source, string $method, string $next): string {
    $start = strpos($source, 'function ' . $method);
    if ($start === false) return '';
    $end = strpos($source, $next, $start + 1);
    return $end === false ? substr($source, $start) : substr($source, $start, $end - $start);
}

$root = dirname(__DIR__);
$main = file_get_contents($root . '/biawin-crm-integration.php');
$admin = file_get_contents($root . '/admin/class-biawin-crm-admin.php');
$frontend = file_get_contents($root . '/includes/class-biawin-crm-frontend.php');
$workflow = file_get_contents($root . '/includes/class-biawin-crm-deficiency-workflow.php');
$activator = file_get_contents($root . '/includes/class-biawin-crm-activator.php');
$js = file_get_contents($root . '/assets/portal-fixes.js');
$css = file_get_contents($root . '/assets/portal-fixes.css');

$users_page = d594_body($admin, 'users_page', 'public static function handle_save_portal_user');
$delete_user = d594_body($admin, 'handle_delete_portal_user', 'public static function handle_reset_portal_password');
$assign_handler = d594_body($admin, 'handle_deficiency_assign', 'public static function handle_deficiency_export');
$render_dashboard = d594_body($workflow, 'render_dashboard', 'private static function render_case_table');
$render_case_table = d594_body($workflow, 'render_case_table', 'private static function render_assignment_panel');
$assignment_panel = d594_body($workflow, 'render_assignment_panel', 'private static function render_case_detail');
$return_assignments = d594_body($workflow, 'return_assignments', 'public static function process_action');
$save_document = d594_body($workflow, 'save_uploaded_document', 'private static function record_contact');
$send_finance = d594_body($workflow, 'send_to_finance', 'private static function complete_with_corrected_declared_amount');

d594_assert_true(strpos($main, "Version: 0.8.66.1") !== false && strpos($main, "define('BIAWIN_CRM_VERSION', '0.8.66.1')") !== false, 'version bumped to 0.8.66.1');
// v0.8.62.14: legacy equality-chain upgrade was replaced by sequential idempotent migrations (bcrm_upgrade_lock).
d594_assert_true(strpos($activator, "MIGRATIONS = [") !== false && strpos($activator, "'0.8.62.0' => 'migrate_0862_0'") !== false && strpos($activator, "bcrm_upgrade_lock") !== false, 'upgrade chain remains code-only, migration-driven, locked');

d594_assert_true(strpos($admin, "admin_post_bcrm_delete_portal_user") !== false, 'delete portal user action registered');
d594_assert_true(strpos($users_page, "bcrm_delete_portal_user") !== false && strpos($users_page, "حذف") !== false, 'delete portal user button rendered');
d594_assert_true(strpos($delete_user, "wp_delete_user") !== false, 'delete handler deletes user');
d594_assert_true(strpos($delete_user, "bcrm_work_cases") !== false && strpos($delete_user, "manage_options") !== false, 'delete handler is limited to non-admin portal users');
d594_assert_true(strpos($delete_user, "get_current_user_id") !== false, 'delete handler blocks deleting current user');

d594_assert_true(strpos($frontend, "add_filter('request'") !== false && strpos($frontend, 'normalize_portal_request') !== false, 'portal request normalizer registered');
d594_assert_true(strpos($frontend, "\$query_vars['bcrm_s'] = \$query_vars['s']") !== false && strpos($frontend, "unset(\$query_vars['s'])") !== false, 'portal s query is moved away from WordPress search');
d594_assert_true(strpos($frontend, "'bcrm_s'") !== false && strpos($render_case_table, "\$_GET['bcrm_s']") !== false, 'deficiency search uses bcrm_s in portal');
d594_assert_true(strpos($render_case_table, 'name="bcrm_s"') !== false, 'deficiency search form emits bcrm_s');

d594_assert_true(strpos($assignment_panel, 'return_agent_id') !== false && strpos($assignment_panel, 'انتخاب کارشناس') !== false, 'return assignment requires selecting an agent');
d594_assert_true(strpos($assign_handler, 'return_assignments($case_ids, $department, absint($_POST') !== false, 'return handler passes selected agent');
d594_assert_true(strpos($return_assignments, 'برای برگشت تخصیص، ابتدا کارشناس را انتخاب کنید') !== false, 'return backend rejects missing agent');
d594_assert_true(strpos($return_assignments, '$case->{$agent_col}') !== false && strpos($return_assignments, '$returnable_status') !== false, 'return backend limits cases to selected agent and assigned status');

// v0.8.62.14: return queue moved to the dedicated «برگشت تخصیص» tab (returnable_queue_rows filters by agent).
d594_assert_true(strpos($workflow, "returnable_queue_rows") !== false && strpos($workflow, "\$return_agent_id = absint(\$_GET['return_agent_id'] ?? 0)") !== false, 'return table is filtered by selected agent (dedicated return tab)');
d594_assert_true(strpos($render_case_table, 'deficiency_agent_id') !== false && strpos($render_case_table, 'finance_agent_id') !== false, 'case table supports agent filter for both departments');

d594_assert_true(strpos($send_finance, 'receipt_amounts') !== false && strpos($send_finance, 'case_documents') !== false, 'send-to-finance accepts multiple receipt rows');
d594_assert_true(strpos($send_finance, 'foreach ($pairs as $i => $pair)') !== false, 'send-to-finance loops over receipts (canonical order-paired rows)');
d594_assert_true(strpos($send_finance, 'receipt_count') !== false && strpos($send_finance, 'attachment_ids') !== false, 'send-to-finance records receipt count and attachment ids');
d594_assert_true(strpos($save_document, 'Biawin_CRM_Upload_Normalizer::normalize_field') !== false && strpos($save_document, 'bcrm_case_document_upload') !== false, 'document upload supports file-array entries via canonical normalization');
d594_assert_true(strpos($workflow, 'data-bcrm-add-receipt') !== false && strpos($workflow, 'case_documents[]') !== false, 'send-to-finance UI exposes addable receipt rows');
d594_assert_true(strpos($js, 'data-bcrm-add-receipt') !== false && strpos($js, 'cloneNode') !== false, 'receipt add button is wired');

d594_assert_true(strpos($render_dashboard, 'bcrm-kpi-help') !== false && strpos($render_dashboard, "'help'") !== false, 'workflow KPI cards include help tooltips');
d594_assert_true(strpos($render_dashboard, "current_department='deficiency' AND status='INCOMPLETE_ASSIGNED'") !== false, 'deficiency assigned KPI is department-specific');
d594_assert_true(strpos($render_dashboard, "current_department='finance' AND status='FINANCE_ASSIGNED'") !== false, 'finance assigned KPI is department-specific');
d594_assert_true(strpos($render_dashboard, "READY_FOR_FINAL_DECISION") !== false, 'deficiency final-decision KPI is explicit');
d594_assert_true(strpos($css, '.bcrm-kpi-help') !== false && strpos($css, '.bcrm-receipt-row') !== false, 'KPI help and receipt rows have CSS');

echo "TOTAL_PASS=$pass\n";
echo "TOTAL_FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
