<?php
define('ABSPATH', __DIR__ . '/../');
define('BIAWIN_CRM_DIR', dirname(__DIR__) . '/');
define('BIAWIN_CRM_VERSION', '0.8.59.7');
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

$pass = 0;
$fail = 0;

function hardening_assert_true($condition, string $name): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}

function current_user_can($cap) { return !empty($GLOBALS['bcrm_caps'][$cap]); }
function esc_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function number_format_i18n($number, $decimals = 0) { return number_format((float)$number, (int)$decimals, '.', ','); }

$GLOBALS['wpdb'] = new class {
    public $prefix = 'wp_';
    public $users = 'wp_users';
    public $last_error = '';
    public $queries = [];
    public $fail_financial = false;
    public function prepare($query, ...$args) {
        foreach ($args as $arg) {
            $query = preg_replace('/%[dfs]/', is_numeric($arg) ? (string)$arg : "'" . addslashes((string)$arg) . "'", $query, 1);
        }
        return $query;
    }
    public function get_var($query) {
        $this->queries[] = (string)$query;
        if (preg_match("/SHOW TABLES LIKE '([^']+)'/", (string)$query, $m)) return $m[1];
        if ($this->fail_financial && strpos((string)$query, 'SUM(') !== false && strpos((string)$query, 'wp_bcrm_invoices') !== false) {
            $this->last_error = 'SQLSTATE Unknown column wp_bcrm_customers.total_paid at C:\\prod\\wordpress\\wp-db.php /var/www/html';
            return null;
        }
        $this->last_error = '';
        if (strpos((string)$query, 'SUM(') !== false && strpos((string)$query, 'wp_bcrm_invoices') !== false) return 123456789;
        if (strpos((string)$query, 'COUNT(*) FROM wp_bcrm_customers') !== false) return 5;
        if (strpos((string)$query, 'COUNT(DISTINCT a.customer_id)') !== false) return 2;
        if (strpos((string)$query, 'wp_bcrm_complaints') !== false) return 1;
        if (strpos((string)$query, 'wp_bcrm_deficiency_cases') !== false) return 1;
        if (strpos((string)$query, 'wp_bcrm_financial_cases') !== false) return 1;
        if (strpos((string)$query, 'wp_bcrm_service_cases') !== false) return 1;
        if (strpos((string)$query, 'wp_bcrm_refunds') !== false) return 1;
        return 0;
    }
    public function get_col($query) {
        $this->queries[] = (string)$query;
        $this->last_error = '';
        if (strpos((string)$query, 'wp_bcrm_production_readiness') !== false) return [2];
        if (strpos((string)$query, 'source_customer_id IN') !== false) return [3];
        if (strpos((string)$query, 'SELECT id FROM wp_bcrm_customers') !== false) return [1, 4, 5];
        return [];
    }
    public function get_results($query, $output = null) {
        $this->queries[] = (string)$query;
        $this->last_error = '';
        return [];
    }
};

require_once BIAWIN_CRM_DIR . 'includes/class-biawin-crm-db.php';
require_once BIAWIN_CRM_DIR . 'includes/class-biawin-crm-production-readiness.php';
require_once BIAWIN_CRM_DIR . 'includes/class-biawin-crm-reporting-population-service.php';
require_once BIAWIN_CRM_DIR . 'includes/class-biawin-crm-reporting-financial-service.php';
require_once BIAWIN_CRM_DIR . 'includes/class-biawin-crm-reporting-assignment-service.php';
require_once BIAWIN_CRM_DIR . 'includes/class-biawin-crm-reporting-consumer-adapter.php';
require_once BIAWIN_CRM_DIR . 'admin/class-biawin-crm-admin.php';

$GLOBALS['bcrm_caps'] = ['manage_options' => true];
Biawin_CRM_Reporting_Consumer_Adapter::reset_request_memo();
ob_start();
Biawin_CRM_Admin::dashboard();
$trusted_html = ob_get_clean();

hardening_assert_true(substr_count($trusted_html, 'class="bcrm-card"') === 12, 'render keeps exactly 12 KPI cards');
hardening_assert_true(strpos($trusted_html, '123,456,789') !== false, 'render shows trusted financial aggregate');
hardening_assert_true(!preg_grep('/financial_snapshot_for_customers/i', $GLOBALS['wpdb']->queries), 'render does not call full financial snapshot query path');

$GLOBALS['wpdb']->queries = [];
$GLOBALS['wpdb']->fail_financial = true;
Biawin_CRM_Reporting_Consumer_Adapter::reset_request_memo();
ob_start();
Biawin_CRM_Admin::dashboard();
$failed_html = ob_get_clean();

hardening_assert_true(strpos($failed_html, 'نامطمئن') !== false, 'failed adapter value renders as untrusted text');
hardening_assert_true(strpos($failed_html, 'داده این شاخص در حال حاضر قابل تأیید نیست') !== false, 'failed adapter note renders bounded Persian safe message');
foreach (['SQLSTATE', 'Unknown column', 'wp_bcrm_', 'C:\\prod', '/var/www', 'wp-db.php'] as $raw) {
    hardening_assert_true(strpos($failed_html, $raw) === false, 'raw error not rendered: ' . $raw);
}

$GLOBALS['bcrm_caps'] = [];
Biawin_CRM_Reporting_Consumer_Adapter::reset_request_memo();
ob_start();
Biawin_CRM_Admin::dashboard();
$agent_html = ob_get_clean();

hardening_assert_true(substr_count($agent_html, 'نامطمئن') >= 12, 'ordinary agent dashboard scope fails closed');

echo "TOTAL_PASS=$pass\n";
echo "TOTAL_FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
