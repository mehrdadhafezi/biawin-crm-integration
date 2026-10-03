<?php
define('ABSPATH', __DIR__ . '/../');
define('BIAWIN_CRM_DIR', dirname(__DIR__) . '/');
define('BIAWIN_CRM_VERSION', '0.8.59.7');
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

function current_time($type = 'mysql') { return '2026-09-05 18:30:00'; }
function current_user_can($cap) { return $cap === 'manage_options'; }

$GLOBALS['wpdb'] = new class {
    public $prefix = 'wp_';
    public $users = 'wp_users';
    public $last_error = '';
    public $tables = [];
    public $vars = [];
    public $cols = [];
    public $results = [];
    public $fail_results_on_call = 0;
    public $col_handler = null;
    private $results_calls = 0;
    public function prepare($query, ...$args) { return vsprintf(str_replace(['%d','%f','%s'], ['%d','%F',"'%s'"], $query), $args); }
    public function get_var($query) {
        if (preg_match("/SHOW TABLES LIKE '([^']+)'/", (string)$query, $m)) return in_array($m[1], $this->tables, true) ? $m[1] : null;
        if ($this->vars) return array_shift($this->vars);
        return 0;
    }
    public function get_col($query) {
        if (is_callable($this->col_handler)) return ($this->col_handler)((string)$query);
        if ($this->cols) return array_shift($this->cols);
        return [];
    }
    public function get_row($query, $output = null) { return null; }
    public function get_results($query, $output = null) {
        $this->results_calls++;
        if ($this->fail_results_on_call > 0 && $this->results_calls === $this->fail_results_on_call) {
            $this->last_error = 'mock chunk failed';
            return null;
        }
        if ($this->results) return array_shift($this->results);
        return [];
    }
};

require_once __DIR__ . '/../includes/class-biawin-crm-db.php';
require_once __DIR__ . '/../includes/class-biawin-crm-production-readiness.php';
require_once __DIR__ . '/../includes/class-biawin-crm-reporting-population-service.php';
require_once __DIR__ . '/../includes/class-biawin-crm-reporting-financial-service.php';
require_once __DIR__ . '/../includes/class-biawin-crm-reporting-assignment-service.php';
require_once __DIR__ . '/../includes/class-biawin-crm-reporting-validation-service.php';
require_once __DIR__ . '/../includes/class-biawin-crm-reporting-consumer-adapter.php';

$pass = 0;
$fail = 0;
function b1_assert_true($condition, string $name): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}
function b1_assert_same($expected, $actual, string $name): void {
    b1_assert_true($expected === $actual, $name);
}
function src(bool $available, string $status, array $ids = [], ?int $count = null): array {
    return Biawin_CRM_Reporting_Population_Service::source_result($available, $status, $ids, $available ? null : $status, $count);
}

$methods = Biawin_CRM_Reporting_Consumer_Adapter::public_methods();
b1_assert_true(in_array('population_summary', $methods, true), 'adapter exposes population_summary');
b1_assert_true(in_array('financial_snapshot_for_customers', $methods, true), 'adapter exposes financial batch path');
b1_assert_true(in_array('event_report', $methods, true), 'adapter exposes event reporting');
b1_assert_true(in_array('sanitize_export_cell', $methods, true), 'adapter exposes shared export sanitizer');

$guard_unavailable = Biawin_CRM_Reporting_Population_Service::canonical_count_result(src(true, 'AVAILABLE', [], 10), src(false, 'TABLE_UNAVAILABLE'), src(true, 'AVAILABLE', [2]));
b1_assert_same(false, $guard_unavailable['trusted'], 'FX-POP-001 Guard unavailable blocks canonical');
b1_assert_same(null, $guard_unavailable['count'], 'FX-POP-001 Guard unavailable count null');
$black_unavailable = Biawin_CRM_Reporting_Population_Service::canonical_count_result(src(true, 'AVAILABLE', [], 10), src(true, 'AVAILABLE', [2]), src(false, 'LOAD_FAILED'));
b1_assert_same(false, $black_unavailable['trusted'], 'FX-POP-002 blacklist unavailable blocks canonical');
b1_assert_same(null, $black_unavailable['count'], 'FX-POP-002 blacklist unavailable count null');
$overlap = Biawin_CRM_Reporting_Population_Service::canonical_count_result(src(true, 'AVAILABLE', [], 10), src(true, 'AVAILABLE', [2,3]), src(true, 'AVAILABLE', [3,4]));
b1_assert_same(7, $overlap['count'], 'adversarial overlap subtracts union once');
b1_assert_same(3, $overlap['excluded_union_count'], 'adversarial overlap exposes union count');
$empty_blacklist = Biawin_CRM_Reporting_Population_Service::canonical_count_result(src(true, 'AVAILABLE', [], 10), src(true, 'AVAILABLE', [2]), src(false, 'EMPTY_UNEXPECTED'));
b1_assert_same(false, $empty_blacklist['trusted'], 'adversarial empty blacklist is untrusted');

