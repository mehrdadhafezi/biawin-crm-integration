<?php
$pass = 0;
$fail = 0;

function d593_assert_true($condition, string $name): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}

function d593_method_body(string $source, string $method, string $next_marker): string {
    $start = strpos($source, 'function ' . $method);
    if ($start === false) return '';
    $end = strpos($source, $next_marker, $start + 1);
    return $end === false ? substr($source, $start) : substr($source, $start, $end - $start);
}

$root = dirname(__DIR__);
$main = file_get_contents($root . '/biawin-crm-integration.php');
$activator = file_get_contents($root . '/includes/class-biawin-crm-activator.php');
$workflow = file_get_contents($root . '/includes/class-biawin-crm-deficiency-workflow.php');
$admin = file_get_contents($root . '/admin/class-biawin-crm-admin.php');

$case_table = d593_method_body($workflow, 'render_case_table', 'private static function render_assignment_panel');
$assignment_panel = d593_method_body($workflow, 'render_assignment_panel', 'private static function render_return_panel');
$return_panel = d593_method_body($workflow, 'render_return_panel', 'private static function render_case_detail');
$return_assignments = d593_method_body($workflow, 'return_assignments', 'public static function process_action');
$assign_handler = d593_method_body($admin, 'handle_deficiency_assign', 'public static function handle_deficiency_export');

d593_assert_true(strpos($main, "Version: 0.8.66.1") !== false && strpos($main, "define('BIAWIN_CRM_VERSION', '0.8.66.1')") !== false, 'version bumped to 0.8.66.1');
d593_assert_true(strpos($activator, "'0.8.62.14' => 'migrate_0862_11'") !== false || strpos($activator, "'0.8.62.11' => 'migrate_0862_11'") !== false, 'sequential idempotent migrations registered (code-only upgrade path)');
d593_assert_true(strpos($case_table, 'normalized_phone') !== false && strpos($case_table, ') phone') !== false, 'assignment/selectable case table exposes primary mobile number');
d593_assert_true(strpos($case_table, '<th>موبایل</th>') !== false, 'case table renders mobile column');
d593_assert_true(strpos($case_table, 'esc_html($r->phone ?:') !== false, 'case table renders mobile value with safe fallback');
d593_assert_true(strpos($assignment_panel, "self::render_case_table('new', \$finance)") !== false, 'assignment page renders ready-to-assign queue');
d593_assert_true(strpos($workflow, "elseif (\$tab === 'return') self::render_return_panel(\$is_finance)") !== false, 'dedicated return-assignment tab is routed');
d593_assert_true(strpos($return_panel, 'name="return_status[]"') !== false, 'return tab lets supervisor pick which statuses to return');
d593_assert_true(strpos($return_panel, 'data-bcrm-group') !== false, 'return tab has select-all checkboxes');
d593_assert_true(strpos($return_panel, 'bcrm_paged') !== false && strpos($return_panel, 'bcrm_pp') !== false, 'return tab has pagination and per-page selector');
d593_assert_true(strpos($workflow, 'agent_assignment_report') !== false && strpos($workflow, 'وضعیت تخصیص هر کارشناس') !== false, 'per-agent per-status assignment report rendered on assign tab');
d593_assert_true(strpos($assignment_panel, 'صف آماده تخصیص رفع ناقصی') !== false, 'deficiency ready queue heading exists');
d593_assert_true(strpos($assignment_panel, 'name="agent_user_ids[]" multiple size="6"') !== false && strpos($assignment_panel, 'return_agent_id') !== false, 'assignment user select and return-agent select are separate controls');
d593_assert_true(strpos($assignment_panel, 'name="return_assignment" value="1" formnovalidate') !== false, 'return assignment button bypasses browser validation');
d593_assert_true(strpos($assign_handler, "\$required = \$department === 'finance' ? 'assign_finance_cases' : 'assign_deficiency_cases'") !== false, 'deficiency supervisor assignment action requires assign_deficiency_cases');
d593_assert_true(strpos($assign_handler, 'return_assignments($case_ids, $department, absint($_POST') !== false, 'same supervisor action can return selected assignments for selected agent');
d593_assert_true(strpos($return_assignments, "'deficiency_agent_id'=>null") !== false && strpos($return_assignments, "'status'=>'INCOMPLETE_NEW'") !== false && strpos($return_assignments, '$agent_id') !== false, 'deficiency return clears selected agent and returns case to new queue');
d593_assert_true(strpos($return_assignments, 'DEFICIENCY_ASSIGNMENT_RETURNED') !== false, 'deficiency return writes explicit audit event');
d593_assert_true(strpos($assignment_panel, "['bcrm_deficiency_agent']") !== false, 'deficiency assignment targets deficiency agents');
d593_assert_true(strpos($workflow, "\$agent->has_cap('process_deficiency_case')") !== false, 'assignment still validates target agent capability');

