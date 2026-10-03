<?php
define('ABSPATH', __DIR__ . '/../');
define('BIAWIN_CRM_DIR', dirname(__DIR__) . '/');
define('BIAWIN_CRM_VERSION', '0.8.59.7');
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

$GLOBALS['wpdb'] = new class {
    public $prefix = 'wp_';
    public $users = 'wp_users';
    public $last_error = '';
    public $tables = [];
    public $columns = ['wp_bcrm_activities' => ['activity_type']];
    public $next_row = null;
    public $next_var = null;
    public $fail_row = false;
    public $fail_var = false;
    public function prepare($query, ...$args) { return vsprintf(str_replace(['%d','%f','%s'], ['%d','%F',"'%s'"], $query), $args); }
    public function get_var($query) {
        if (preg_match("/SHOW TABLES LIKE '([^']+)'/", (string)$query, $m)) return in_array($m[1], $this->tables, true) ? $m[1] : null;
        if (preg_match("/SHOW COLUMNS FROM ([^\\s]+) LIKE '([^']+)'/", (string)$query, $m)) return in_array($m[2], $this->columns[$m[1]] ?? [], true) ? $m[2] : null;
        if ($this->fail_var) { $this->fail_var = false; $this->last_error = 'mock query failed near production-compatible audit'; return null; }
        if ($this->next_var !== null) { $v = $this->next_var; $this->next_var = null; return $v; }
        return 0;
    }
    public function get_col($query) { return []; }
    public function get_row($query, $output = null) {
        if ($this->fail_row) { $this->fail_row = false; $this->last_error = 'mock financial query failed near declared alias'; return null; }
        if ($this->next_row !== null) { $row = $this->next_row; $this->next_row = null; return $row; }
        return null;
    }
};

require_once __DIR__ . '/../includes/class-biawin-crm-db.php';
require_once __DIR__ . '/../includes/class-biawin-crm-reporting-population-service.php';
require_once __DIR__ . '/../includes/class-biawin-crm-reporting-financial-service.php';
require_once __DIR__ . '/../includes/class-biawin-crm-reporting-assignment-service.php';
require_once __DIR__ . '/../includes/class-biawin-crm-reporting-validation-service.php';
require_once __DIR__ . '/../includes/class-biawin-crm-reporting-consumer-adapter.php';

$pass = 0;
$fail = 0;
function bcrm_assert_true($condition, string $name): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}
function bcrm_assert_same($expected, $actual, string $name): void {
    bcrm_assert_true($expected === $actual, $name);
}

function src(bool $available, string $status, array $ids = [], ?int $count = null): array {
    return Biawin_CRM_Reporting_Population_Service::source_result($available, $status, $ids, $available ? null : $status, $count);
}

$defs = Biawin_CRM_Reporting_Population_Service::definitions();
$sem = Biawin_CRM_Reporting_Financial_Service::semantics();
$actual = Biawin_CRM_Reporting_Financial_Service::actual_paid_expression('i');
$assign = Biawin_CRM_Reporting_Assignment_Service::newest_active_assignment_condition('a');
$validation = file_get_contents(__DIR__ . '/../includes/class-biawin-crm-reporting-validation-service.php');
$population = file_get_contents(__DIR__ . '/../includes/class-biawin-crm-reporting-population-service.php');
$financial = file_get_contents(__DIR__ . '/../includes/class-biawin-crm-reporting-financial-service.php');
$assignment = file_get_contents(__DIR__ . '/../includes/class-biawin-crm-reporting-assignment-service.php');
$admin = file_get_contents(__DIR__ . '/../admin/class-biawin-crm-admin.php');
$activator = file_get_contents(__DIR__ . '/../includes/class-biawin-crm-activator.php');

bcrm_assert_true(isset($defs['NORMAL_CANONICAL']), 'normal canonical customer');
bcrm_assert_true(isset($defs['GUARDED']), 'Guarded customer');
bcrm_assert_true(isset($defs['BLACKLISTED_LIVE']), 'blacklisted live customer');
bcrm_assert_true(strpos($defs['NORMAL_CANONICAL']['guard_policy'], 'excluded') !== false, 'Guarded + blacklisted customer excluded from normal');
bcrm_assert_true(isset($defs['ALL_LIVE']) && isset($defs['NORMAL_CANONICAL']), 'all-live vs normal-canonical count semantics');
bcrm_assert_true(strpos($defs['NORMAL_CANONICAL']['canonical_identity_assumption'], 'phone') === false, 'migrated source/target identity does not infer from phone');