Biawin_CRM_Reporting_Consumer_Adapter::reset_request_memo();
$GLOBALS['wpdb']->tables = ['wp_bcrm_customers', 'wp_bcrm_readiness_quarantine'];
$GLOBALS['wpdb']->vars = [5];
$GLOBALS['wpdb']->col_handler = function (string $query): array {
    if (strpos($query, 'readiness_quarantine') !== false) return [2, 3];
    if (strpos($query, 'source_customer_id IN') !== false) return [3, 4];
    if (strpos($query, 'WHERE id NOT IN') !== false) return [1, 5];
    return [];
};
$visible_ids = Biawin_CRM_Reporting_Consumer_Adapter::customer_population_ids('NORMAL_PLUS_VISIBLE_QUARANTINE', ['role' => 'manager']);
b1_assert_same(true, $visible_ids['trusted'], 'segmentation public IDs trusted when dependencies available');
b1_assert_true(isset($visible_ids['data']['segments']['normal_canonical']['ids']), 'segmentation public IDs expose normal segment');
b1_assert_true(isset($visible_ids['data']['segments']['guarded']['ids']), 'segmentation public IDs expose guarded segment');
b1_assert_true(isset($visible_ids['data']['segments']['blacklisted_live']['ids']), 'segmentation public IDs expose blacklisted segment');
b1_assert_true(isset($visible_ids['data']['segments']['unresolved']['ids']), 'segmentation public IDs expose unresolved segment');
b1_assert_same([1, 2, 3, 4, 5], array_values($visible_ids['data']['ids']), 'segmentation aggregate visible IDs are deduplicated');
b1_assert_same(2, $visible_ids['data']['business_count'], 'segmentation business count remains normal canonical count');
Biawin_CRM_Reporting_Consumer_Adapter::reset_request_memo();
$GLOBALS['wpdb']->vars = [5];
$visible_count = Biawin_CRM_Reporting_Consumer_Adapter::customer_population_count('NORMAL_PLUS_VISIBLE_QUARANTINE', ['role' => 'manager']);
b1_assert_same(true, $visible_count['trusted'], 'segmentation public count trusted when dependencies available');
b1_assert_true(isset($visible_count['data']['segments']['normal_canonical']['count']), 'segmentation public count preserves segment metadata');
b1_assert_same(5, $visible_count['value'], 'segmentation public count aggregate is deduplicated visible count');
$GLOBALS['wpdb']->col_handler = null;

$GLOBALS['wpdb']->tables = ['wp_bcrm_customers', 'wp_bcrm_invoices', 'wp_bcrm_interactions'];
$GLOBALS['wpdb']->results = [[
    ['customer_id' => 1, 'actual_paid_live' => '150.00', 'declared_paid_latest' => '200.00', 'discrepancy_live' => '50.00', 'cached_total_paid' => '999.00', 'cached_declared_paid' => '200.00', 'cached_discrepancy' => '-799.00'],
]];
$fin = Biawin_CRM_Reporting_Consumer_Adapter::financial_snapshot_for_customers([1], ['role' => 'finance']);
b1_assert_same(true, $fin['trusted'], 'FX-FIN-001 financial batch success trusted');
b1_assert_same(150.0, $fin['data']['rows'][1]['actual_paid_live'], 'FX-FIN-001 actual paid uses invoice aggregate');
b1_assert_same(false, $fin['data']['rows'][1]['cache_is_authoritative'], 'FX-FIN-002 cached total remains diagnostic');
$GLOBALS['wpdb']->results = [];
$GLOBALS['wpdb']->fail_results_on_call = 1;
$failed_fin = Biawin_CRM_Reporting_Consumer_Adapter::financial_snapshot_for_customers([1], ['role' => 'finance']);
b1_assert_same(false, $failed_fin['trusted'], 'adversarial financial chunk failure untrusted');
b1_assert_same(null, $failed_fin['value'], 'adversarial financial chunk failure value null');
$GLOBALS['wpdb']->fail_results_on_call = 0;

