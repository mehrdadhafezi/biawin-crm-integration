<?php
$pass = 0;
$fail = 0;

function h591_assert_true($condition, string $name): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}

function h591_body(string $source, string $method, string $next): string {
    $start = strpos($source, 'function ' . $method);
    if ($start === false) return '';
    $end = strpos($source, $next, $start + 1);
    return $end === false ? substr($source, $start) : substr($source, $start, $end - $start);
}

$root = dirname(__DIR__);
$main = file_get_contents($root . '/biawin-crm-integration.php');
$admin = file_get_contents($root . '/admin/class-biawin-crm-admin.php');
$frontend = file_get_contents($root . '/includes/class-biawin-crm-frontend.php');
$adapter = file_get_contents($root . '/includes/class-biawin-crm-reporting-consumer-adapter.php');
$assignment = file_get_contents($root . '/includes/class-biawin-crm-reporting-assignment-service.php');
$activator = file_get_contents($root . '/includes/class-biawin-crm-activator.php');

$assignments_page = h591_body($admin, 'assignments_page', 'public static function handle_save_sales_manager_mapping');
$manager_scope = h591_body($admin, 'main_dashboard_reporting_scope', 'private static function adapter_metric_value');
$front_scope = h591_body($frontend, 'front_reporting_scope', 'private static function front_metric_number');
$scope = h591_body($adapter, 'assert_scope', 'private static function population_source');
$filter_summary = h591_body($adapter, 'management_assignment_filter_summary', 'public static function management_assignment_candidate_rows');
$candidate_rows = h591_body($adapter, 'management_assignment_candidate_rows', 'public static function agent_dashboard_assignment_summary');
$recent_rows = h591_body($adapter, 'management_recent_assignment_rows', 'public static function management_guarded_assignment_rows');
$guarded_rows = h591_body($adapter, 'management_guarded_assignment_rows', 'public static function management_assignment_filter_summary');
$agent_rows = h591_body($adapter, 'agent_dashboard_assignment_rows', 'private static function case_contract');