$all = src(true, 'AVAILABLE', [], 10);
$guard_nonempty = src(true, 'AVAILABLE', [2, 3]);
$guard_zero = src(true, 'AVAILABLE', []);
$guard_class_missing = src(false, 'CLASS_UNAVAILABLE');
$guard_table_missing = src(false, 'TABLE_UNAVAILABLE');
$guard_query_failed = src(false, 'QUERY_FAILED');
$black_nonempty = src(true, 'AVAILABLE', [3, 4]);
$black_zero_unexpected = src(false, 'EMPTY_UNEXPECTED');
$black_missing = src(false, 'MISSING');
$black_unreadable = src(false, 'UNREADABLE');
$black_load_failed = src(false, 'LOAD_FAILED');

$healthy = Biawin_CRM_Reporting_Population_Service::canonical_count_result($all, $guard_nonempty, $black_nonempty);
bcrm_assert_same(true, $healthy['trusted'], 'both dependencies healthy');
bcrm_assert_same(7, $healthy['count'], 'NORMAL_CANONICAL healthy numeric result');
bcrm_assert_same(3, $healthy['excluded_union_count'], 'union subtraction avoids double exclusion');
bcrm_assert_same([], $healthy['dependency_errors'], 'healthy canonical has no dependency errors');

$valid_empty_guard = Biawin_CRM_Reporting_Population_Service::canonical_count_result($all, $guard_zero, $black_nonempty);
bcrm_assert_same(true, $guard_zero['available'], 'Guard available + valid zero');
bcrm_assert_same(0, $guard_zero['count'], 'valid empty Guard count zero');
bcrm_assert_same(true, $valid_empty_guard['trusted'], 'valid empty Guard != unavailable Guard');
bcrm_assert_same(8, $valid_empty_guard['count'], 'valid empty Guard still computes canonical');

foreach ([
    'Guard class unavailable' => $guard_class_missing,
    'Guard table unavailable' => $guard_table_missing,
    'Guard query failure' => $guard_query_failed,
] as $name => $guard_source) {
    $blocked = Biawin_CRM_Reporting_Population_Service::canonical_count_result($all, $guard_source, $black_nonempty);
    bcrm_assert_same(false, $blocked['trusted'], $name . ' blocks trusted canonical');
    bcrm_assert_same(null, $blocked['count'], $name . ' count is not trusted zero');
}

foreach ([
    'Blacklist path missing' => $black_missing,
    'Blacklist file unreadable' => $black_unreadable,
    'Blacklist parse/load failure' => $black_load_failed,
    'Blacklist unexpectedly empty' => $black_zero_unexpected,
] as $name => $black_source) {
    $blocked = Biawin_CRM_Reporting_Population_Service::canonical_count_result($all, $guard_nonempty, $black_source);
    bcrm_assert_same(false, $blocked['trusted'], $name . ' blocks trusted canonical');
    bcrm_assert_same(null, $blocked['count'], $name . ' count is not trusted zero');
}

$both_unavailable = Biawin_CRM_Reporting_Population_Service::canonical_count_result($all, $guard_query_failed, $black_load_failed);
bcrm_assert_same(false, $both_unavailable['trusted'], 'both unavailable blocks trusted canonical');
bcrm_assert_true(in_array('GUARD_QUERY_FAILED', $both_unavailable['dependency_errors'], true), 'Guard failure is explicitly named');
bcrm_assert_true(in_array('BLACKLIST_LOAD_FAILED', $both_unavailable['dependency_errors'], true), 'Blacklist failure is explicitly named');

$parsed = Biawin_CRM_Reporting_Population_Service::parse_blacklist_lines([' abc ', '', 'ABC', 'def']);
bcrm_assert_same(2, count($parsed), 'Blacklist available + non-empty parse dedupes IDs');
bcrm_assert_true(isset($parsed['ABC']) && isset($parsed['DEF']), 'Blacklist parser normalizes IDs');
bcrm_assert_same(0, count(Biawin_CRM_Reporting_Population_Service::parse_blacklist_lines([])), 'empty loaded blacklist is distinguishable before trust policy');