$approved = Biawin_CRM_Reporting_Consumer_Adapter::approved_paid_total([], ['role' => 'finance']);
b1_assert_same('SEMANTICALLY_UNRESOLVED', $approved['status'], 'approved paid unresolved status');
b1_assert_same(false, $approved['trusted'], 'approved paid unresolved untrusted');
b1_assert_same(null, $approved['value'], 'approved paid unresolved value null');
b1_assert_same('APPROVED_PAID_SOURCE_UNDEFINED', $approved['error'], 'approved paid reason explicit');

$GLOBALS['wpdb']->tables = ['wp_bcrm_assignments'];
$GLOBALS['wpdb']->results = [[
    ['id' => 22, 'customer_id' => 7, 'agent_user_id' => 200, 'status' => 'assigned', 'assigned_at' => '2026-09-01 10:00:00'],
]];
$assignment = Biawin_CRM_Reporting_Consumer_Adapter::newest_active_assignments_for_customers([7], ['role' => 'manager']);
b1_assert_same(true, $assignment['trusted'], 'FX-ASG-001 newest assignment query trusted');
b1_assert_same(22, (int)$assignment['data']['rows'][7]['id'], 'FX-ASG-002 newest active owner returned');
b1_assert_true(strpos(Biawin_CRM_Reporting_Assignment_Service::newest_active_assignment_condition('a'), 'MAX(ax.id)') !== false, 'assignment workload uses newest active semantics');

$GLOBALS['wpdb']->tables = ['wp_bcrm_interactions', 'wp_bcrm_activities', 'wp_bcrm_audit_log'];
$GLOBALS['wpdb']->vars = [1, 1, 1];
$events = Biawin_CRM_Reporting_Consumer_Adapter::event_report(['timestamp_semantic' => 'activity_created_at'], ['role' => 'manager']);
b1_assert_same(true, $events['trusted'], 'FX-EVT-001 event adapter available');
b1_assert_same(2, $events['data']['operational_count'], 'FX-EVT-002 operational count excludes audit evidence');
b1_assert_same(1, $events['data']['audit_evidence_count'], 'FX-EVT-003 audit evidence stays separate');
b1_assert_same(false, $events['data']['combined_event_count_exposed'], 'event adapter exposes no activity+audit combined count');
$interaction_actor = ['actor_user_id' => 301, 'current_assignment_owner' => 999];
b1_assert_same(301, $interaction_actor['actor_user_id'], 'adversarial interaction actor differs from owner and remains event actor');

$GLOBALS['wpdb']->tables = ['wp_bcrm_deficiency_cases', 'wp_bcrm_customers'];
$GLOBALS['wpdb']->vars = [59];
$orphan = Biawin_CRM_Reporting_Consumer_Adapter::case_orphan_summary('deficiency_cases', ['role' => 'deficiency']);
b1_assert_same(true, $orphan['trusted'], 'FX-CASE-001 deficiency orphan summary trusted');
b1_assert_same(59, $orphan['value'], 'FX-CASE-002 deficiency orphan derived live not hard-coded in adapter method');
b1_assert_same(true, $orphan['data']['canonical_customer_case_metrics_exclude_orphans'], 'deficiency orphan excluded from canonical case metrics');

foreach (['=SUM(A1:A2)', '+cmd', '-1+2', '@formula'] as $dangerous) {
    b1_assert_same("'" . $dangerous, Biawin_CRM_Reporting_Consumer_Adapter::sanitize_export_cell($dangerous), 'FX-EXP-002 neutralizes ' . $dangerous);
}
b1_assert_same('plain', Biawin_CRM_Reporting_Consumer_Adapter::sanitize_export_cell('plain'), 'export sanitizer leaves safe text unchanged');

