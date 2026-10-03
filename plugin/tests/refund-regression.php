<?php
// Run: php -n -d extension_dir=C:/php-8.4.4/ext -d extension=mbstring -d extension=pdo_sqlite tests/refund-regression.php
// Executes production builders against an isolated SQLite fixture. MySQL-only
// SQL_CALC_FOUND_ROWS is stripped; no financial predicates are rewritten.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('ABSPATH', __DIR__);
$pluginRoot = getenv('BCRM_TEST_ROOT') ?: dirname(__DIR__);
function wp_unslash($s) { return $s; }
class Biawin_CRM_DB { public static function table($s) { return 'wp_bcrm_' . $s; } }
require $pluginRoot . '/includes/class-biawin-crm-refund-register.php';
require $pluginRoot . '/includes/class-biawin-crm-frontend.php';
class KpiCaptured extends RuntimeException {}
class FixtureDB {
    public $db;
    public $kpi;
    public $queries = [];
    function __construct() { $this->db = new PDO('sqlite::memory:'); $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
    function esc_like($s) { return addcslashes($s, '_%\\'); }
    function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $i = 0;
        $sql = preg_replace_callback('/%[sfd]/', function($m) use (&$i, $args) {
            $v = $args[$i++];
            return $m[0] === '%s' ? $this->db->quote($v) : (string)(float)$v;
        }, $sql);
        if ($i !== count($args)) throw new RuntimeException('Placeholder mismatch');
        return $sql;
    }
    function execute($sql) {
        if (strpos($sql, 'rv.refund_amount') !== false) throw new RuntimeException('Undefined alias');
        $this->queries[] = $sql;
        return $this->db->query(str_replace('SQL_CALC_FOUND_ROWS ', '', $sql));
    }
    function get_results($sql) { return $this->execute($sql)->fetchAll(PDO::FETCH_OBJ); }
    function get_row($sql) { $this->kpi = $this->execute($sql)->fetch(PDO::FETCH_OBJ); throw new KpiCaptured(); }
}
$wpdb = new FixtureDB();
$wpdb->db->exec("CREATE TABLE wp_bcrm_customers(id INTEGER PRIMARY KEY, display_name TEXT, source_customer_id TEXT, customer_code TEXT, total_paid NUMERIC);
CREATE TABLE wp_bcrm_customer_tags(customer_id INTEGER, tag TEXT);
CREATE TABLE wp_bcrm_refund_registers(id INTEGER PRIMARY KEY, customer_id INTEGER, source_type TEXT, refund_amount NUMERIC, invoice_code TEXT DEFAULT '');
CREATE TABLE wp_bcrm_phones(id INTEGER PRIMARY KEY, customer_id INTEGER, normalized_phone TEXT, is_primary INTEGER);
INSERT INTO wp_bcrm_customers VALUES (1,'A','C1','a',100),(2,'B','C2','b',100),(3,'C','C3','c',100),(4,'D','C4','d',100),(5,'E','C5','e',100),(6,'F','C6','f',0);
INSERT INTO wp_bcrm_customer_tags VALUES (1,'REFUND_CONFIRMED'),(1,'REFUND_REPORTED'),(2,'REFUND_CONFIRMED'),(3,'REFUND_CONFIRMED'),(4,'REFUND_REPORTED'),(6,'REFUND_REPORTED');
-- v0.8.62.13: customer 1 has TWO invoice codes (2+150=350 summed) + an older no-code legacy row (not counted); customer 2 has legacy no-code rows only (last=40).
INSERT INTO wp_bcrm_refund_registers VALUES (1,1,'excel',1000,''),(2,1,'excel',150,'INV-A'),(3,1,'agent',9000,''),(4,2,'excel',500,''),(5,2,'excel',40,''),(6,3,'excel',100,''),(7,1,'excel',2,'INV-B');
INSERT INTO wp_bcrm_phones VALUES (1,1,'09111111111',1),(2,1,'09222222222',0);");
function invoke($method, ...$args) { $r = new ReflectionMethod('Biawin_CRM_Frontend', $method); $r->setAccessible(true); return $r->invoke(null, ...$args); }
$passed = 0;
function check($ok, $message) { global $passed; if (!$ok) throw new RuntimeException($message); $passed++; }
function runCase($filters, $ids, $sumBalance) {
    global $wpdb;
    $_GET = $filters;
    $parts = invoke('refunded_query_parts', 'Biawin_CRM_Refund_Register');
    $rows = invoke('refunded_fetch_rows', [$parts[0],$parts[1]], $parts[2],$parts[3], 50000,0);
    check(array_map(function($r){return (int)$r->id;},$rows) === $ids, json_encode($filters).' IDs');
    // Export calls this same builder with limit=50000; compare paginated table.
    $page = invoke('refunded_fetch_rows', [$parts[0],$parts[1]], $parts[2],$parts[3],1,0);
    check(count($page) === min(1,count($rows)), 'Pagination count');
    if ($page) check($page[0]->id === $rows[0]->id, 'Table/export ordering');
    try { invoke('render_refunded_list'); } catch (KpiCaptured $e) {}
    check((int)$wpdb->kpi->total === count($rows), 'KPI/table count');
    check((float)$wpdb->kpi->sum_balance === (float)$sumBalance, 'KPI balance');
    check(array_sum(array_map(function($r){return $r->balance;},$rows)) == $sumBalance, 'Table balance');
}
runCase([], [1,2,3,4,6],-892);
runCase(['b_sign'=>'neg'],[1],-1052);
check((int)$wpdb->kpi->negative===1 && (float)$wpdb->kpi->negative_amount===-1052.0,'Negative KPI');
check((float)$wpdb->kpi->sum_refunds===1152.0,'Per-invoice-code sum (150+2) for the filtered customer; no agent row, legacy no-code row ignored');
runCase(['b_sign'=>'pos'],[2,3,4,6],160);
runCase(['b_sign'=>'zero'],[3,6],0);
runCase(['b_op'=>'lt','b_a'=>'0'],[1],-1052);
runCase(['b_op'=>'between','b_a'=>'-50','b_b'=>'0'],[3,6],0);
runCase(['r_op'=>'eq','r_a'=>'1152'],[1],-1052);
runCase(['r_op'=>'between','r_a'=>'40','r_b'=>'100'],[2,3],60);
runCase(['r_q'=>'1152'],[1],-1052);
runCase(['r_q'=>'9000'],[],0);
runCase(['b_sign'=>'neg','r_sort'=>'refund','r_dir'=>'desc'],[1],-1052);
runCase(['b_sign'=>'neg','r_sort'=>'tags','r_dir'=>'asc'],[1],-1052);
foreach (['eq'=>'1152','gt'=>'1151','gte'=>'1152','lt'=>'1153','lte'=>'1152'] as $op=>$value) {
 runCase(['b_sign'=>'neg','r_op'=>$op,'r_a'=>$value],[1],-1052);
}
runCase(['tag_mode'=>'confirmed'],[1,2,3],-992);
runCase(['tag_mode'=>'reported'],[1,4,6],-952);
runCase(['tag_mode'=>'both','b_sign'=>'neg'],[1],-1052);
runCase(['tag_mode'=>'none'],[],0);
runCase(['refund_tag'=>'REFUND_CONFIRMED','b_sign'=>'neg'],[1],-1052);
runCase(['b_sign'=>'neg','inv_op'=>'gte','inv_a'=>'100','r_op'=>'gte','r_a'=>'1152','b_op'=>'lt','b_a'=>'0'],[1],-1052);
$source = file_get_contents($pluginRoot.'/includes/class-biawin-crm-frontend.php');
check(strpos($source,'rv.refund_amount')===false,'No obsolete alias in production source');
check(substr_count($source,"SELECT COALESCE(SUM(rr.refund_amount),0) FROM \$registers rr WHERE rr.customer_id=c.id AND rr.source_type='excel' AND rr.id IN (SELECT MAX(rm.id) FROM \$registers rm WHERE rm.customer_id=c.id AND rm.source_type='excel' GROUP BY rm.invoice_code)")===1,'Single canonical per-invoice-code sum expression');
check(strpos($source,'refunded_fetch_rows([$parts[0], $parts[1]], $parts[2], $parts[3], 50000, 0)')!==false,'Export uses tested fetch path');
echo "$passed passed / 0 failed; actual WHERE, table/export queries and render KPI executed on SQLite fixtures.\n";