$divergence_source = Biawin_CRM_Reporting_Population_Service::summary();
bcrm_assert_true(strpos($population, 'guarded_not_blacklisted') !== false, 'guarded_not_blacklisted metric exists');
bcrm_assert_true(strpos($population, 'blacklisted_not_guarded') !== false, 'blacklisted_not_guarded metric exists');
bcrm_assert_true(strpos($population, 'array_diff_key') !== false, 'Guard/blacklist divergence uses set comparison');
bcrm_assert_true(strpos($population, 'excluded_union_count') !== false, 'normal invariant exposes exclusion union');
bcrm_assert_true(strpos($population, 'NORMAL_PLUS_VISIBLE_QUARANTINE') !== false && strpos($population, 'BLOCKED') !== false, 'NORMAL_PLUS_VISIBLE_QUARANTINE propagates unsafe state');
bcrm_assert_true(strpos($population, 'UNRESOLVED') !== false && strpos($population, 'unavailable Guard remains unavailable') !== false, 'UNRESOLVED propagates unsafe dependency state');
bcrm_assert_true(strpos($population, 'EMPTY_UNEXPECTED') !== false, 'valid empty blacklist policy behaves conservatively');
bcrm_assert_true(strpos($population, 'unknown_is_not_zero') !== false, 'UNKNOWN is not ZERO contract is documented in service');

bcrm_assert_true(strpos($actual, 'amount_paid') !== false, 'customer with one invoice');
bcrm_assert_true(strpos($actual, 'SUM') !== false, 'customer with multiple invoices');
bcrm_assert_true(strpos($actual, 'exclude_from_total') !== false, 'excluded invoice');
bcrm_assert_true(strpos($actual, 'COALESCE') !== false, 'customer with no invoice');
bcrm_assert_true(isset($sem['DECLARED_PAID_LATEST_CUSTOMER_CLAIM']), 'latest declared payment interaction');
bcrm_assert_true(strpos($financial, ' decl ') !== false || strpos($financial, ') decl ON') !== false, 'MariaDB-safe financial query construction');
bcrm_assert_true(strpos($financial, ' dec ') === false && strpos($financial, ') dec ON') === false, 'financial query avoids dec alias');
bcrm_assert_true(strpos($financial, 'SUM(CASE WHEN') !== false, 'financial query uses MariaDB-compatible CASE aggregates');

$GLOBALS['wpdb']->tables = ['wp_bcrm_customers', 'wp_bcrm_invoices', 'wp_bcrm_interactions'];
$GLOBALS['wpdb']->next_row = [
    'customers_checked' => 0,
    'total_paid_matched' => 0,
    'total_paid_mismatched' => 0,
    'declared_paid_matched' => 0,
    'declared_paid_mismatched' => 0,
    'discrepancy_matched' => 0,
    'discrepancy_mismatched' => 0,
];
$financial_zero = Biawin_CRM_Reporting_Financial_Service::freshness_summary();
bcrm_assert_same(true, $financial_zero['trusted'], 'financial query success');
bcrm_assert_same(0, $financial_zero['customers_checked'], 'legitimate zero-customer result remains trusted zero');
$GLOBALS['wpdb']->tables = ['wp_bcrm_customers', 'wp_bcrm_invoices', 'wp_bcrm_interactions'];
$GLOBALS['wpdb']->fail_row = true;
$financial_failed = Biawin_CRM_Reporting_Financial_Service::freshness_summary();
bcrm_assert_same(false, $financial_failed['trusted'], 'financial query failure');
bcrm_assert_same('QUERY_FAILED', $financial_failed['status'], 'financial query failure status');
bcrm_assert_same(null, $financial_failed['customers_checked'], 'failed financial query returns null not zero');
bcrm_assert_true(strpos($financial_failed['error'], 'mock financial query failed') !== false, 'raw DB error is captured as diagnostic not successful validation');

bcrm_assert_true(strpos($assign, "status='assigned'") !== false, 'one active assignment');
bcrm_assert_true(strpos($assignment, 'duplicate_active_assignment_customers') !== false, 'duplicate active assignments');
bcrm_assert_true(strpos($assign, 'MAX(ax.id)') !== false, 'newest active assignment selection');
bcrm_assert_true(strpos($assignment, 'unassigned_all_live_customers') !== false, 'unassigned all-live');
bcrm_assert_true(strpos($assignment, 'unassigned_normal_canonical_customers') !== false, 'unassigned NORMAL_CANONICAL helper');