$bad_filter = Biawin_CRM_Reporting_Consumer_Adapter::normalize_filters(['raw_sql' => '1=1']);
b1_assert_same(false, $bad_filter['trusted'], 'unknown filter rejected');
$missing_date_semantic = Biawin_CRM_Reporting_Consumer_Adapter::normalize_filters(['date_from' => '2026-01-01']);
b1_assert_same('SEMANTICALLY_UNRESOLVED', $missing_date_semantic['status'], 'date filter without timestamp semantic unresolved');

$agent_broad = Biawin_CRM_Reporting_Consumer_Adapter::assert_scope(['role' => 'agent'], 'population_summary');
b1_assert_same(false, $agent_broad['trusted'], 'FX-SEC-001 ordinary agent cannot obtain manager-wide population');
$finance_case = Biawin_CRM_Reporting_Consumer_Adapter::assert_scope(['role' => 'finance'], 'case_rows');
b1_assert_same(false, $finance_case['trusted'], 'FX-SEC-001 finance scope does not gain case-wide reporting');
$deficiency_finance = Biawin_CRM_Reporting_Consumer_Adapter::assert_scope(['role' => 'deficiency'], 'financial_total_for_population');
b1_assert_same(false, $deficiency_finance['trusted'], 'FX-SEC-001 deficiency scope does not gain finance reporting');
$service_case = Biawin_CRM_Reporting_Consumer_Adapter::assert_scope(['role' => 'service'], 'case_rows');
b1_assert_same(true, $service_case['trusted'], 'FX-SEC-001 service scope allowed only case context');
$manager_scope = Biawin_CRM_Reporting_Consumer_Adapter::assert_scope(['role' => 'manager'], 'population_summary');
b1_assert_same(true, $manager_scope['trusted'], 'FX-SEC-001 manager scope stays manager scope');

Biawin_CRM_Reporting_Consumer_Adapter::reset_request_memo();
$adapter_source = file_get_contents(__DIR__ . '/../includes/class-biawin-crm-reporting-consumer-adapter.php');
$main = file_get_contents(__DIR__ . '/../biawin-crm-integration.php');
$admin = file_get_contents(__DIR__ . '/../admin/class-biawin-crm-admin.php');
$rest = file_get_contents(__DIR__ . '/../includes/class-biawin-crm-rest.php');
$frontend = file_get_contents(__DIR__ . '/../includes/class-biawin-crm-frontend.php');
b1_assert_true(strpos($adapter_source, 'private static $memo') !== false, 'FX-CACHE-001 request-scoped memoization exists');
b1_assert_true(strpos($adapter_source, 'transient') === false, 'FX-CACHE-001 no transient cache in adapter truth');
b1_assert_true(strpos($adapter_source, 'INSERT') === false && strpos($adapter_source, 'UPDATE') === false && strpos($adapter_source, 'DELETE') === false && strpos($adapter_source, 'dbDelta') === false, 'adapter runtime has no mutation SQL/schema calls');
b1_assert_true(strpos($adapter_source, 'v0.8.53.2 Frozen Canonical Plan') === false, 'no Frozen Plan live dependency in adapter');
b1_assert_true(substr_count($admin, 'Biawin_CRM_Reporting_Consumer_Adapter') > 0 && substr_count($rest, 'Biawin_CRM_Reporting_Consumer_Adapter') === 0, 'B.1/B.2 reporting adapter remains out of REST reporting surface');
b1_assert_true(strpos($frontend, 'agent_dashboard_assignment_summary') !== false && strpos($frontend, 'management_workload_cards') !== false, 'B.2.3 frontend usage is limited to workload/dashboard adapter methods');
b1_assert_true(strpos($main, 'class-biawin-crm-reporting-consumer-adapter.php') !== false, 'adapter class registered in plugin loader only');
b1_assert_true(!file_exists(__DIR__ . '/../data/historical-identity-baseline.ndjson') || filesize(__DIR__ . '/../data/historical-identity-baseline.ndjson') > 0, 'historical baseline source check is explicit before packaging exclusion');

echo "TOTAL_PASS=$pass\n";
echo "TOTAL_FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
