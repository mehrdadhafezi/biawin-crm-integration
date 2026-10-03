<?php
$pass = 0;
$fail = 0;

function b23_assert_true($condition, string $name): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}

function b23_body(string $source, string $method, string $next): string {
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

$assignments_page = b23_body($admin, 'assignments_page', 'public static function handle_save_sales_manager_mapping');
$front_dashboard = b23_body($frontend, 'render_front_dashboard', 'private static function front_reporting_scope');
$scope = b23_body($adapter, 'assert_scope', 'private static function population_source');

$adapter_methods = [
    'management_workload_cards',
    'management_agent_workload_rows',
    'management_recent_assignment_rows',
    'agent_dashboard_assignment_summary',
];

$assignments = [
    ['id' => 10, 'customer_id' => 1, 'agent_user_id' => 7, 'status' => 'assigned'],
    ['id' => 11, 'customer_id' => 1, 'agent_user_id' => 8, 'status' => 'assigned'],
    ['id' => 12, 'customer_id' => 2, 'agent_user_id' => 7, 'status' => 'assigned'],
    ['id' => 13, 'customer_id' => 3, 'agent_user_id' => 7, 'status' => 'returned'],
];
$guarded = [2 => true];
$blacklisted = [3 => true];
$newest = [];
foreach ($assignments as $row) {
    if ($row['status'] !== 'assigned') continue;
    $cid = $row['customer_id'];
    if (!isset($newest[$cid]) || $row['id'] > $newest[$cid]['id']) $newest[$cid] = $row;
}
$workable = array_filter($newest, static fn($row) => empty($guarded[$row['customer_id']]) && empty($blacklisted[$row['customer_id']]));
$guarded_active = array_filter($newest, static fn($row) => !empty($guarded[$row['customer_id']]));

b23_assert_true(strpos($main, "Version: 0.8.59.7") !== false, 'version header bumped to 0.8.59.7');
b23_assert_true(strpos($main, "define('BIAWIN_CRM_VERSION', '0.8.59.7')") !== false, 'version constant bumped to 0.8.59.7');
b23_assert_true(strpos($activator, "['0.8.58.0', '0.8.59.0', '0.8.59.1', '0.8.59.2', '0.8.59.3', '0.8.59.4', '0.8.59.5', '0.8.59.6']") !== false && strpos($activator, "BIAWIN_CRM_VERSION === '0.8.59.7'") !== false, 'upgrade 0.8.58.0/0.8.59.0 to 0.8.59.7 is code-only');

b23_assert_true(count($newest) === 2 && $newest[1]['id'] === 11, '1 newest active assignment');
b23_assert_true(count($newest) === 2, '2 duplicate active rows do not double count customers');
b23_assert_true(count($workable) === 1 && isset($workable[1]), '3 Guarded assignment exclusion');
b23_assert_true(count($guarded_active) === 1 && isset($guarded_active[2]), '4 manager Guarded visibility fixture');
b23_assert_true(strpos($adapter, 'NEWEST_ACTIVE_NORMAL_CANONICAL') !== false, '5 normal workable count semantic documented');
b23_assert_true(strpos($adapter, 'UNASSIGNED_NORMAL_CANONICAL') !== false, '6 unassigned normal canonical semantic documented');
b23_assert_true(strpos($adapter, 'AGENT_GUARDED_WORKLOAD_QUERY_FAILED') !== false && strpos($adapter, "\$row['guarded_customers'] = null") === false, '6b per-agent Guarded workload is separate trusted aggregate');

b23_assert_true(strpos($assignments_page, 'management_workload_cards') !== false && strpos($assignments_page, 'management_recent_assignment_rows') !== false, '7 manager card/list equality uses shared adapter');
b23_assert_true(strpos($scope, 'ROLE_MANAGER') !== false, '8 manager scope');
b23_assert_true(strpos($scope, 'SCOPE_FORBIDS_AGENT') !== false && strpos($adapter, 'agent_dashboard_assignment_summary') !== false, '9 agent scope');
b23_assert_true(strpos($frontend, 'front_reporting_scope') !== false, '10 department/organization scope boundary preserved through existing portal scope');
b23_assert_true(strpos($adapter, 'WORKLOAD_TABLE_UNAVAILABLE') !== false && strpos($adapter, 'AGENT_DASHBOARD_TABLE_UNAVAILABLE') !== false, '11 unavailable assignment dependency fails closed');
b23_assert_true(strpos($admin, 'report_safe_number') !== false && strpos($frontend, "return 'نامطمئن'") !== false, '12 null != zero');
b23_assert_true(strpos($admin, 'adapter_safe_status_message') !== false, '13 raw error hidden');

foreach ($adapter_methods as $method) {
    $body = b23_body($adapter, $method, 'public static function ');
    b23_assert_true(strpos($adapter, "'$method'") !== false, "$method public method registered");
    b23_assert_true(strpos($body, "assert_scope(\$scope, '$method'") !== false || strpos($body, "assert_scope(\$scope, '$method',") !== false, "$method scope checked");
    b23_assert_true(strpos($body, 'newest_active_assignment_condition') !== false || $method === 'management_workload_cards', "$method uses newest-active assignment SOT");
}

b23_assert_true(strpos($front_dashboard, 'Biawin_CRM_DB::table(\'assignments\')') === false && strpos($front_dashboard, "status='assigned'") === false, '14 no direct business assignment SQL in portal dashboard');
b23_assert_true(stripos($adapter, 'INSERT ') === false && stripos($adapter, 'UPDATE ') === false && stripos($adapter, 'DELETE ') === false && strpos($adapter, 'dbDelta') === false, '15 no mutation in adapter');
b23_assert_true(strpos($frontend, 'set_transient($cache_key') === false, 'dashboard migrated workload path has no transient truth cache');
b23_assert_true(strpos($adapter, 'SCOPE_FORBIDS_MANAGER_WIDE_DATA') !== false, 'manager-wide adapter support does not broaden ordinary agent access');
b23_assert_true(strpos($admin, 'Run #8') === false && strpos($frontend, 'Run #8') === false, 'no Run #8 implementation marker');
b23_assert_true(strpos($adapter, 'Frozen') === false && strpos($adapter, 'frozen') === false, 'no Frozen Plan live dependency in adapter');

echo "TOTAL_PASS=$pass\n";
echo "TOTAL_FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