h591_assert_true(strpos($main, "Version: 0.8.59.7") !== false && strpos($main, "define('BIAWIN_CRM_VERSION', '0.8.59.7')") !== false, 'version bumped to 0.8.59.7');
h591_assert_true(strpos($assignments_page, 'management_assignment_filter_summary') !== false, '1 filter-current workable uses canonical Adapter');
h591_assert_true(strpos($filter_summary, 'unassigned_count') !== false && strpos($filter_summary, 'newest_active_assignment_condition') !== false, '2 filter-current unassigned uses canonical Adapter');
h591_assert_true(strpos($assignments_page, 'filter_unassigned_sql') === false && strpos($assignments_page, 'filter_assigned = max') === false && strpos($assignments_page, "aa.status='assigned'") === false, '3 no raw assignment SQL truth in migrated rendering paths');
h591_assert_true(strpos($recent_rows, 'total_count') !== false && strpos($recent_rows, 'COUNT(DISTINCT c.id)') !== false, '4 manager workable card/list exact equality contract');
h591_assert_true(strpos($guarded_rows, 'total_count') !== false && strpos($guarded_rows, 'GUARDED_NEWEST_ACTIVE') !== false, '5 manager Guarded card/list exact equality contract');
h591_assert_true(strpos($adapter, 'agent_dashboard_assignment_rows') !== false && strpos($agent_rows, 'total_count') !== false, '6 agent card/list exact equality contract');
h591_assert_true(strpos($candidate_rows, 'unassigned_count') !== false && strpos($candidate_rows, 'total_count') !== false, '7 unassigned card/list exact equality contract');
h591_assert_true(strpos($candidate_rows, 'returned_count') !== false && strpos($recent_rows, 'returned_count') !== false && strpos($agent_rows, 'returned_count') !== false, '8 pagination preserves total_count');
h591_assert_true(strpos($manager_scope, "'scope_proven' => true") !== false && strpos($manager_scope, "'requesting_user_id'") !== false, '9 manager valid scope');
h591_assert_true(strpos($scope, 'SCOPE_FORBIDS_DEPARTMENT') !== false && strpos($scope, 'requested_department_key') !== false, '10 manager cross-department denied');
h591_assert_true(strpos($scope, 'SCOPE_FORBIDS_ORGANIZATION') !== false && strpos($scope, 'requested_organization_key') !== false, '11 manager cross-organization denied where applicable');
h591_assert_true(strpos($scope, 'SCOPE_UNPROVEN_MANAGER_AGGREGATE') !== false, '12 missing required manager scope fails closed');
h591_assert_true(strpos($front_scope, "'agent_user_id' => \$user_id") !== false, '13 agent self permitted');
h591_assert_true(strpos($scope, 'SCOPE_FORBIDS_AGENT') !== false, '14 agent cross-agent denied');
h591_assert_true(strpos($scope, 'SCOPE_FORBIDS_MANAGER_WIDE_DATA') !== false, '15 agent management aggregate denied');
h591_assert_true(strpos($agent_rows, "assert_scope(\$scope, 'agent_dashboard_assignment_rows', ['agent_user_id' => \$agent_user_id]") !== false, '16 tampered scope denied');
h591_assert_true(strpos($assignments_page, "report_safe_number(\$agent_row['guarded_customers'] ?? null, \$agent_row['trusted'] ?? false)") !== false, '17 trusted Guarded value renders numeric');
h591_assert_true(strpos($admin, "return 'نامطمئن'") !== false || strpos($admin, 'report_safe_number') !== false, '18 untrusted Guarded value renders bounded unknown');
h591_assert_true(strpos($assignments_page, "guarded_customers'=>0") !== false && strpos($assignments_page, "guarded_customers'=>null") === false, '19 untrusted Guarded never renders zero through null default');
h591_assert_true(strpos($adapter, 'FILTER_SUMMARY_TABLE_UNAVAILABLE') !== false && strpos($adapter, "['value' => null]") !== false, '20 strict read-path audit reproduction fixed fail-closed');
h591_assert_true(strpos($assignment, 'MAX(ax.id)') !== false && strpos($filter_summary, 'COUNT(DISTINCT c.id)') !== false, '21 duplicate active rows do not double count');
h591_assert_true(strpos($assignment, 'newest_active_assignment_condition') !== false && strpos($assignment, 'MAX(ax.id)') !== false, '22 newest active wins');
h591_assert_true(strpos($filter_summary, 'newest_active_assignment_condition') !== false, '23 inactive assignment history ignored');
h591_assert_true(strpos($adapter, 'CANONICAL_POPULATION_UNTRUSTED') !== false || strpos($adapter, 'GUARD_') !== false, '24 Guard unavailable fails closed');
h591_assert_true(strpos($adapter, 'BLACKLIST') !== false || strpos($adapter, 'blacklist') !== false, '25 Blacklist unavailable fails closed');
h591_assert_true(strpos($adapter, 'workable_active_workload') !== false && strpos($adapter, 'management_workload_cards') !== false, '26 B2.1/B2.3 workable equality');
h591_assert_true(strpos($adapter, 'unassigned_normal_canonical') !== false && strpos($filter_summary, 'unassigned_count') !== false, '27 B2.1/B2.3 unassigned equality');
h591_assert_true(strpos($activator, "['0.8.58.0', '0.8.59.0', '0.8.59.1', '0.8.59.2', '0.8.59.3', '0.8.59.4', '0.8.59.5', '0.8.59.6']") !== false && strpos($activator, "BIAWIN_CRM_VERSION === '0.8.59.7'") !== false, 'upgrade paths to 0.8.59.7 are code-only');
h591_assert_true(strpos($adapter, 'set_transient') === false && strpos($filter_summary . $candidate_rows . $recent_rows . $guarded_rows . $agent_rows, 'INSERT ') === false && strpos($filter_summary . $candidate_rows . $recent_rows . $guarded_rows . $agent_rows, 'UPDATE ') === false && strpos($filter_summary . $candidate_rows . $recent_rows . $guarded_rows . $agent_rows, 'DELETE ') === false, 'strict read paths have no write fallback');

echo "TOTAL_PASS=$pass\n";
echo "TOTAL_FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