$blocked_assignment_population = [
    'counts' => [
        'NORMAL_CANONICAL' => [
            'trusted' => false,
            'dependency_errors' => ['GUARD_CLASS_UNAVAILABLE'],
        ],
    ],
];
$blocked_assignment = Biawin_CRM_Reporting_Assignment_Service::unassigned_normal_canonical_customers($blocked_assignment_population);
bcrm_assert_same(false, $blocked_assignment['trusted'], 'unassigned NORMAL_CANONICAL blocked when canonical population blocked');
bcrm_assert_same(null, $blocked_assignment['count'], 'blocked unassigned NORMAL_CANONICAL is not rendered as zero');
bcrm_assert_true(strpos($assignment, 'guarded_active_workload') !== false, 'Guarded active workload');
bcrm_assert_true(strpos($assignment, 'workable_active_workload') !== false, 'workable active workload');

bcrm_assert_true(strpos($validation, 'overall_reporting_sot_status') !== false, 'validation overall status HEALTHY/BLOCKED exists');
bcrm_assert_true(strpos($validation, 'core_source_of_truth_status') !== false, 'core SOT health semantics');
bcrm_assert_true(strpos($validation, 'validation_execution_status') !== false, 'validation execution health semantics');
bcrm_assert_true(strpos($validation, 'guard_source_status') !== false, 'validation exposes Guard source status');
bcrm_assert_true(strpos($validation, 'blacklist_source_status') !== false, 'validation exposes Blacklist source status');
bcrm_assert_true(strpos($validation, 'canonical_population_status') !== false, 'validation exposes canonical population status');
bcrm_assert_true(strpos($validation, 'reset_request_memo') !== false, 'request-level population memoization');
bcrm_assert_true(strpos($validation, "'complaints' => 'complaints'") !== false, 'complaint valid customer');
bcrm_assert_true(strpos($validation, 'orphan_refs') !== false, 'complaint orphan');
bcrm_assert_true(strpos($validation, 'deficiency_cases') !== false, 'deficiency orphan');
bcrm_assert_true(strpos($validation, 'deficiency_orphan_diagnostics') !== false, 'deficiency orphan diagnostic helper');
bcrm_assert_true(strpos($validation, 'financial_cases') !== false, 'financial case orphan');
bcrm_assert_true(strpos($validation, 'service_cases') !== false, 'service case orphan');
bcrm_assert_true(strpos($validation, 'refunds') !== false, 'refund orphan');
bcrm_assert_true(strpos($validation, "mode LIKE '%run8%'") !== false, 'Run #8 absent/non-executable query excludes release-version rows');
bcrm_assert_true(strpos($validation, 'run_7_terminal') !== false, 'Run #7 remains terminal metadata check');
bcrm_assert_true(strpos($validation, "'run_8_implemented_or_executable' => 'NO'") !== false, 'Run #8 absent/non-executable');
bcrm_assert_true(strpos($validation, "'frozen_plan_business_dependency' => 'NO'") !== false, 'Frozen Plan disagreement does not change live canonical population');
bcrm_assert_true(strpos($validation, 'activity_type') !== false, 'activities schema with correct event column');
bcrm_assert_true(strpos($validation, 'COLUMN_UNAVAILABLE') !== false, 'activities expected column absent');
bcrm_assert_same('activity_type', Biawin_CRM_Reporting_Validation_Service::activity_event_column('wp_bcrm_activities'), 'activities schema resolver selects activity_type');
$GLOBALS['wpdb']->columns = ['wp_bcrm_activities' => []];
bcrm_assert_same(null, Biawin_CRM_Reporting_Validation_Service::activity_event_column('wp_bcrm_activities'), 'activities expected column absent returns null');
$GLOBALS['wpdb']->columns = ['wp_bcrm_activities' => ['activity_type']];
$GLOBALS['wpdb']->tables = ['wp_bcrm_customers'];
$GLOBALS['wpdb']->last_error = 'stale error from a previous validation query';
bcrm_assert_same(true, Biawin_CRM_Reporting_Population_Service::table_exists('wp_bcrm_customers'), 'table existence checks clear stale database errors');
$GLOBALS['wpdb']->tables = ['wp_bcrm_activities', 'wp_bcrm_audit_log'];
$GLOBALS['wpdb']->next_var = 0;
$event_zero = Biawin_CRM_Reporting_Validation_Service::event_double_count_risk();
bcrm_assert_same(true, $event_zero['trusted'], 'event-risk query success returning 0');
bcrm_assert_same(0, $event_zero['potential_duplicate_assignment_return_events'], 'event-risk zero remains trusted zero');
$GLOBALS['wpdb']->tables = ['wp_bcrm_activities', 'wp_bcrm_audit_log'];
$GLOBALS['wpdb']->next_var = 3;
$event_three = Biawin_CRM_Reporting_Validation_Service::event_double_count_risk();
bcrm_assert_same(3, $event_three['potential_duplicate_assignment_return_events'], 'event-risk query success returning >0');
$GLOBALS['wpdb']->tables = ['wp_bcrm_activities', 'wp_bcrm_audit_log'];
$GLOBALS['wpdb']->fail_var = true;
$event_failed = Biawin_CRM_Reporting_Validation_Service::event_double_count_risk();
bcrm_assert_same(false, $event_failed['trusted'], 'event-risk query failure returns untrusted');
bcrm_assert_same(null, $event_failed['potential_duplicate_assignment_return_events'], 'event-risk query failure returns null');
bcrm_assert_same('DEGRADED', Biawin_CRM_Reporting_Validation_Service::validation_status('HEALTHY', $financial_failed, $event_zero), 'core healthy + financial failure => validation DEGRADED');
bcrm_assert_same('DEGRADED', Biawin_CRM_Reporting_Validation_Service::validation_status('HEALTHY', $financial_zero, $event_failed), 'core healthy + event failure => validation DEGRADED');
bcrm_assert_same('HEALTHY', Biawin_CRM_Reporting_Validation_Service::validation_status('HEALTHY', $financial_zero, $event_zero), 'core healthy + all validation healthy => validation HEALTHY');
bcrm_assert_same('BLOCKED', Biawin_CRM_Reporting_Validation_Service::validation_status('BLOCKED', $financial_zero, $event_zero), 'core blocked => validation BLOCKED');

