<?php
$pass = 0;
$fail = 0;

function b22_assert_true($condition, string $name): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}

function b22_method_body(string $source, string $method, string $next_marker): string {
    $start = strpos($source, 'function ' . $method);
    if ($start === false) return '';
    $end = strpos($source, $next_marker, $start);
    return $end === false ? substr($source, $start) : substr($source, $start, $end - $start);
}

$root = dirname(__DIR__);
$main = file_get_contents($root . '/biawin-crm-integration.php');
$admin = file_get_contents($root . '/admin/class-biawin-crm-admin.php');
$adapter = file_get_contents($root . '/includes/class-biawin-crm-reporting-consumer-adapter.php');
$readme = file_get_contents($root . '/readme.txt');
$activator = file_get_contents($root . '/includes/class-biawin-crm-activator.php');

$reports_page = b22_method_body($admin, 'reports_page', 'private static function report_kpi_rows');
$preview_wrapper = b22_method_body($admin, 'customer_report_preview_rows', 'public static function settings_page');

$adapter_methods = [
    'management_report_kpi_rows',
    'management_satisfaction_summary',
    'management_contact_status_rows',
    'management_route_summary_rows',
    'management_route_detail_rows',
    'management_service_case_summary_rows',
    'management_source_service_summary_rows',
    'management_customer_preview_rows',
];

b22_assert_true(strpos($main, "Version: 0.8.59.7") !== false, 'version header bumped to 0.8.59.7');
b22_assert_true(strpos($main, "define('BIAWIN_CRM_VERSION', '0.8.59.7')") !== false, 'version constant bumped to 0.8.59.7');
b22_assert_true(strpos($readme, 'Management Reports SOT Migration') !== false, 'release name documented');
b22_assert_true(strpos($activator, "['0.8.58.0', '0.8.59.0', '0.8.59.1', '0.8.59.2', '0.8.59.3', '0.8.59.4', '0.8.59.5', '0.8.59.6']") !== false && strpos($activator, "BIAWIN_CRM_VERSION === '0.8.59.7'") !== false, 'v0.8.58.0/0.8.59.0 to v0.8.59.7 upgrade is code-only');
b22_assert_true(strpos($activator, "\$current_db_version === '0.8.57.1' && BIAWIN_CRM_VERSION === '0.8.58.0'") !== false, 'v0.8.57.1 to v0.8.58.0 upgrade is code-only');

b22_assert_true($reports_page !== '', 'management reports page extracted');
b22_assert_true(strpos($reports_page, 'global $wpdb') === false, 'reports page no longer opens wpdb directly');
b22_assert_true(strpos($reports_page, 'Biawin_CRM_DB::table') === false, 'reports page no longer builds table names directly');
b22_assert_true(stripos($reports_page, 'SELECT ') === false, 'reports page contains no direct SELECT query');
b22_assert_true(stripos($reports_page, 'INSERT ') === false && stripos($reports_page, 'UPDATE ') === false && stripos($reports_page, 'DELETE ') === false, 'reports page has no mutation SQL');
b22_assert_true(strpos($reports_page, 'set_transient') === false && strpos($reports_page, 'update_option') === false, 'reports page has no persistent cache/write operation');
b22_assert_true(strpos($reports_page, 'main_dashboard_reporting_scope') !== false, 'reports page uses management reporting scope');
b22_assert_true(strpos($reports_page, 'customer_population_count') !== false, 'reports pagination uses population adapter count');
b22_assert_true(strpos($reports_page, 'report_safe_number') !== false, 'reports page uses fail-closed numeric rendering');

b22_assert_true($preview_wrapper !== '', 'customer preview wrapper extracted');
b22_assert_true(strpos($preview_wrapper, 'global $wpdb') === false, 'customer preview wrapper has no direct wpdb');
b22_assert_true(strpos($preview_wrapper, 'management_customer_preview_rows') !== false, 'customer preview uses reporting adapter');
b22_assert_true(strpos($preview_wrapper, 'adapter_safe_status_message') !== false, 'customer preview renders safe blocked status');

foreach ($adapter_methods as $method) {
    $body = b22_method_body($adapter, $method, 'public static function ');
    b22_assert_true(strpos($adapter, "'$method'") !== false, "$method is registered as public adapter method");
    b22_assert_true(strpos($adapter, "function $method") !== false, "$method exists on adapter");
    b22_assert_true(strpos($body, "assert_scope(\$scope, '$method'") !== false, "$method enforces adapter scope");
}

foreach (['management_report_kpi_rows', 'management_route_summary_rows', 'management_route_detail_rows', 'management_customer_preview_rows'] as $method) {
    $body = b22_method_body($adapter, $method, 'public static function ');
    b22_assert_true(strpos($body, 'NORMAL_CANONICAL') !== false, "$method uses NORMAL_CANONICAL population");
    b22_assert_true(strpos($body, 'actual_paid_expression') !== false || strpos($body, 'ACTUAL_PAID_LIVE') !== false, "$method uses live invoice financial semantics");
    b22_assert_true(strpos($body, 'c.total_paid') === false && strpos($body, 'customers.total_paid') === false, "$method does not read cached customer total_paid");
}

b22_assert_true(strpos($adapter, "'management_customer_preview_rows'") !== false && strpos($adapter, "'management_source_service_summary_rows'") !== false, 'B.2.2 adapter methods are in broad-scope guard list');
b22_assert_true(strpos($adapter, 'SCOPE_FORBIDS_MANAGER_WIDE_DATA') !== false, 'agent access to management-wide methods fails closed');
b22_assert_true(strpos($adapter, 'population_materialization') !== false && strpos($adapter, 'excluded_ids_only') !== false, 'management reports avoid full customer financial snapshot materialization');
b22_assert_true(strpos($admin, 'Run #8') === false, 'no Run #8 implementation marker in admin');

echo "TOTAL_PASS=$pass\n";
echo "TOTAL_FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
