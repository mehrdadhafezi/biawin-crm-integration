<?php
$pass = 0;
$fail = 0;

function b21_assert_true($condition, string $name): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}

function b21_method_body(string $source, string $method, string $next_marker): string {
    $start = strpos($source, 'function ' . $method);
    if ($start === false) return '';
    $end = strpos($source, $next_marker, $start);
    return $end === false ? substr($source, $start) : substr($source, $start, $end - $start);
}

$root = dirname(__DIR__);
$main = file_get_contents($root . '/biawin-crm-integration.php');
$admin = file_get_contents($root . '/admin/class-biawin-crm-admin.php');
$rest = file_get_contents($root . '/includes/class-biawin-crm-rest.php');
$frontend = file_get_contents($root . '/includes/class-biawin-crm-frontend.php');
$dashboard = b21_method_body($admin, 'dashboard', 'private static function metric');

b21_assert_true(strpos($main, "Version: 0.8.59.7") !== false, 'version header bumped to 0.8.59.7');
b21_assert_true(strpos($main, "define('BIAWIN_CRM_VERSION', '0.8.59.7')") !== false, 'version constant bumped to 0.8.59.7');
b21_assert_true($dashboard !== '', 'main dashboard method extracted');

b21_assert_true(strpos($dashboard, 'global $wpdb') === false, 'dashboard no longer opens raw wpdb');
b21_assert_true(strpos($dashboard, 'Biawin_CRM_DB::table') === false, 'dashboard no longer builds table names directly');
b21_assert_true(stripos($dashboard, 'SELECT COUNT(*)') === false, 'dashboard legacy count SQL removed');
b21_assert_true(strpos($dashboard, 'customers.total_paid') === false && strpos($dashboard, 'total_paid') === false, 'dashboard does not depend on cached customer totals');

b21_assert_true(strpos($dashboard, 'customer_population_count') !== false, 'customer KPI uses population adapter');
b21_assert_true(strpos($dashboard, 'NORMAL_CANONICAL') !== false, 'normal canonical population shown');
b21_assert_true(strpos($dashboard, 'GUARDED') !== false, 'guarded population shown separately');
b21_assert_true(strpos($dashboard, 'BLACKLISTED_LIVE') !== false, 'blacklisted live population shown separately');
b21_assert_true(strpos($dashboard, 'UNRESOLVED') !== false, 'unresolved population shown separately');

b21_assert_true(strpos($dashboard, 'management_actual_paid_total') !== false, 'financial KPI uses database-side management aggregate adapter');
b21_assert_true(strpos($dashboard, 'financial_snapshot_for_customers') === false, 'dashboard financial KPI avoids full population snapshot materialization');
b21_assert_true(strpos($dashboard, 'workable_active_workload') !== false, 'assignment active KPI uses canonical assignment adapter');
b21_assert_true(strpos($dashboard, 'unassigned_normal_canonical') !== false, 'assignment unassigned KPI uses canonical assignment adapter');

foreach (['complaints', 'deficiency_cases', 'financial_cases', 'service_cases', 'refunds'] as $case_type) {
    b21_assert_true(strpos($dashboard, "case_population_summary('$case_type'") !== false, "case KPI uses adapter for $case_type");
}

b21_assert_true(strpos($dashboard, 'adapter_metric_value') !== false, 'dashboard formats untrusted adapter metrics fail-closed');
b21_assert_true(strpos($admin, "Biawin_CRM_Reporting_Consumer_Adapter::ROLE_AGENT") !== false && strpos($admin, "'agent_user_id' => \$user_id") !== false, 'non-management dashboard scope fails closed as agent');
b21_assert_true(substr_count($dashboard, 'self::metric(') === 12, 'KPI migrated count is 12 cards');

$mutation_tokens = ['INSERT ', 'UPDATE ', 'DELETE ', 'dbDelta', 'set_transient', 'update_option'];
foreach ($mutation_tokens as $token) {
    b21_assert_true(stripos($dashboard, $token) === false, "dashboard contains no mutation token $token");
}

b21_assert_true(substr_count($rest, 'Biawin_CRM_Reporting_Consumer_Adapter') === 0, 'REST APIs not migrated in B.2.1');
b21_assert_true(strpos($frontend, 'agent_dashboard_assignment_summary') !== false, 'B.2.3 migrates frontend/agent dashboard assignment KPIs without changing B.2.1 admin dashboard inventory');
b21_assert_true(strpos($admin, 'Run #8') === false, 'no Run #8 dashboard implementation marker');

echo "TOTAL_PASS=$pass\n";
echo "TOTAL_FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