bcrm_assert_true(strpos($admin, 'CORE SOURCE-OF-TRUTH: ') !== false, 'core health validation page banner');
bcrm_assert_true(strpos($admin, 'VALIDATION EXECUTION: ') !== false, 'validation execution page banner');
bcrm_assert_true(strpos($admin, "'UNAVAILABLE'") !== false || strpos($admin, 'UNAVAILABLE') !== false, 'NULL is not rendered/interpreted as zero for blocked metric');
bcrm_assert_true(strpos(file_get_contents(__DIR__ . '/../biawin-crm-integration.php'), '0.8.59.7') !== false, 'plugin release version updated');

bcrm_assert_true(strpos($activator, "['0.8.58.0', '0.8.59.0', '0.8.59.1', '0.8.59.2', '0.8.59.3', '0.8.59.4', '0.8.59.5', '0.8.59.6']") !== false && strpos($activator, "BIAWIN_CRM_VERSION === '0.8.59.7'") !== false, 'direct 0.8.58.0/0.8.59.0 to 0.8.59.7 code-only upgrade supported');
bcrm_assert_true(strpos($activator, "\$current_db_version === '0.8.57.1' && BIAWIN_CRM_VERSION === '0.8.58.0'") !== false, 'direct 0.8.57.1 to 0.8.58.0 code-only upgrade supported');
bcrm_assert_true(strpos($activator, "['0.8.55.2', '0.8.56.0', '0.8.56.1', '0.8.57.0']") !== false && strpos($activator, "BIAWIN_CRM_VERSION === '0.8.57.1'") !== false, 'direct 0.8.55.2/0.8.56.0/0.8.56.1/0.8.57.0 to 0.8.57.1 code-only upgrade supported');
bcrm_assert_true(strpos($activator, "BIAWIN_CRM_VERSION === '0.8.57.0'") !== false, 'activator v0.8.57.0 exact safe gate preserved');
bcrm_assert_true(strpos($activator, "BIAWIN_CRM_VERSION === '0.8.56.1'") !== false, 'activator v0.8.56.1 exact safe gate preserved');

echo "TOTAL_PASS=$pass\n";
echo "TOTAL_FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