// v0.8.62.15: excel-based batch assignment (Customer ID file → one agent)
d593_assert_true(strpos($assignment_panel, 'تخصیص از فایل اکسل (Customer ID)') !== false && strpos($assignment_panel, 'name="assign_customer_file"') !== false, 'assign tab renders excel Customer ID upload form');
d593_assert_true(strpos($workflow, 'public static function assign_from_customer_file(') !== false && strpos($workflow, 'self::assign_cases($open_ids, $agent_id, $department, count($open_ids))') !== false, 'excel assignment resolves customers and assigns all open cases to ONE agent');
d593_assert_true(strpos($admin, 'function handle_deficiency_assign_upload') !== false && strpos($admin, "check_admin_referer('bcrm_deficiency_assign_upload')") !== false, 'excel assignment handler is nonce-guarded and department-cap checked');

// v0.8.62.16: excel profile assignment in the mobile-number assignments page (portal ?bcrm_view=assignments)
d593_assert_true(strpos($admin, 'تخصیص از فایل اکسل (Customer ID)') !== false && strpos($admin, 'name="assign_customers_file"') !== false, 'assignments page renders excel Customer ID upload form');
d593_assert_true(strpos($admin, 'function assign_profiles_from_customer_file') !== false && strpos($admin, 'function handle_assign_customers_excel') !== false, 'excel profile assignment service + handler exist');
d593_assert_true(strpos($admin, "check_admin_referer('bcrm_assign_customers_excel')") !== false, 'excel profile assignment handler nonce-guarded');

// v0.8.62.17: multi-sheet excel report (cases + per-action sub-status + pivot)
d593_assert_true(strpos($workflow, 'action=bcrm_deficiency_export_excel') !== false && strpos($admin, 'function handle_deficiency_export_excel') !== false, 'reports screen has the full excel export button + registered handler');
d593_assert_true(strpos($workflow, 'public static function export_excel(): void') !== false && strpos($workflow, 'json_decode((string)$a->metadata_json, true)') !== false, 'excel export turns case_actions metadata into per-stage sub-status columns');
d593_assert_true(strpos($workflow, 'function xlsx_write_multi(string $file_name, array $sheets): void') !== false && strpos($workflow, "'خلاصه'") !== false, 'multi-sheet writer emits cases + actions + pivot summary sheets');
d593_assert_true(strpos($workflow, "'وضعیت','مرحله اقدام','ریز وضعیت','تاریخ آخرین اقدام','ساعت آخرین اقدام','آخرین اقدام‌کننده'") !== false && strpos($workflow, 'GROUP BY case_id') !== false, 'cases sheet carries status + workflow-step + sub-status + last-action date/time/actor (v0.8.62.24/19)');
d593_assert_true(strpos($workflow, "'CUSTOMER_CONTACT_ATTEMPT'=>'ثبت تماس'") !== false && strpos($workflow, "\$step_of((string)\$a->action_type),") !== false, 'excel export carries the مرحله اقدام column from the workflow_step options (v0.8.62.24)');
d593_assert_true(substr_count($workflow, 'data-bcrm-jalali-date') >= 3 && strpos($workflow, "Biawin_CRM_Normalizer::date(wp_unslash(\$raw['invoice_date']") !== false, 'workflow date inputs are Jalali pickers with server-side shamsi→gregorian conversion (v0.8.62.24)');
d593_assert_true(strpos($workflow, 'bcrm-receipts-panel') !== false && strpos($workflow, 'analyze_case_receipts') !== false && strpos($workflow, 'باقیمانده تا مبلغ اعلامی') !== false, 'finance case detail shows sales-net-style receipt cards with per-receipt amounts + totals + remaining (v0.8.62.24)');
d593_assert_true(strpos($workflow, 'bcrm-finance-summary') !== false && strpos($workflow, 'data-bcrm-prefill') !== false, 'finance form opens with a totals summary and pre-fills the remaining amount (v0.8.62.24)');

echo "TOTAL_PASS=$pass\n";
echo "TOTAL_FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
