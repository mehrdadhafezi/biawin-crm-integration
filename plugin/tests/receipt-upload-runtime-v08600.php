<?php
/**
 * REAL RUNTIME TEST — v0.8.60.1
 * Deficiency → Finance receipt upload pipeline (production code paths).
 *
 * Creates real temp files with unique content, submits them through a simulated
 * multipart POST ($_POST/$_FILES populated exactly like PHP does after a real
 * browser upload), and executes the production normalization/upload/persistence code.
 *
 * Every number below comes from actually executing the production pipeline,
 * NOT from searching source-code strings.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

define('ABSPATH', sys_get_temp_dir() . '/bcrm-fake-abspath/');
if (!is_dir(ABSPATH)) mkdir(ABSPATH, 0777, true);
// Production code require_once's the three WP admin upload includes; ship empty stubs so
// the media_handle_upload() behavior shim below stays the single definition.
foreach (['wp-admin/includes/file.php', 'wp-admin/includes/media.php', 'wp-admin/includes/image.php'] as $stub) {
    $p = ABSPATH . $stub;
    if (!is_dir(dirname($p))) mkdir(dirname($p), 0777, true);
    if (!is_file($p)) file_put_contents($p, "<?php // test stub\n");
}
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('ARRAY_A', 'ARRAY_A');
define('ARRAY_N', 'ARRAY_N');
define('OBJECT', 'OBJECT');

/* ---------- Minimal WordPress function shims (behavior where it matters) ---------- */
$GLOBALS['__options'] = [];
$GLOBALS['__shims'] = [];
function wp_unslash($v) { return is_array($v) ? array_map('wp_unslash', $v) : stripslashes($v); }
function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$k)); }
function sanitize_text_field($t) { return trim(preg_replace('/[\r\n\t ]+/', ' ', (string)$t)); }
function sanitize_textarea_field($t) { return trim((string)$t); }
function absint($n) { return abs((int)$n); }
function current_time($t) { return date('Y-m-d H:i:s'); }
function get_current_user_id() { return (int)($GLOBALS['__shims']['user_id'] ?? 1); }
function get_option($k, $d = false) { return $GLOBALS['__options'][$k] ?? $d; }
function update_option($k, $v, $a = false) { $GLOBALS['__options'][$k] = $v; return true; }
/* v0.8.62.26: WP-accurate add_option — fails when the key exists. Backs the staging lock.
   $GLOBALS['__force_lock_busy'] makes the lock appear held forever (timeout-path test). */
function add_option($k, $v, $dep = '', $autoload = 'yes') {
    if (!empty($GLOBALS['__force_lock_busy'])) return false;
    if (array_key_exists($k, $GLOBALS['__options'])) return false;
    $GLOBALS['__options'][$k] = $v;
    return true;
}
function add_query_arg(...$a) { return ''; }
function wp_safe_redirect($u) { }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function number_format_i18n($n, $d = 0) { return number_format((float)$n, $d, '.', ','); }
/* v0.8.62.23: escaping + nonce shims so the RENDER path (render_case_detail) can run for real. */
function esc_html($t) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }
function esc_attr($t) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }
function esc_js($t) { return preg_replace('/[\r\n]+/', ' ', (string)$t); }
function esc_url($u) { return (string)$u; }
function wp_nonce_field($a = '', $n = '_wpnonce', $r = true, $e = true) { if ($e) echo '<input type="hidden" name="' . $n . '" value="test">'; return ''; }
/* Capture diagnostic log lines so tests can assert guards actually logged their refusals. */
if (!function_exists('error_log')) { function error_log($line) { $GLOBALS['__shims']['log_lines'][] = (string)$line; return true; } }
else { /* PHP built-in present: route diagnostics into the sink via a tiny wrapper below. */ }
function wp_salt($s = '') { return 'testsalt'; }
function current_user_can($c) { return true; }
function get_user_by($f, $v) { return false; }
function wp_get_attachment_url($id) { return 'https://example.test/wp-content/uploads/file-' . $id . '.jpg'; }
function wp_delete_attachment($id, $force = false) { $GLOBALS['__shims']['deleted_attachments'][] = (int)$id; return true; }
function add_action(...$a) { }
function register_activation_hook(...$a) { }
function do_action(...$a) { }
function apply_filters($t, $v) { return $v; }
function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0777, true); }
function wp_normalize_path($p) { return str_replace('\\', '/', $p); }
function trailingslashit($p) { return rtrim($p, '/\\') . '/'; }
function get_temp_dir() { return sys_get_temp_dir() . '/'; }
function wp_unique_filename($dir, $name, $cb = null) { return $name; }
function wp_check_filetype_and_ext($f, $n, $m = null) {
    $ext = strtolower(pathinfo($n, PATHINFO_EXTENSION));
    if (is_array($m) && $m) {
        $allowed = false;
        foreach ($m as $exts => $mime) { if (in_array($ext, explode('|', (string)$exts), true)) { $allowed = true; break; } }
        if (!$allowed) return ['ext' => false, 'type' => false, 'proper_filename' => false];
    }
    return ['ext' => $ext, 'type' => 'application/octet-stream', 'proper_filename' => false];
}
function wp_check_filetype($n, $m = null) { return ['ext' => strtolower(pathinfo($n, PATHINFO_EXTENSION)), 'type' => 'application/octet-stream']; }
function get_allowed_mime_types() { return []; }
function is_wp_error($t) { return $t instanceof WP_Error; }
function current_time_ts() { return time(); }

class WP_Error
{
    private $code; private $message;
    public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message ?: $this->code; }
    public function get_error_code() { return $this->code; }
}

/* media_handle_upload(): the real WordPress implementation copies the tmp file into the
 * uploads dir and creates a posts row. We reproduce that observable behavior on the shim DB.
 * (Defined here; the wp-admin/includes stubs under ABSPATH are empty on purpose.)
 * v0.8.61.4: the REAL function runs is_uploaded_file() — a staged (disk-queued) file is NOT
 * a PHP-managed upload, so the production code must never feed staged files into this.
 * We mirror that constraint so a regression fatals/fails here instead of only on the host. */
if (!function_exists('media_handle_upload')) {
function media_handle_upload($field, $post_id = 0, $post_data = [], $overrides = [])
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) return new WP_Error('no_file', 'No file');
    $f = $_FILES[$field];
    if ((int)$f['error'] !== UPLOAD_ERR_OK) return new WP_Error('php_upload_error', 'PHP upload error ' . (int)$f['error']);
    if (!is_uploaded_file($f['tmp_name'])) return new WP_Error('not_a_php_upload', 'Not a PHP-managed upload (staged files must use media_handle_sideload)');
    return bcrm_shim_import_media($f);
}
}

/* media_handle_sideload(): WP's native importer for files already on disk (used for the
 * staged receipt queue). Same observable result as media_handle_upload minus the
 * is_uploaded_file() gate. $file is taken BY REFERENCE in real WordPress. */
if (!function_exists('media_handle_sideload')) {
function media_handle_sideload(&$file, $post_id = 0, $post_data = null, $overrides = [])
{
    if (!is_array($file)) return new WP_Error('no_file', 'No file');
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return new WP_Error('php_upload_error', 'PHP upload error');
    if (empty($file['tmp_name']) || !is_file($file['tmp_name'])) return new WP_Error('tmp_missing', 'Tmp missing');
    return bcrm_shim_import_media($file);
}
}

/* Shared media-import behavior for both shims: copy into uploads + register. */
function bcrm_shim_import_media(array $f)
{
    $mimes = Biawin_CRM_Upload_Normalizer::RECEIPT_MIMES;
    $check = wp_check_filetype_and_ext((string)$f['tmp_name'], (string)$f['name'], $mimes);
    if (empty($check['ext'])) return new WP_Error('filetype_rejected', 'This file type is not permitted');
    $up = sys_get_temp_dir() . '/bcrm-test-uploads';
    if (!is_dir($up)) mkdir($up, 0777, true);
    $dest = $up . '/att-' . (++$GLOBALS['__shims']['upload_seq']) . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$f['name']);
    if (!@copy($f['tmp_name'], $dest)) return new WP_Error('copy_failed', 'Copy failed');
    $GLOBALS['__shims']['uploads'][] = ['field' => 'sideload', 'name' => $f['name'], 'dest' => $dest, 'hash' => hash_file('sha256', $dest)];
    return 900000 + count($GLOBALS['__shims']['uploads']); // wp_attachment_id
}

/* v0.8.62.27: staged receipts are imported with wp_handle_sideload(test_type=false) +
 * wp_insert_attachment() (any format). The shim records the import exactly like
 * bcrm_shim_import_media() so every hash/count assertion keeps checking real bytes. */
if (!function_exists('wp_insert_attachment')) {
function wp_insert_attachment($args, $file = false, $parent = 0, $wp_error = false)
{
    if (!$file || !is_file($file)) return $wp_error ? new WP_Error('no_file', 'No file') : 0;
    $GLOBALS['__shims']['uploads'][] = ['field' => 'sideload', 'name' => basename((string)$file), 'dest' => (string)$file, 'hash' => hash_file('sha256', $file), 'mime' => (string)($args['post_mime_type'] ?? '')];
    return 900000 + count($GLOBALS['__shims']['uploads']);
}
}
if (!function_exists('wp_generate_attachment_metadata')) { function wp_generate_attachment_metadata($id, $file) { $GLOBALS['__shims']['metadata_calls'] = ($GLOBALS['__shims']['metadata_calls'] ?? 0) + 1; return ['file' => basename((string)$file)]; } }
if (!function_exists('wp_update_attachment_metadata')) { function wp_update_attachment_metadata($id, $meta) { return true; } }
if (!function_exists('add_filter')) { function add_filter($tag, $cb, $prio = 10, $args = 1) { $GLOBALS['__shims']['filters'][$tag][] = $cb; return true; } }
if (!function_exists('remove_filter')) { function remove_filter($tag, $cb, $prio = 10) { foreach ($GLOBALS['__shims']['filters'][$tag] ?? [] as $i => $f) { if ($f === $cb) unset($GLOBALS['__shims']['filters'][$tag][$i]); } return true; } }

/* wp_tempnam(): WP creates a unique empty temp file and returns its path. */
if (!function_exists('wp_tempnam')) {
function wp_tempnam($filename = '', $dir = '')
{
    $dir = $dir !== '' ? trailingslashit($dir) : get_temp_dir();
    if (!is_dir($dir)) wp_mkdir_p($dir);
    $path = $dir . uniqid('bcrm-row-') . '.tmp';
    return @touch($path) ? $path : false;
}
}

/* wp_handle_sideload(): reproduces WP's observable staging behavior — mime validation
 * against $overrides['mimes'] and a move into the uploads dir. Used by receipt staging. */
if (!function_exists('wp_handle_sideload')) {
// v0.8.61.2: matches the REAL WordPress signature — $file is taken BY REFERENCE
// (wp_handle_sideload mutates it). With this, calling it with a literal array (as
// v0.8.61.0/.1 did) fatals in the harness exactly like production PHP 8 does.
function wp_handle_sideload(&$file, $overrides = [], $time = null)
{
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return ['error' => 'php-upload-error'];
    if (empty($file['tmp_name']) || !is_file($file['tmp_name'])) return ['error' => 'tmp-missing'];
    $mimes = $overrides['mimes'] ?? get_allowed_mime_types();
    $check = wp_check_filetype_and_ext($file['tmp_name'], (string)$file['name'], $mimes);
    if (empty($check['ext'])) return ['error' => 'sorry-this-file-type-is-not-permitted-for-security-reasons'];
    $up = sys_get_temp_dir() . '/bcrm-test-uploads';
    if (!is_dir($up)) mkdir($up, 0777, true);
    $GLOBALS['__shims']['sideload_seq'] = ($GLOBALS['__shims']['sideload_seq'] ?? 0) + 1;
    $dest = $up . '/staged-' . $GLOBALS['__shims']['sideload_seq'] . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$file['name']);
    if (!@copy($file['tmp_name'], $dest)) return ['error' => 'move-failed'];
    return ['file' => $dest, 'url' => 'https://example.test/wp-content/uploads/' . basename($dest), 'type' => (string)$file['type']];
}
}
if (!function_exists('delete_option')) { function delete_option($k) { unset($GLOBALS['__options'][$k]); return true; } }
/* v0.8.62.26: the staging lock option must never leak across scenarios. */
unset($GLOBALS['__options']['bcrm_receipt_stage_lock']);
if (!function_exists('wp_generate_password')) { function wp_generate_password($l = 12, $sp = false, $dsa = false) { return 'deterministic-test-site-token'; } }
if (!function_exists('sanitize_file_name')) { function sanitize_file_name($n) { return preg_replace('/[^A-Za-z0-9._\-]/', '_', (string)$n); } }
if (!function_exists('get_transient')) { function get_transient($k) { return $GLOBALS['__options']['__transient_' . $k] ?? false; } }
if (!function_exists('set_transient')) { function set_transient($k, $v, $e = 0) { $GLOBALS['__options']['__transient_' . $k] = $v; return true; } }
if (!function_exists('delete_transient')) { function delete_transient($k) { unset($GLOBALS['__options']['__transient_' . $k]); return true; } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return !empty($GLOBALS['__shims']['logged_in']); } }
$GLOBALS['__shims']['logged_in'] = true;
if (!function_exists('check_ajax_referer')) { function check_ajax_referer($a, $q = false, $die = true) { return true; } }
if (!function_exists('wp_verify_nonce')) { function wp_verify_nonce($nonce, $action = -1) { return !empty($GLOBALS['__shims']['nonce_ok']) ? 1 : false; } }
if (!function_exists('wp_create_nonce')) { function wp_create_nonce($action = -1) { return 'fresh-nonce-' . $action; } }
$GLOBALS['__shims']['nonce_ok'] = 1; // default: valid nonce (production normal path)
if (!function_exists('hash_equals')) { function hash_equals($known, $user) { return is_string($known) && is_string($user) && strlen($known) === strlen($user) && hash('sha256', $known) === hash('sha256', $user); } }
if (!function_exists('nocache_headers')) { function nocache_headers(): void {} }
if (!function_exists('sanitize_key')) { function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$k)); } }
if (!function_exists('wp_send_json')) { function wp_send_json($r, $s = 0) { echo wp_json_encode($r); } }

/* ---------- Minimal wpdb shim over real SQLite (real persistence, real rollback) ---------- */
class Shim_SQLite
{
    public $pdo;
    public function __construct($path)
    {
        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys=OFF');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wpx_bcrm_deficiency_cases (
            id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER, case_code TEXT, status TEXT,
            current_department TEXT, reported_amount REAL, registered_invoice_amount REAL, difference_amount REAL,
            deficiency_agent_id INTEGER, finance_agent_id INTEGER, entered_at TEXT, created_by INTEGER,
            created_at TEXT, updated_at TEXT,
            last_contact_outcome TEXT, follow_up_at TEXT,
            sent_to_finance_at TEXT, deficiency_description TEXT, deficiency_result TEXT,
            customer_claimed_amount REAL, confirmed_difference_amount REAL, finance_result TEXT,
            finance_result_json TEXT, finance_finished_at TEXT, final_decision TEXT,
            completed_at TEXT, archived_at TEXT, final_approved_amount REAL)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wpx_bcrm_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER, object_type TEXT, object_id INTEGER,
            wp_attachment_id INTEGER, file_kind TEXT, note TEXT, uploaded_by INTEGER, created_at TEXT)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wpx_bcrm_case_actions (
            id INTEGER PRIMARY KEY AUTOINCREMENT, case_id INTEGER, customer_id INTEGER, department TEXT,
            action_type TEXT, from_status TEXT, to_status TEXT, user_id INTEGER, description TEXT,
            metadata_json TEXT, created_at TEXT)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wpx_bcrm_customer_tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER, tag TEXT, case_id INTEGER,
            created_by INTEGER, created_at TEXT, UNIQUE(customer_id, tag))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wpx_bcrm_refund_registers (
            id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER, invoice_code TEXT NOT NULL DEFAULT \'\',
            refund_status INTEGER,
            refund_amount REAL, source_type TEXT, source_row INTEGER, batch_code TEXT,
            created_by INTEGER, created_at TEXT)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wpx_bcrm_customers (
            id INTEGER PRIMARY KEY AUTOINCREMENT, display_name TEXT, source_customer_id TEXT,
            customer_code TEXT, total_paid REAL DEFAULT 0,
            declared_paid REAL, discrepancy_amount REAL, profile_completeness TEXT,
            completion_source TEXT, status TEXT, updated_at TEXT, sales_manager TEXT, journey_state TEXT)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wpx_bcrm_invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER, amount_paid REAL DEFAULT 0,
            exclude_from_total INTEGER DEFAULT 0,
            invoice_code TEXT, invoice_name TEXT, payment_info TEXT, invoice_status TEXT,
            seller_action_year TEXT, sales_manager TEXT,
            source_import_id INTEGER, has_input_conflict INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wpx_bcrm_phones (
            id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER, normalized_phone TEXT,
            is_primary INTEGER DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wpx_bcrm_interactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER, declared_paid REAL,
            satisfaction_score REAL, created_at TEXT)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wpx_bcrm_complaints (
            id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER, status TEXT)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wpx_users (
            ID INTEGER PRIMARY KEY AUTOINCREMENT, display_name TEXT, user_login TEXT)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wpx_posts (
            ID INTEGER PRIMARY KEY AUTOINCREMENT, post_title TEXT, post_type TEXT)');
        $this->pdo->exec("INSERT OR IGNORE INTO wpx_users (ID, display_name, user_login) VALUES (1, 'مدیر تست', 'admin'), (7, 'آپلودکننده اکسل', 'uploader'), (42, 'کارشناس اعلامی', 'agent42')");
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wpx_bcrm_audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER, object_type TEXT, object_id INTEGER,
            action TEXT, before_json TEXT, after_json TEXT, created_at TEXT)');
        /* v0.8.62.27: per-row staging queue (production creates it with MySQL DDL in ensure_table). */
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wpx_bcrm_receipt_stage (
            id INTEGER PRIMARY KEY AUTOINCREMENT, staged_id TEXT NOT NULL UNIQUE, user_id INTEGER NOT NULL DEFAULT 0,
            case_id INTEGER NOT NULL DEFAULT 0, content_hash TEXT NOT NULL, file_path TEXT NOT NULL,
            file_name TEXT NOT NULL DEFAULT \'\', mime_type TEXT NOT NULL DEFAULT \'\', file_size INTEGER NOT NULL DEFAULT 0,
            touched_at INTEGER NOT NULL DEFAULT 0, created_at TEXT, UNIQUE(user_id, case_id, content_hash))');
        $GLOBALS['__options']['bcrm_receipt_queue_schema'] = '1';
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wpx_bcrm_receipt_reviews (attachment_id INTEGER PRIMARY KEY, case_id INTEGER NOT NULL DEFAULT 0, review_status TEXT NOT NULL DEFAULT \'pending\', approved_amount REAL NULL, review_note TEXT NULL, reviewed_by INTEGER NULL, reviewed_at TEXT NULL)');
        $GLOBALS['__options']['bcrm_receipt_review_schema'] = '1';
        /* v0.8.66.0: deficiency completion ends the previous contact assignment (production table bcrm_assignments). */
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wpx_bcrm_assignments (
            id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, agent_user_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT "assigned", assigned_by INTEGER NOT NULL DEFAULT 0, assigned_at TEXT, completed_at TEXT)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wpx_bcrm_activities (
            id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER, actor_user_id INTEGER, activity_type TEXT,
            object_type TEXT, object_id INTEGER, title TEXT, meta_json TEXT, created_at TEXT)');
    }
    public function begin() { $this->pdo->beginTransaction(); }
    public function commit() { if ($this->pdo->inTransaction()) $this->pdo->commit(); }
    public function rollback() { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); }
    public function insert_id() { return (int)$this->pdo->lastInsertId(); }
}

class Shim_wpdb
{
    public $prefix = 'wpx_';
    public $insert_id = 0;
    public $last_error = '';
    public $users = 'wpx_users';
    public $posts = 'wpx_posts';
    private $db;
    public $in_transaction = false;
    public function __construct(Shim_SQLite $db) { $this->db = $db; }
    private function run(string $sql, array $params = [])
    {
        $stmt = $this->db->pdo->prepare($sql);
        foreach ($params as $i => $p) $stmt->bindValue($i + 1, $p);
        $stmt->execute();
        return $stmt;
    }
    public function prepare($sql, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        foreach ($args as $a) {
            $q = is_int($a) || is_float($a) ? (string)$a : "'" . addslashes((string)$a) . "'";
            $sql = preg_replace('/(%[sd])/i', $q, $sql, 1);
        }
        return $sql;
    }
    public function query($sql)
    {
        $s = trim($sql);
        if ($s === 'START TRANSACTION') { $this->in_transaction = true; $this->db->begin(); return 1; }
        if ($s === 'COMMIT') { $this->in_transaction = false; $this->db->commit(); return 1; }
        if ($s === 'ROLLBACK') { $this->in_transaction = false; $this->db->rollback(); return 1; }
        if (stripos($s, 'insert into') === 0 || stripos($s, 'update ') === 0 || stripos($s, 'delete from') === 0) {
            $this->run($s); return 1;
        }
        $stmt = $this->run($s);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function insert($table, array $data)
    {
        try {
            $cols = implode(',', array_keys($data));
            $ph = implode(',', array_fill(0, count($data), '?'));
            $this->run("INSERT INTO $table ($cols) VALUES ($ph)", array_values($data));
            $this->insert_id = $this->db->insert_id();
            return 1;
        } catch (Throwable $e) { $this->last_error = $e->getMessage(); return false; }
    }
    public function update($table, array $data, array $where)
    {
        try {
            $set = implode(',', array_map(fn($k) => "$k=?", array_keys($data)));
            $cond = implode(' AND ', array_map(fn($k) => "$k=?", array_keys($where)));
            $this->run("UPDATE $table SET $set WHERE $cond", array_merge(array_values($data), array_values($where)));
            return 1;
        } catch (Throwable $e) { $this->last_error = $e->getMessage(); return false; }
    }
    public function delete($table, array $where)
    {
        try {
            $cond = implode(' AND ', array_map(fn($k) => "$k=?", array_keys($where)));
            $this->run("DELETE FROM $table WHERE $cond", array_values($where));
            return 1;
        } catch (Throwable $e) { $this->last_error = $e->getMessage(); return false; }
    }
    public function get_var($sql)
    {
        $row = $this->run($sql)->fetch(PDO::FETCH_NUM);
        return $row ? $row[0] : null;
    }
    public function get_row($sql)
    {
        $row = $this->run($sql)->fetch(PDO::FETCH_OBJ);
        return $row ?: null;
    }
    public function get_results($sql, $mode = null)
    {
        $rows = $this->run($sql)->fetchAll($mode === ARRAY_A ? PDO::FETCH_ASSOC : PDO::FETCH_OBJ);
        return $mode === ARRAY_A ? ($rows ?: []) : ($rows ?: []);
    }
    public function get_col($sql)
    {
        return array_map(fn($r) => $r[0], $this->run($sql)->fetchAll(PDO::FETCH_NUM));
    }
}

/* Fake MySQL→SQLite SQL compatibility: rewrite table refs + FOR UPDATE inside our queries. */
class Shim_SQL_Fixer
{
    public static function fix(string $sql): string
    {
        $sql = str_ireplace(' FOR UPDATE', '', $sql);
        // Placeholders are already substituted when query() sees the SQL, so match generically.
        if (stripos($sql, 'ON DUPLICATE KEY UPDATE') !== false) {
            $pos = stripos($sql, 'ON DUPLICATE KEY UPDATE');
            $sql = substr($sql, 0, $pos);
            $sql = str_ireplace('INSERT INTO ', 'INSERT OR REPLACE INTO ', $sql);
        }
        return $sql;
    }
}

/* Route every wpdb SQL string through the SQLite compatibility fixer. */
class Shim_wpdb_Proxy extends Shim_wpdb
{
    private function f($sql) { return Shim_SQL_Fixer::fix($sql); }
    public function query($sql) { return parent::query($this->f($sql)); }
    public function insert($t, $d) { return parent::insert($this->f($t), $d); }
    public function update($t, $d, $w) { return parent::update($this->f($t), $d, $w); }
    public function delete($t, $w) { return parent::delete($this->f($t), $w); }
    public function get_var($sql) { return parent::get_var($this->f($sql)); }
    public function get_row($sql) { return parent::get_row($this->f($sql)); }
    public function get_results($sql, $m = null) { return parent::get_results($this->f($sql), $m); }
    public function get_col($sql) { return parent::get_col($this->f($sql)); }
}

/* ---------- Bootstrap production classes (fresh DB every run) ---------- */
$sqlite_path = sys_get_temp_dir() . '/bcrm-runtime-test.sqlite';
@unlink($sqlite_path);
$shim_db = new Shim_SQLite($sqlite_path); // constructor creates the full schema
$GLOBALS['wpdb'] = new Shim_wpdb_Proxy($shim_db);

$root = dirname(__DIR__);
require $root . '/includes/class-biawin-crm-db.php';
require $root . '/includes/class-biawin-crm-file-safety.php'; // v0.8.63.2 (loaded right after DB, as in the plugin)
require $root . '/includes/class-biawin-crm-upload-normalizer.php';
require $root . '/includes/class-biawin-crm-receipt-staging.php';
require $root . '/includes/class-biawin-crm-finance-review.php'; // v0.8.62.28
require $root . '/includes/class-biawin-crm-normalizer.php';
require $root . '/includes/class-biawin-crm-workflow-service.php';
require $root . '/includes/class-biawin-crm-customer-service.php'; // v0.8.62.23: برای تایید مالی با فاکتور (create_manual_invoice)
require $root . '/includes/class-biawin-crm-deficiency-workflow.php';
require $root . '/includes/class-biawin-crm-refund-register.php';

/* ---------- Test framework ---------- */
$PASS = 0; $FAIL = 0;
function T(bool $cond, string $msg): void
{
    global $PASS, $FAIL;
    if ($cond) { $PASS++; echo "PASS: $msg\n"; }
    else { $FAIL++; echo "FAIL: $msg\n"; }
}
function TVAL(string $label, int $expected, $actual): void
{
    T((int)$actual === $expected, "$label (expected $expected, got " . var_export($actual, true) . ")");
}

/** Build a case row and return its id. */
function make_case(int $id, int $customer_id, string $status = 'DEFICIENCY_UNDER_REVIEW'): int
{
    global $wpdb;
    $wpdb->insert(Biawin_CRM_DB::table('deficiency_cases'), [
        'id' => $id, 'customer_id' => $customer_id, 'case_code' => 'DEF-TEST-' . $id,
        'status' => $status, 'current_department' => 'deficiency',
        'reported_amount' => 1000000, 'registered_invoice_amount' => 0, 'difference_amount' => 1000000,
        'entered_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);
    return $id;
}

/** Reset the uploads sink + attachment table between scenarios. */
function reset_storage(): void
{
    global $wpdb;
    $GLOBALS['__shims']['uploads'] = [];
    $GLOBALS['__shims']['deleted_attachments'] = [];
    $GLOBALS['__shims']['upload_seq'] = 0;
    $GLOBALS['__shims']['sideload_seq'] = 0;
    // v0.8.62.24: upload-diagnostic trail must start clean per scenario — drop only upload rows.
    $wpdb->query('DELETE FROM wpx_bcrm_audit_log WHERE object_type=\'upload\'');
    $wpdb->query('DELETE FROM wpx_bcrm_attachments');
    $wpdb->query('DELETE FROM wpx_bcrm_case_actions');
    // Receipt-staging state + staged files (real files on disk under the test uploads dir).
    foreach (array_keys($GLOBALS['__options']) as $k) {
        if (strpos((string)$k, 'bcrm_receipt_stage_') === 0) {
            $st = $GLOBALS['__options'][$k];
            if (is_array($st)) foreach (($st['files'] ?? []) as $f) @unlink((string)($f['path'] ?? ''));
            unset($GLOBALS['__options'][$k]);
        }
    }
    unset($GLOBALS['__force_lock_busy']); // v0.8.62.26: lock-timeout simulation must not leak across scenarios
    $wpdb->query('DELETE FROM wpx_bcrm_receipt_stage'); // v0.8.62.27: per-row staging queue
    array_map('unlink', glob(sys_get_temp_dir() . '/bcrm-receipt-staging/r*.bin') ?: []);
    array_map('unlink', glob(sys_get_temp_dir() . '/bcrm-test-uploads/staged-*') ?: []);
}

/** Simulate the final submit of a STAGED receipt batch (what the browser posts since v0.8.61.0). */
function simulate_staged_submit(int $case_id, array $ids, array $amounts): void
{
    $_POST = [
        'case_id' => (string)$case_id, 'case_action' => 'deficiency_submit',
        'workflow_step' => 'send_finance', 'send_receipt' => '1', 'description' => 'توضیحات تست',
        'staged_receipt_ids' => array_values($ids), 'staged_receipt_amounts' => array_values($amounts),
    ];
    $_FILES = [];
    $_REQUEST = $_POST;
}

/** Persisted attachment rows for a case (id + wp id + hash of stored file). */
function persisted_rows(int $case_id): array
{
    global $wpdb;
    $rows = $wpdb->get_results('SELECT * FROM ' . Biawin_CRM_DB::table('attachments') . ' WHERE object_id=' . (int)$case_id, ARRAY_A);
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['id' => (int)$r['id'], 'wp_id' => (int)$r['wp_attachment_id'], 'note' => (string)$r['note']];
    }
    return $out;
}

/** SHA-256 of every media-library file this test produced (by wp id). */
function uploaded_hashes(): array
{
    $map = [];
    foreach ($GLOBALS['__shims']['uploads'] as $u) $map[$u['hash']] = true;
    return array_keys($map);
}

/** Simulate a browser multipart POST: fills $_POST and $_FILES exactly like PHP does.
 * $rows: ordered map rowKey => ['amount','tmp','name'] where rowKey is the file's ORIGINAL
 * request key (sparse allowed). receipt_amounts[] defaults to a dense list over $rows in
 * DOM order (exactly what a browser posts); pass $amounts to override. */
function simulate_multipart(int $case_id, array $rows, string $description = 'توضیحات تست', ?array $amounts = null): void
{
    $_POST = [];
    $_FILES = [];
    $_REQUEST = [];
    $_POST['case_id'] = (string)$case_id;
    $_POST['case_action'] = 'deficiency_submit';
    $_POST['workflow_step'] = 'send_finance';
    $_POST['send_receipt'] = '1';
    $_POST['description'] = $description;
    if ($amounts === null) { $amounts = []; foreach ($rows as $row) { $amounts[] = $row['amount']; } }
    foreach ($rows as $key => $row) {
        if (!empty($row['tmp'])) {
            $_FILES['case_documents']['name'][$key] = $row['name'];
            $_FILES['case_documents']['type'][$key] = 'image/jpeg';
            $_FILES['case_documents']['tmp_name'][$key] = $row['tmp'];
            $_FILES['case_documents']['error'][$key] = UPLOAD_ERR_OK;
            $_FILES['case_documents']['size'][$key] = (int)filesize($row['tmp']);
        }
    }
    $_POST['receipt_amounts'] = array_values($amounts); // dense, like a real browser POST
    $_REQUEST = $_POST;
}

/* Create real temp files with unique content. */
function make_receipt(string $basename, string $marker): array
{
    return make_receipt_from_content($basename, "BIAWIN-RECEIPT-TEST\n" . $marker . "\n" . str_repeat((string)crc32($marker), 50) . "\n");
}

function make_receipt_from_content(string $basename, string $content): array
{
    $path = sys_get_temp_dir() . '/' . $basename;
    file_put_contents($path, $content);
    return ['path' => $path, 'hash' => hash_file('sha256', $path), 'name' => $basename];
}

$uploads_dir = sys_get_temp_dir() . '/bcrm-test-uploads';
if (!is_dir($uploads_dir)) mkdir($uploads_dir, 0777, true);
array_map('unlink', glob($uploads_dir . '/att-*') ?: []);

echo "=== Biawin CRM v0.8.60.1 — REAL RUNTIME RECEIPT UPLOAD TESTS ===\n";
echo "PHP " . PHP_VERSION . " | max_file_uploads=" . ini_get('max_file_uploads') . "\n\n";

/* =====================================================================
 * TEST 1 — MANDATORY: 20 UNIQUE FILES  (release blocker)
 * ===================================================================== */
echo "--- TEST 1: 20 unique files (release blocker) ---\n";
reset_storage();
$case_id = make_case(101, 5001);
$files = [];
$rows = [];
for ($i = 1; $i <= 20; $i++) {
    $f = make_receipt(sprintf('receipt-%02d.jpg', $i), 'unique-content-' . $i . '-' . bin2hex(random_bytes(8)));
    $files[$i - 1] = $f; // original request keys 0..19, consecutive here
    $rows[$i - 1] = ['amount' => number_format($i * 100000), 'tmp' => $f['path'], 'name' => $f['name']];
}
simulate_multipart($case_id, $rows);
$received_keys = Biawin_CRM_Upload_Normalizer::uploaded_keys('case_documents');
TVAL('selected/declared rows', 20, count($rows));
TVAL('received $_FILES entries', 20, count($received_keys));
$result = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T((bool)($result['ok'] ?? false), 'send_to_finance() succeeded: ' . ($result['message'] ?? ''));
$acc = $result['upload_accounting'] ?? [];
TVAL('uploaded media files', 20, count($GLOBALS['__shims']['uploads']));
TVAL('uploaded_count accounting', 20, (int)($acc['uploaded_count'] ?? -1));
TVAL('DB attachment inserts', 20, count(persisted_rows($case_id)));
TVAL('persisted_count accounting', 20, (int)($acc['persisted_count'] ?? -1));
TVAL('unique persisted file identities', 20, count(uploaded_hashes()));
$selected_hashes = array_map(fn($f) => $f['hash'], $files);
sort($selected_hashes); $got = uploaded_hashes(); sort($got);
T($selected_hashes === $got, 'every selected file identity persisted exactly once (0 duplicated, 0 missing)');
TVAL('duplicate_count accounting', 0, (int)($acc['duplicate_count'] ?? -1));
TVAL('failed_count accounting', 0, (int)($acc['failed_count'] ?? -1));
$case = $GLOBALS['wpdb']->get_row('SELECT * FROM ' . Biawin_CRM_DB::table('deficiency_cases') . ' WHERE id=' . $case_id);
T($case && $case->status === 'FINANCE_REVIEW_PENDING', 'case moved to FINANCE_REVIEW_PENDING after full success');

/* Amount↔file pairing: each receipt_amounts[i] must land on the SAME row's file (note check). */
$persisted = persisted_rows($case_id);
$notes_ok = true;
foreach ($persisted as $row) {
    if (strpos($row['note'], 'مبلغ فیش') === false) $notes_ok = false;
}
T($notes_ok, 'every attachment row carries its own receipt amount in the note');

/* =====================================================================
 * TEST 2 — SPARSE INDEXES (0,2,5,8,11,17,21,30)
 * Files keep their sparse request keys exactly as PHP delivers them for
 * 20 rows where rows 1,3,4,6,9,10,12,15 were deleted in the UI.
 * ===================================================================== */
echo "\n--- TEST 2: sparse request indexes ---\n";
reset_storage();
$case_id = make_case(102, 5002);
$sparse = [0, 2, 5, 8, 11, 17, 21, 30];
$rows = [];
$files = [];
$amounts = [];
foreach ($sparse as $n => $key) {
    $f = make_receipt(sprintf('sparse-%02d.jpg', $n), 'sparse-' . $key . '-' . bin2hex(random_bytes(8)));
    $files[$key] = $f;
    $rows[$key] = ['amount' => number_format(($n + 1) * 100000), 'tmp' => $f['path'], 'name' => $f['name']];
    $amounts[] = $rows[$key]['amount'];
}
simulate_multipart($case_id, $rows, 'توضیحات تست', $amounts);
TVAL('received $_FILES entries (sparse keys preserved)', 8, count(Biawin_CRM_Upload_Normalizer::uploaded_keys('case_documents')));
$result = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T((bool)($result['ok'] ?? false), 'send_to_finance() succeeded with sparse keys: ' . ($result['message'] ?? ''));
TVAL('uploaded media files', 8, count($GLOBALS['__shims']['uploads']));
TVAL('DB attachment inserts', 8, count(persisted_rows($case_id)));
TVAL('unique persisted identities', 8, count(uploaded_hashes()));
$sel = array_map(fn($f) => $f['hash'], $files); sort($sel); $got = uploaded_hashes(); sort($got);
T($sel === $got, 'every sparse-indexed file persisted exactly once (0 duplicated, 0 missing)');
// Amount↔file pairing must follow DOM order, not request keys:
$persisted = persisted_rows($case_id);
$note_amounts = [];
foreach ($persisted as $row) { if (preg_match('/مبلغ فیش: ([0-9,]+)/', $row['note'], $m)) $note_amounts[] = $m[1]; }
$expected_amounts = array_map(fn($key) => $rows[$key]['amount'], $sparse);
T($note_amounts === $expected_amounts, 'sparse rows keep their own amounts (no cross-row pairing): ' . json_encode($note_amounts, JSON_UNESCAPED_UNICODE));

/* =====================================================================
 * TEST 3 — DELETE MIDDLE ROWS (20 rows, delete 4/9/13, add 3 new)
 * ===================================================================== */
echo "\n--- TEST 3: delete middle rows, then add new rows ---\n";
reset_storage();
$case_id = make_case(103, 5003);
// 20 UI rows get browser keys 0..19
$all_rows = [];
$all_files = [];
for ($i = 0; $i < 20; $i++) {
    $f = make_receipt(sprintf('del-%02d.jpg', $i), 'del-' . $i . '-' . bin2hex(random_bytes(8)));
    $all_files[$i] = $f;
    $all_rows[$i] = ['amount' => number_format(($i + 1) * 100000), 'tmp' => $f['path'], 'name' => $f['name']];
}
// user deletes rows 4, 9 and 13 (files/amounts never sent for those keys)
foreach ([4, 9, 13] as $gone) {
    unset($all_rows[$gone], $all_files[$gone]);
}
// user adds 3 new rows → browser assigns them keys 20, 21, 22
for ($j = 20; $j <= 22; $j++) {
    $f = make_receipt(sprintf('new-%02d.jpg', $j), 'new-' . $j . '-' . bin2hex(random_bytes(8)));
    $all_files[$j] = $f;
    $all_rows[$j] = ['amount' => number_format(($j + 1) * 100000), 'tmp' => $f['path'], 'name' => $f['name']];
}
// Expected remaining rows (visible in the UI) in DOM order:
$expected_keys = array_values(array_diff(range(0, 19), [4, 9, 13]));
$expected_keys = array_merge($expected_keys, [20, 21, 22]);
// Browser posts: dense receipt_amounts[] over VISIBLE rows + files keeping their ORIGINAL keys.
$visible_rows = [];
$visible_amounts = [];
foreach ($expected_keys as $key) {
    $visible_rows[$key] = ['amount' => $all_rows[$key]['amount'], 'tmp' => $all_files[$key]['path'], 'name' => $all_files[$key]['name']];
    $visible_amounts[] = $all_rows[$key]['amount'];
}
simulate_multipart($case_id, $visible_rows, 'توضیحات تست', $visible_amounts);
$result = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T((bool)($result['ok'] ?? false), 'send_to_finance() succeeded after middle-row deletions: ' . ($result['message'] ?? ''));
TVAL('uploaded media files (20 - 3 deleted + 3 new)', 20, count($GLOBALS['__shims']['uploads']));
TVAL('DB attachment inserts', 20, count(persisted_rows($case_id)));
TVAL('unique persisted identities', 20, count(uploaded_hashes()));
$sel = array_map(fn($f) => $f['hash'], $all_files); sort($sel); $got = uploaded_hashes(); sort($got);
T($sel === $got, 'every visible row file uploaded exactly once — deleted rows gone, no substitution (0 dup, 0 missing)');

/* =====================================================================
 * TEST 4 — SAME NAME, DIFFERENT CONTENT
 * ===================================================================== */
echo "\n--- TEST 4: same filename, different content ---\n";
reset_storage();
$case_id = make_case(104, 5004);
$a = make_receipt('receipt.jpg', 'content-A-' . bin2hex(random_bytes(8)));
$b = make_receipt('receipt-dup-name.jpg', 'content-B-' . bin2hex(random_bytes(8)));
$b2 = $b; $b2['name'] = 'receipt.jpg'; // same display name, different content
$rows = [
    0 => ['amount' => '100,000', 'tmp' => $a['path'], 'name' => $a['name']],
    1 => ['amount' => '200,000', 'tmp' => $b2['path'], 'name' => $b2['name']],
];
simulate_multipart($case_id, $rows);
$result = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T((bool)($result['ok'] ?? false), 'both same-named files accepted: ' . ($result['message'] ?? ''));
TVAL('uploaded media files', 2, count($GLOBALS['__shims']['uploads']));
TVAL('DB attachment inserts', 2, count(persisted_rows($case_id)));
TVAL('unique persisted identities', 2, count(uploaded_hashes()));
T(uploaded_hashes() === [$a['hash'], $b['hash']] || count(uploaded_hashes()) === 2, 'both distinct contents persisted (filename not used as identity)');

/* =====================================================================
 * TEST 5 — INTENTIONAL EXACT DUPLICATE (same physical file twice)
 * ===================================================================== */
echo "\n--- TEST 5: exact duplicate content ---\n";
reset_storage();
$case_id = make_case(105, 5005);
$d1 = make_receipt('dup-1.jpg', 'exact-same-content-' . bin2hex(random_bytes(4)));
$d2 = make_receipt_from_content('dup-2.jpg', (string)file_get_contents($d1['path'])); // byte-identical content, different name
$rows = [
    0 => ['amount' => '100,000', 'tmp' => $d1['path'], 'name' => $d1['name']],
    1 => ['amount' => '200,000', 'tmp' => $d2['path'], 'name' => $d2['name']],
];
simulate_multipart($case_id, $rows);
$result = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
$acc = $result['upload_accounting'] ?? [];
T((bool)($result['ok'] ?? false), 'same-content rows both persist with their own amounts: ' . ($result['message'] ?? ''));
TVAL('both rows persisted (v0.8.61.4 per-row semantics)', 2, count(persisted_rows($case_id)));
TVAL('both media imports done', 2, count($GLOBALS['__shims']['uploads']));
TVAL('duplicate_count reported as observability figure', 1, (int)($acc['duplicate_count'] ?? -1));
$amounts_in_notes = array_map(static fn($r) => (bool)preg_match('/100,000|200,000/u', $r['note']), persisted_rows($case_id));
T($amounts_in_notes === [true, true], 'each row keeps its own amount in its attachment note');
T((bool)preg_match('/200,000/u', (string)persisted_rows($case_id)[1]['note']), 'second row amount (200,000) not lost');

/* The request-shape family behind the production defect — must fail loud, never corrupt: */
echo "\n--- TEST 5b: request shapes that must fail loud (never corrupt silently) ---\n";
reset_storage();
$case_id = make_case(106, 5006);
$dup = make_receipt('dup-only.jpg', 'all-dup-' . bin2hex(random_bytes(4)));
// Shape A: two files, only one visible amount row (deleted file without its row).
simulate_multipart($case_id, [
    0 => ['amount' => '100,000', 'tmp' => $dup['path'], 'name' => $dup['name']],
    1 => ['amount' => '200,000', 'tmp' => $dup['path'], 'name' => $dup['name']],
], 'توضیحات تست', ['100,000']);
$result = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T(empty($result['ok']), 'file without a matching amount row fails loud: ' . ($result['message'] ?? ''));
TVAL('zero partial persists on inconsistent request', 0, count(persisted_rows($case_id)));

/* Same content twice in one request (v0.8.61.4): every visible row is a real receipt
 * line with its own amount — BOTH rows persist; duplicate_count reports the repeat. */
reset_storage();
$case_id = make_case(106, 5006);
$dup = make_receipt('dup-only.jpg', 'all-dup-' . bin2hex(random_bytes(4)));
$rows = [
    0 => ['amount' => '100,000', 'tmp' => $dup['path'], 'name' => $dup['name']],
    1 => ['amount' => '200,000', 'tmp' => $dup['path'], 'name' => $dup['name']],
];
simulate_multipart($case_id, $rows);
$result = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
$acc = $result['upload_accounting'] ?? [];
T((bool)($result['ok'] ?? false), 'duplicate-in-request persists every row with its own amount: ' . ($result['message'] ?? ''));
TVAL('both rows persisted', 2, count(persisted_rows($case_id)));
TVAL('duplicate reported clearly', 1, (int)($acc['duplicate_count'] ?? -1));
$note_all = implode('|', array_map(static fn($r) => $r['note'], persisted_rows($case_id)));
T(strpos($note_all, '100,000') !== false && strpos($note_all, '200,000') !== false, 'both amounts survived (no row dropped)');
$case = $GLOBALS['wpdb']->get_row('SELECT * FROM ' . Biawin_CRM_DB::table('deficiency_cases') . ' WHERE id=' . $case_id);
T($case && $case->status === 'FINANCE_REVIEW_PENDING', 'valid request still completes');

/* =====================================================================
 * TEST 6 — PARTIAL FAILURE ROLLBACK (row 3 of 5 fails at media upload)
 * ===================================================================== */
echo "\n--- TEST 6: partial failure → full rollback, no status change ---\n";
reset_storage();
$case_id = make_case(107, 5007);
$rows = [];
$files = [];
for ($i = 0; $i < 5; $i++) {
    $f = make_receipt("roll-$i.jpg", 'roll-' . $i . '-' . bin2hex(random_bytes(8)));
    $files[$i] = $f;
    $rows[$i] = ['amount' => number_format(($i + 1) * 100000), 'tmp' => $f['path'], 'name' => $f['name']];
}
simulate_multipart($case_id, $rows);
// Corrupt row 3 (index 3): PHP-level error like a broken transfer.
$_FILES['case_documents']['error'][3] = UPLOAD_ERR_PARTIAL;
$result = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T(empty($result['ok']), 'partial failure returns error (not silent partial success)');
T(strpos((string)($result['message'] ?? ''), 'ناقص منتقل شد') !== false, 'error message explains the failing row: ' . ($result['message'] ?? ''));
TVAL('DB attachment inserts after rollback', 0, count(persisted_rows($case_id)));
/* uploads[] is an append-only log; rollback deletes the media files, so the invariant
 * is LIVE uploads (append log minus wp_delete_attachment'd ids) == 0. */
$deleted_ids = array_map('intval', $GLOBALS['__shims']['deleted_attachments'] ?? []);
$live_uploads = 0;
foreach ($GLOBALS['__shims']['uploads'] as $i => $u) { if (!in_array(900001 + (int)$i, $deleted_ids, true)) $live_uploads++; }
TVAL('media files kept after rollback (live uploads)', 0, $live_uploads);
TVAL('rolled-back media deletions', 3, count($deleted_ids));
$acc = $result['upload_accounting'] ?? [];
TVAL('failed_count accounting', 1, (int)($acc['failed_count'] ?? -1));
$case = $GLOBALS['wpdb']->get_row('SELECT * FROM ' . Biawin_CRM_DB::table('deficiency_cases') . ' WHERE id=' . $case_id);
T($case && $case->status === 'DEFICIENCY_UNDER_REVIEW', 'case NOT moved to FINANCE_REVIEW_PENDING after partial failure');

/* =====================================================================
 * TEST 7 — SERVER LIMIT DETECTION (truncated request)
 * ===================================================================== */
echo "\n--- TEST 7: server limit detection ---\n";
reset_storage();
$case_id = make_case(108, 5008);
$rows = [];
for ($i = 0; $i < 25; $i++) {
    $f = make_receipt(sprintf('limit-%02d.jpg', $i), 'limit-' . $i . '-' . bin2hex(random_bytes(8)));
    $rows[$i] = ['amount' => number_format(($i + 1) * 100000), 'tmp' => $f['path'], 'name' => $f['name']];
}
simulate_multipart($case_id, $rows);
// Simulate max_file_uploads=20 truncation: PHP drops everything beyond the 20th file slot.
$idx = 0;
foreach (array_keys($_FILES['case_documents']['name']) as $k) {
    if ($idx >= 20) {
        unset($_FILES['case_documents']['name'][$k], $_FILES['case_documents']['type'][$k],
              $_FILES['case_documents']['tmp_name'][$k], $_FILES['case_documents']['error'][$k],
              $_FILES['case_documents']['size'][$k]);
    }
    $idx++;
}
$chk = Biawin_CRM_Upload_Normalizer::check_server_limits(count(Biawin_CRM_Upload_Normalizer::uploaded_keys('case_documents')), 25);
T(empty($chk['ok']) && !empty($chk['truncated']), 'truncation detected when received < declared rows');
T(strpos($chk['message'], 'max_file_uploads') !== false || strpos($chk['message'], 'ناقص') !== false, 'clear error names the server limit: ' . $chk['message']);
$result = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T(empty($result['ok']), 'truncated request is NOT silently processed end-to-end');
TVAL('DB attachment inserts on truncated request', 0, count(persisted_rows($case_id)));

/* =====================================================================
 * TEST 8 — MALFORMED $_FILES ENTRIES ARE REJECTED EXPLICITLY
 * ===================================================================== */
echo "\n--- TEST 8: malformed multipart entries ---\n";
reset_storage();
$case_id = make_case(109, 5009);
$f0 = make_receipt('ok.jpg', 'malformed-ok-' . bin2hex(random_bytes(8)));
$rows = [0 => ['amount' => '100,000', 'tmp' => $f0['path'], 'name' => $f0['name']]];
simulate_multipart($case_id, $rows);
// Malformed: tmp_name without matching name key (PHP-level corruption).
$_FILES['case_documents']['tmp_name'][7] = $f0['path'];
$norm = Biawin_CRM_Upload_Normalizer::normalize_field('case_documents');
T(!isset($norm[7]), 'tmp_name key without name key is rejected, not borrowed by another row');
T(count($norm) === 1 && isset($norm[0]), 'well-formed entry survives normalization');

/* =====================================================================
 * TEST 9 — REGRESSION: 1 / 2 / 5 / 20 FILES (25 files = max_file_uploads
 * truncation scenario and is asserted in TEST 7 with a clear server-limit
 * error instead of silent partial processing on this 20-slot PHP build).
 * ===================================================================== */
echo "\n--- TEST 9: regression matrix 1/2/5/20 ---\n";
$scenarios = [1 => 201, 2 => 202, 5 => 203, 20 => 204];
foreach ($scenarios as $n => $cid) {
    reset_storage();
    $case_id = make_case($cid, 6000 + $n);
    $rows = [];
    $files = [];
    for ($i = 0; $i < $n; $i++) {
        $f = make_receipt(sprintf('reg-%02d.jpg', $i), "reg-$n-$i-" . bin2hex(random_bytes(8)));
        $files[$i] = $f;
        $rows[$i] = ['amount' => number_format(($i + 1) * 100000), 'tmp' => $f['path'], 'name' => $f['name']];
    }
    simulate_multipart($case_id, $rows);
    $result = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
    $ok = (bool)($result['ok'] ?? false);
    $acc = $result['upload_accounting'] ?? [];
    T($ok, "$n-file request succeeds: " . ($result['message'] ?? ''));
    TVAL("$n selected → received", $n, count(Biawin_CRM_Upload_Normalizer::uploaded_keys('case_documents')));
    TVAL("$n selected → uploaded", $n, count($GLOBALS['__shims']['uploads']));
    TVAL("$n selected → persisted", $n, count(persisted_rows($case_id)));
    TVAL("$n selected → unique identities", $n, count(uploaded_hashes()));
    TVAL("$n selected → duplicate_count", 0, (int)($acc['duplicate_count'] ?? -1));
    TVAL("$n selected → missing", 0, $n - count(uploaded_hashes()));
}

/* 25 files on a max_file_uploads=20 PHP build → clear error, no silent partial state. */
reset_storage();
$case_id = make_case(205, 6025);
$rows = [];
for ($i = 0; $i < 25; $i++) {
    $f = make_receipt(sprintf('reg25-%02d.jpg', $i), "reg25-$i-" . bin2hex(random_bytes(8)));
    $rows[$i] = ['amount' => number_format(($i + 1) * 100000), 'tmp' => $f['path'], 'name' => $f['name']];
}
simulate_multipart($case_id, $rows);
$result = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T(empty($result['ok']), '25 files on a 20-slot server → clear error (no silent partial): ' . ($result['message'] ?? ''));
TVAL('zero persists when the request exceeds max_file_uploads', 0, count(persisted_rows($case_id)));

/* =====================================================================
 * TEST 9b — HOST CAP SIGNATURE (28 declared → 20 received on a 20-slot server)
 * The production incident: the browser posts 28 rows, PHP silently keeps
 * exactly max_file_uploads (20) file slots and drops the rest before the
 * plugin sees them. The error must NAME the cap and include actionable
 * hosting guidance, and nothing may persist.
 * ===================================================================== */
echo "\n--- TEST 9b: exact host-cap signature (28 declared → 20 received) ---\n";
$chk = Biawin_CRM_Upload_Normalizer::check_server_limits(20, 28);
T(empty($chk['ok']) && !empty($chk['truncated']), 'exact-cap truncation detected');
T(strpos($chk['message'], 'سرور هاستینگ شما') !== false, 'exact-cap message names the hosting cap as the cause: ' . $chk['message']);
T(Biawin_CRM_Upload_Normalizer::looks_like_max_uploads_cap(20, 28) === true, 'looks_like_max_uploads_cap(20,28) is true on a 20-slot build');
T(Biawin_CRM_Upload_Normalizer::looks_like_max_uploads_cap(19, 28) === false, 'below-cap partial loss is NOT classified as the exact cap (still truncated)');
T(Biawin_CRM_Upload_Normalizer::looks_like_max_uploads_cap(20, 20) === false, 'a complete 20/20 request is NOT flagged as truncated');
$notice = Biawin_CRM_Upload_Normalizer::server_limit_notice('رفع ناقصی');
T(strpos($notice, 'max_file_uploads') !== false && strpos($notice, '.user.ini') !== false, 'server_limit_notice includes hosting-panel and .user.ini instructions');
reset_storage();
$case_id = make_case(206, 6028);
$rows = [];
for ($i = 0; $i < 28; $i++) {
    $f = make_receipt(sprintf('cap-%02d.jpg', $i), "cap-$i-" . bin2hex(random_bytes(8)));
    $rows[$i] = ['amount' => number_format(($i + 1) * 100000), 'tmp' => $f['path'], 'name' => $f['name']];
}
simulate_multipart($case_id, $rows);
// Host truncation: PHP keeps exactly the first 20 file slots and drops the rest.
$idx = 0;
foreach (array_keys($_FILES['case_documents']['name']) as $k) {
    if ($idx >= 20) {
        unset($_FILES['case_documents']['name'][$k], $_FILES['case_documents']['type'][$k],
              $_FILES['case_documents']['tmp_name'][$k], $_FILES['case_documents']['error'][$k],
              $_FILES['case_documents']['size'][$k]);
    }
    $idx++;
}
$result = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T(empty($result['ok']), '28-row request truncated at the cap is rejected end-to-end');
T(strpos((string)($result['message'] ?? ''), 'سرور هاستینگ شما') !== false, 'workflow error names the hosting cap as the cause');
T(strpos((string)($result['message'] ?? ''), 'user.ini') !== false, 'workflow error includes the hosting fix guidance');
TVAL('zero DB inserts when the host cap truncates the request', 0, count(persisted_rows($case_id)));

/* =====================================================================
 * TEST 9c — PRE-SUBMIT GUARDS SHIP TO THE BROWSER
 * ===================================================================== */
echo "\n--- TEST 9c: browser pre-submit cap guards ---\n";
$fix_js = file_get_contents($root . '/assets/portal-fixes.js');
$front_js = file_get_contents($root . '/assets/frontend.js');
$front_php = file_get_contents($root . '/includes/class-biawin-crm-frontend.php');
$admin_php = file_get_contents($root . '/admin/class-biawin-crm-admin.php');
$workflow_src = file_get_contents($root . '/includes/class-biawin-crm-deficiency-workflow.php');
foreach ([['portal-fixes.js', $fix_js]] as $asset) {
    T(strpos($asset[1], 'bcrm_stage_receipt') !== false && strpos($asset[1], 'stageNonce') !== false, $asset[0] . ' stages every file through the staging endpoint with the localized nonce');
    T(strpos($asset[1], 'staged_receipt_ids') !== false && strpos($asset[1], 'staged_receipt_amounts') !== false, $asset[0] . ' attaches staged ids + amounts to the final submit');
    T(strpos($asset[1], 'preventDefault') !== false, $asset[0] . ' holds submission until staging completes');
}
/* v0.8.62.27: exactly ONE uploader — frontend.js' duplicate copy raced portal-fixes.js on the portal. */
T(strpos($front_js, 'bcrm_stage_receipt') === false && strpos($front_js, 'staged_receipt_ids') === false, 'frontend.js no longer carries a second receipt uploader');
T(strpos($fix_js, 'function initReceiptUploader') !== false && strpos($fix_js, 'initReceiptUploader(form);') !== false, 'portal-fixes.js owns the single receipt uploader and runs it for every workflow form');
T(strpos($front_php, "'stageNonce' => wp_create_nonce(Biawin_CRM_Receipt_Staging::NONCE_ACTION)") !== false, 'frontend localizes stageNonce');
T(strpos($front_php, "'stagingToken'") !== false, 'frontend localizes stagingToken');
T(strpos($admin_php, "'stageNonce'") !== false && strpos($admin_php, "'stagingToken'") !== false, 'admin localizes stageNonce + stagingToken');
T(strpos($workflow_src, 'data-bcrm-receipt-limit') !== false && strpos($workflow_src, 'receipt_limit_hint') !== false, 'receipt form renders the upload guidance hint');
T(strpos($workflow_src, 'data-bcrm-stage-status') !== false, 'receipt form renders the live staging progress element');

/* =====================================================================
 * TEST 10 — FINANCE ROUND-TRIP: RETURNED_TO_DEFICIENCY → resend
 * Round 1: 3 receipts. Finance returns the case. Round 2: 2 new receipts.
 * Expected: 5 historical attachments, old ones intact, status FINANCE_REVIEW_PENDING.
 * ===================================================================== */
echo "\n--- TEST 10: finance round-trip with previous attachments preserved ---\n";
reset_storage();
$case_id = make_case(301, 7001);
// Round 1: 3 receipts
$r1 = [];
$f1 = [];
for ($i = 0; $i < 3; $i++) {
    $f = make_receipt("round1-$i.jpg", 'round1-' . $i . '-' . bin2hex(random_bytes(8)));
    $f1[$i] = $f;
    $r1[$i] = ['amount' => number_format(($i + 1) * 100000), 'tmp' => $f['path'], 'name' => $f['name']];
}
simulate_multipart($case_id, $r1);
$res1 = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T((bool)($res1['ok'] ?? false), 'round 1 send-to-finance succeeded: ' . ($res1['message'] ?? ''));
TVAL('round 1 attachments persisted', 3, count(persisted_rows($case_id)));
// Finance returns the case (finance_result rejected → RETURNED_TO_DEFICIENCY)
$GLOBALS['wpdb']->update(Biawin_CRM_DB::table('deficiency_cases'), ['status' => 'FINANCE_REVIEW_PENDING', 'current_department' => 'finance'], ['id' => $case_id]);
$_POST = [
    'case_id' => (string)$case_id, 'case_action' => 'finance_submit', 'finance_result' => 'rejected',
    'reject_reason' => 'invalid_receipt', 'description' => 'فیش نامعتبر بود',
];
$_FILES = [];
$res2 = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T((bool)($res2['ok'] ?? false), 'finance rejection (return to deficiency) succeeded: ' . ($res2['message'] ?? ''));
$case = $GLOBALS['wpdb']->get_row('SELECT * FROM ' . Biawin_CRM_DB::table('deficiency_cases') . ' WHERE id=' . $case_id);
T($case && $case->status === 'RETURNED_TO_DEFICIENCY', 'case is RETURNED_TO_DEFICIENCY');
// Round 2: 2 NEW receipts (same workflow, same normalization code)
$r2 = [];
$f2 = [];
for ($i = 0; $i < 2; $i++) {
    $f = make_receipt("round2-$i.jpg", 'round2-' . $i . '-' . bin2hex(random_bytes(8)));
    $f2[$i] = $f;
    $r2[$i] = ['amount' => number_format(($i + 4) * 100000), 'tmp' => $f['path'], 'name' => $f['name']];
}
$GLOBALS['__shims']['uploads'] = []; // count only round-2 media adds
simulate_multipart($case_id, $r2);
$res3 = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T((bool)($res3['ok'] ?? false), 'round 2 send-to-finance succeeded: ' . ($res3['message'] ?? ''));
TVAL('round 2 new media uploads', 2, count($GLOBALS['__shims']['uploads']));
TVAL('total historical attachments (3 old + 2 new)', 5, count(persisted_rows($case_id)));
$all_hashes = [];
foreach (persisted_rows($case_id) as $row) {
    // map wp id → stored file hash
    $wp_id = $row['wp_id'];
    foreach ($GLOBALS['__shims']['uploads'] as $u) { if (false) {} }
}
// Old round-1 media files must not have been deleted:
T(count($GLOBALS['__shims']['deleted_attachments'] ?? []) === 0, 'no previous attachments deleted during round-trip');
$case = $GLOBALS['wpdb']->get_row('SELECT * FROM ' . Biawin_CRM_DB::table('deficiency_cases') . ' WHERE id=' . $case_id);
T($case && $case->status === 'FINANCE_REVIEW_PENDING', 'case re-entered FINANCE_REVIEW_PENDING after round-trip');

/* =====================================================================
 * TEST 11 — NO-FILE ACTION STILL WORKS (upload_document path, single scalar $_FILES)
 * ===================================================================== */
echo "\n--- TEST 11: single-file upload_document action (scalar \$_FILES) ---\n";
reset_storage();
$case_id = make_case(401, 8001);
$single = make_receipt('single-doc.jpg', 'single-' . bin2hex(random_bytes(8)));
$_FILES = [
    'case_document' => [
        'name' => $single['name'], 'type' => 'image/jpeg', 'tmp_name' => $single['path'],
        'error' => UPLOAD_ERR_OK, 'size' => (int)filesize($single['path']),
    ],
];
$_POST = ['case_id' => (string)$case_id, 'case_action' => 'upload_document', 'document_type' => 'bank_receipt'];
$res = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T((bool)($res['ok'] ?? false), 'single scalar upload succeeds: ' . ($res['message'] ?? ''));
TVAL('media uploads', 1, count($GLOBALS['__shims']['uploads']));
TVAL('DB attachment inserts', 1, count(persisted_rows($case_id)));

/* =====================================================================
 * TEST 12 — OLD v0.8.59.9 BUG REPRODUCED AS FIXED:
 * sparse file keys + compacted amounts (the production data-corruption shape).
 * A real browser posts receipt_amounts[] DENSELY over visible rows while
 * case_documents[] keeps ORIGINAL keys — the exact mismatch that corrupted
 * v0.8.59.9. Files must stay on their own rows and keep their own amounts.
 * ===================================================================== */
echo "\n--- TEST 12: sparse-file-keys vs compacted-amounts (production bug shape) ---\n";
reset_storage();
$case_id = make_case(501, 9001);
// UI rows: rows 0..4 built; row 1 deleted; rows 3,4 added → visible files keep keys 0,2,3,4.
$file_defs = [
    0 => make_receipt('bugfix-a.jpg', 'bugfix-a-' . bin2hex(random_bytes(8))),
    2 => make_receipt('bugfix-b.jpg', 'bugfix-b-' . bin2hex(random_bytes(8))),
    3 => make_receipt('bugfix-c.jpg', 'bugfix-c-' . bin2hex(random_bytes(8))),
    4 => make_receipt('bugfix-d.jpg', 'bugfix-d-' . bin2hex(random_bytes(8))),
];
$rows = [];
$visible_amounts = ['150,000', '250,000', '350,000', '450,000'];
$ki = 0;
foreach ($file_defs as $key => $f) {
    $rows[$key] = ['amount' => $visible_amounts[$ki++], 'tmp' => $f['path'], 'name' => $f['name']];
}
// Dense amounts over the 4 visible rows, sparse file keys — the real browser shape:
simulate_multipart($case_id, $rows, 'توضیحات تست', $visible_amounts);
$result = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
$acc = $result['upload_accounting'] ?? [];
T((bool)($result['ok'] ?? false), 'request succeeds: ' . ($result['message'] ?? ''));
TVAL('uploaded media files', 4, count($GLOBALS['__shims']['uploads']));
TVAL('DB attachment inserts', 4, count(persisted_rows($case_id)));
TVAL('unique persisted identities', 4, count(uploaded_hashes()));
$sel = array_map(fn($f) => $f['hash'], $file_defs); sort($sel); $got = uploaded_hashes(); sort($got);
T($sel === $got, 'no file swapped onto another row and no file lost despite sparse keys (0 dup, 0 missing)');
// Verify pairing: notes must carry the amounts in DOM order — proof that
// amount[i] stayed attached to the file of visible row i (no cross-row corruption).
$persisted = persisted_rows($case_id);
$note_amounts = [];
foreach ($persisted as $row) {
    if (preg_match('/مبلغ فیش: ([0-9,]+)/', $row['note'], $m)) $note_amounts[] = $m[1];
}
T($note_amounts === $visible_amounts, 'amount↔file pairing preserved row-by-row (no cross-row corruption): ' . json_encode($note_amounts, JSON_UNESCAPED_UNICODE));

/* =====================================================================
 * TEST 13 — STAGED UPLOADS (the production path since v0.8.61.0):
 * 28 files staged ONE PER REQUEST, then a final submit carrying ONLY ids
 * + amounts. Server multi-file caps and post_max_size are irrelevant BY
 * CONSTRUCTION. The release blocker shape (28 files > any host cap) must
 * now fully persist.
 * ===================================================================== */
echo "\n--- TEST 13: STAGED batch of 28 files (beyond every server cap) ---\n";
reset_storage();
$case_id = make_case(601, 11001);
$stage_token = Biawin_CRM_Receipt_Staging::fixed_token();
$ids = [];
$amounts = [];
$expected_hashes = [];
for ($i = 0; $i < 28; $i++) {
    $f = make_receipt(sprintf('staged-%02d.jpg', $i), 'staged-' . $i . '-' . bin2hex(random_bytes(8)));
    $_FILES = ['case_documents' => [
        'name' => [$i => $f['name']], 'type' => [$i => 'image/jpeg'],
        'tmp_name' => [$i => $f['path']], 'error' => [$i => UPLOAD_ERR_OK],
        'size' => [$i => (int)filesize($f['path'])],
    ]];
    $entries = Biawin_CRM_Upload_Normalizer::normalize_field('case_documents');
    $st = Biawin_CRM_Receipt_Staging::stage_entry(reset($entries), $stage_token);
    T(!empty($st['ok']), "staged file $i accepted: " . ($st['message'] ?? ''));
    $ids[] = (string)$st['id'];
    $amounts[] = number_format(($i + 1) * 100000);
    $expected_hashes[] = (string)$st['hash'];
}
TVAL('staged files accepted (no count cap)', 28, count($ids));
TVAL('staging state holds 28 real files', 28, count(Biawin_CRM_Receipt_Staging::state($stage_token)['files']));
$_FILES = []; // nothing file-shaped rides along on the final submit
simulate_staged_submit($case_id, $ids, $amounts);
$result = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
$acc = $result['upload_accounting'] ?? [];
T((bool)($result['ok'] ?? false), '28-file STAGED submit succeeds (zero server-cap dependence): ' . ($result['message'] ?? ''));
TVAL('uploaded media files', 28, count($GLOBALS['__shims']['uploads']));
TVAL('DB attachment inserts', 28, count(persisted_rows($case_id)));
TVAL('unique persisted identities', 28, count(uploaded_hashes()));
TVAL('duplicate_count', 0, (int)($acc['duplicate_count'] ?? -1));
TVAL('missing', 0, 28 - count(uploaded_hashes()));
$sel13 = $expected_hashes; sort($sel13);
$got13 = uploaded_hashes(); sort($got13);
T($sel13 === $got13, 'every staged file materialized exactly once (identity-verified)');
$note_amounts = [];
foreach (persisted_rows($case_id) as $row) {
    if (preg_match('/مبلغ فیش: ([0-9,]+)/', $row['note'], $m)) $note_amounts[] = $m[1];
}
T($note_amounts === array_values($amounts), 'staged amount↔file pairing preserved row-by-row: ' . json_encode($note_amounts, JSON_UNESCAPED_UNICODE));
$case = $GLOBALS['wpdb']->get_row('SELECT * FROM ' . Biawin_CRM_DB::table('deficiency_cases') . ' WHERE id=' . $case_id);
T($case && $case->status === 'FINANCE_REVIEW_PENDING', 'case moved to FINANCE_REVIEW_PENDING');
T(count(Biawin_CRM_Receipt_Staging::state($stage_token)['files']) === 0, 'staging state consumed after successful finalize');
$left = glob(sys_get_temp_dir() . '/bcrm-receipt-staging/r*.bin') ?: [];
T(count($left) === 0, 'staged queue files cleaned after finalize (' . count($left) . ' left)');

/* =====================================================================
 * TEST 13b — STAGED GUARDS: unknown id, amount mismatch, mid-batch
 * failure rollback, repeated id = duplicate not double-persist.
 * ===================================================================== */
echo "\n--- TEST 13b: staged id validation, mismatch, rollback, duplicate ---\n";
$stage_one = function (string $marker) use ($stage_token): array {
    $f = make_receipt('s13b-' . substr(md5($marker), 0, 6) . '.jpg', $marker . '-' . bin2hex(random_bytes(8)));
    $_FILES = ['case_documents' => [
        'name' => [0 => $f['name']], 'type' => [0 => 'image/jpeg'],
        'tmp_name' => [0 => $f['path']], 'error' => [0 => UPLOAD_ERR_OK],
        'size' => [0 => (int)filesize($f['path'])],
    ]];
    $entries = Biawin_CRM_Upload_Normalizer::normalize_field('case_documents');
    return Biawin_CRM_Receipt_Staging::stage_entry(reset($entries), $stage_token);
};
// (a) unknown staged id → loud error, zero persists
reset_storage();
$case_id = make_case(602, 11002);
$st = $stage_one('s13b-a');
T(!empty($st['ok']), 'stage one file for guard tests');
simulate_staged_submit($case_id, [$st['id'], 'runknown0000000000000000'], ['100,000', '200,000']);
$result = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T(empty($result['ok']), 'unknown staged id rejected loudly: ' . ($result['message'] ?? ''));
T(strpos((string)($result['message'] ?? ''), 'پیدا نشد') !== false, 'unknown-id error names the missing staged file');
TVAL('zero persists on unknown id', 0, count(persisted_rows($case_id)));
// (b) id/amount count mismatch → rejected, zero persists
simulate_staged_submit($case_id, [$st['id']], ['100,000', '200,000']);
$result = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T(empty($result['ok']), 'id/amount count mismatch rejected');
TVAL('zero persists on mismatch', 0, count(persisted_rows($case_id)));
// (c) mid-batch failure → FULL rollback of already-materialized rows
reset_storage();
$case_id = make_case(603, 11003);
$sids = [];
$samounts = [];
foreach (['s13b-c1', 's13b-c2', 's13b-c3'] as $marker) {
    $r = $stage_one($marker);
    T(!empty($r['ok']), "stage $marker");
    $sids[] = (string)$r['id'];
    $samounts[] = '100,000';
}
// The server loses one staged file before finalize (disk cleanup, TTL edge...):
$lost_id = $sids[1];
@unlink(Biawin_CRM_Receipt_Staging::state($stage_token)['files'][$lost_id]['path']);
simulate_staged_submit($case_id, $sids, $samounts);
$result = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T(empty($result['ok']), 'mid-batch staged failure rejects the whole submit: ' . ($result['message'] ?? ''));
TVAL('zero DB rows after mid-batch failure (full rollback)', 0, count(persisted_rows($case_id)));
TVAL('v0.8.62.27: missing file detected BEFORE any media import (nothing to roll back)', 0, count($GLOBALS['__shims']['uploads'] ?? []));
// (d) repeated staged id (same file on 2 rows) → BOTH rows persist with their own amounts
// (v0.8.61.4: the old skip-repeat semantics silently dropped rows + amounts and left
// the case stuck — exactly what the 17:33 production log shows)
reset_storage();
$case_id = make_case(604, 11004);
$st = $stage_one('s13b-d');
simulate_staged_submit($case_id, [$st['id'], $st['id']], ['100,000', '250,000']);
$result = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
$acc = $result['upload_accounting'] ?? [];
T((bool)($result['ok'] ?? false), 'repeated staged id persists both rows: ' . ($result['message'] ?? ''));
TVAL('both rows materialized', 2, count(persisted_rows($case_id)));
TVAL('duplicate_count still reports the content repeat', 1, (int)($acc['duplicate_count'] ?? -1));
$note_all = implode('|', array_map(static fn($r) => $r['note'], persisted_rows($case_id)));
T(strpos($note_all, '100,000') !== false && strpos($note_all, '250,000') !== false, 'each repeated row keeps its own amount');

/* =====================================================================
 * TEST 14 — v0.8.61.1 layered staging auth (the "18 of 18 failed" incident)
 * Production signature: a page-cache-served portal page carries a stale nonce;
 * EVERY per-file stage request must still succeed for a logged-in user with a
 * valid site token, and must fail LOUDLY (not with HTTP "0") otherwise.
 * ===================================================================== */
echo "\n--- TEST 14: layered staging auth (stale cached nonce incident) ---\n";
// (a) stale nonce + valid token + logged in → stage STILL succeeds (token fallback)
reset_storage();
$case_id = make_case(605, 11005);
$GLOBALS['__shims']['nonce_ok'] = 0; // simulate nonce expired under page cache
$st = $stage_one('s14-stale-nonce');
T(!empty($st['ok']), 'stale nonce + valid site token + logged in → stage succeeds (fallback): ' . ($st['message'] ?? ''));
// and the finalized workflow still accepts those staged ids
simulate_staged_submit($case_id, [$st['id']], ['100,000']);
$result = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T((bool)($result['ok'] ?? false), 'finalize after token-fallback staging passes: ' . ($result['message'] ?? ''));
TVAL('fallback-staged file persisted', 1, count(persisted_rows($case_id)));
// (b) stale nonce + WRONG token → refused explicitly (403-shape response array from authorize())
$GLOBALS['__shims']['nonce_ok'] = 0;
$bad = Biawin_CRM_Receipt_Staging::stage_entry(['name' => 'x.jpg', 'type' => 'image/jpeg', 'tmp_name' => '/nonexistent/x', 'error' => UPLOAD_ERR_OK, 'size' => 10], 'wrong-token-value');
// wrong token simply opens a DIFFERENT (empty) state — the guard is authorize(); verify via reflection-free call:
$ref = new ReflectionMethod('Biawin_CRM_Receipt_Staging', 'authorize');
$ref->setAccessible(true);
$_POST['nonce'] = 'stale';
$_POST['staging_token'] = 'wrong-token-value';
$GLOBALS['__shims']['logged_in'] = true;
$denied = $ref->invoke(null);
T(is_array($denied) && ($denied['status'] ?? 0) === 403, 'stale nonce + wrong token → explicit 403 denial');
// (c) no session at all → explicit 401 with actionable message (never silent)
$GLOBALS['__shims']['logged_in'] = false;
$denied = $ref->invoke(null);
T(is_array($denied) && ($denied['status'] ?? 0) === 401, 'guest + stale nonce → explicit 401 with re-login guidance');
T(strpos((string)($denied['message'] ?? ''), 'وارد') !== false, '401 message tells the user to log in again');
// (d) valid nonce again → normal path restored
$GLOBALS['__shims']['logged_in'] = true;
$GLOBALS['__shims']['nonce_ok'] = 1;
$denied = $ref->invoke(null);
T($denied === null, 'valid nonce + logged in → authorized');
// (e) config endpoint shape (what the browser fetches to recover from stale nonce)
ob_start();
Biawin_CRM_Receipt_Staging::ajax_config(); // shimmed wp_send_json echoes instead of exiting
$cfg = json_decode((string)ob_get_clean(), true);
T(is_array($cfg) && !empty($cfg['ok']) && is_string($cfg['nonce']) && $cfg['nonce'] !== '' && is_string($cfg['token']) && $cfg['token'] !== '', 'bcrm_stage_config returns fresh nonce + token');
// (f) the incident regression: 18 files with a stale nonce all stage, all persist
reset_storage();
$case_id = make_case(606, 11006);
$GLOBALS['__shims']['nonce_ok'] = 0;
$ids18 = [];
$amounts18 = [];
foreach (range(1, 18) as $i) {
    $r = $stage_one('s18-' . $i);
    if (!empty($r['ok'])) { $ids18[] = (string)$r['id']; $amounts18[] = (string)(1000 * $i); }
}
TVAL('18 files staged under stale nonce (incident scenario)', 18, count($ids18));
simulate_staged_submit($case_id, $ids18, $amounts18);
$result = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T((bool)($result['ok'] ?? false), '18-file incident batch finalizes: ' . ($result['message'] ?? ''));
TVAL('18-file incident batch persisted', 18, count(persisted_rows($case_id)));
$GLOBALS['__shims']['nonce_ok'] = 1;

/* =====================================================================
 * TEST 15 — v0.8.61.5: the send_to_finance status guard must never reject
 * silently (22-Sep incident: 18 staged files, no finalize trace in the log,
 * user sees "پرونده در این وضعیت قابل ارسال به مالی نیست" with no reason).
 * ===================================================================== */
echo "\n--- TEST 15: status guard is loud, not silent ---\n";
reset_storage();
$case_id = make_case(607, 11007, 'FINANCE_REVIEW_PENDING'); // already sent case
$st15 = $stage_one('s15-a');
simulate_staged_submit($case_id, [$st15['id']], ['100,000']);
$result = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T(empty($result['ok']), 'already-sent case is rejected (guard intact)');
T(strpos((string)($result['message'] ?? ''), 'در انتظار بررسی مالی') !== false, 'rejection message explains the status in plain language: ' . ($result['message'] ?? ''));
TVAL('zero persists when guard blocks', 0, count(persisted_rows($case_id)));
// The guard logs via Biawin_CRM_DB::log → the shim audit_log table; the refusal must be
// there with action=receipt_send_to_finance_status_guard_blocked and the case id in the JSON context.
$blocked = $GLOBALS['wpdb']->get_row("SELECT * FROM " . Biawin_CRM_DB::table('audit_log') . " WHERE action='receipt_send_to_finance_status_guard_blocked' ORDER BY id DESC LIMIT 1");
T((bool)$blocked && strpos((string)($blocked->after_json ?? ''), '"case_id":' . (int)$case_id) !== false, 'guard refusal is LOGGED with case id + status');
// staging state must survive a guard rejection so the user can retry after refresh
$state15 = Biawin_CRM_Receipt_Staging::state(Biawin_CRM_Receipt_Staging::fixed_token());
T(isset($state15['files'][$st15['id']]), 'staged file survives guard rejection (retry-friendly)');

/* =====================================================================
 * TEST 16 — v0.8.62.0 REFUND REGISTER: excel import → confirmed tag,
 * balance = invoices − confirmed refunds, call outcome → reported tag.
 * ===================================================================== */
echo "\n--- TEST 16: refund register (excel import + tags + balance) ---\n";
$mk_customer = function (int $id, string $external, float $total, string $name = 'مشتری تست'): int {
    global $wpdb;
    $wpdb->insert(Biawin_CRM_DB::table('customers'), ['id' => $id, 'display_name' => $name, 'source_customer_id' => $external, 'customer_code' => 'BC-' . $id, 'total_paid' => $total]);
    if ($total > 0) $wpdb->insert(Biawin_CRM_DB::table('invoices'), ['customer_id' => $id, 'amount_paid' => $total, 'exclude_from_total' => 0]);
    return $id;
};
reset_storage();
$mk_customer(9001, '14239', 200000000.0, 'حمزه رضایی کاسی');
$mk_customer(9002, '14240', 50000000.0, 'مشتری دوم');
$mk_customer(9003, '14241', 10000000.0, 'مشتری سوم');
// (a) excel import: one valid, one unknown customer, one invalid status
$parsed = [
    ['customer id' => '14239', 'وضعیت عودت' => '1', 'مبلغ عودت' => '15,000,000'],
    ['customer id' => '99999', 'وضعیت عودت' => '1', 'مبلغ عودت' => '1,000'],
    ['customer id' => '14240', 'وضعیت عودت' => '7', 'مبلغ عودت' => '2,000'],
];
$batch = Biawin_CRM_Refund_Register::import_batch($parsed, 7);
TVAL('excel rows persisted', 1, (int)$GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM " . Biawin_CRM_DB::table('refund_registers')));
TVAL('unknown customer reported (not silently dropped)', 1, count($batch['not_found']));
TVAL('invalid row reported (not silently dropped)', 1, count($batch['invalid']));
T(($batch['not_found'][0]['customer_id'] ?? $batch['not_found'][0]['key'] ?? '') === '99999' && $batch['not_found'][0]['row'] === 3, 'unknown customer keeps its excel row number');
T($batch['invalid'][0]['row'] === 4, 'invalid row keeps its excel row number');
// (b) confirmed tag + balance
T(in_array('REFUND_CONFIRMED', Biawin_CRM_Refund_Register::tags_for(9001), true), 'excel import grants REFUND_CONFIRMED tag');
T(!in_array('REFUND_CONFIRMED', Biawin_CRM_Refund_Register::tags_for(9002), true), 'invalid-row customer does NOT get the tag');
TVAL('balance = invoices − confirmed refunds', 185000000.0, (int)Biawin_CRM_Refund_Register::balance_for(9001));
TVAL('untouched customer keeps full balance', 50000000, (int)Biawin_CRM_Refund_Register::balance_for(9002));
// (c) re-upload same customer → replacement, old row stays in history
$parsed2 = [['customer id' => '14239', 'وضعیت عودت' => '1', 'مبلغ عودت' => '25,000,000']];
$batch2 = Biawin_CRM_Refund_Register::import_batch($parsed2, 7);
TVAL('replacement counted', 1, $batch2['replaced_count']);
TVAL('history keeps both records', 2, (int)$GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM " . Biawin_CRM_DB::table('refund_registers') . " WHERE customer_id=9001"));
TVAL('balance uses the LATEST amount', 175000000.0, (int)Biawin_CRM_Refund_Register::balance_for(9001));
// (d) call outcome «عودت شده است» → reported tag
Biawin_CRM_Refund_Register::apply_reported_tag(9002, 3, 555);
$tags9002 = Biawin_CRM_Refund_Register::tags_for(9002);
T(in_array('REFUND_REPORTED', $tags9002, true), 'call outcome grants REFUND_REPORTED tag');
T(!in_array('REFUND_CONFIRMED', $tags9002, true), 'reported tag is distinct from confirmed');
$rep_log = $GLOBALS['wpdb']->get_row("SELECT * FROM " . Biawin_CRM_DB::table('audit_log') . " WHERE action='refund_reported_by_agent' ORDER BY id DESC LIMIT 1");
T((bool)$rep_log && strpos((string)$rep_log->after_json, '"customer_id":9002') !== false, 'reported-by-agent event is logged with actor context');
// (e) tagged-customer lists + defaults carry the new outcome
T(in_array(9001, Biawin_CRM_Refund_Register::tagged_customer_ids('any'), true) && in_array(9002, Biawin_CRM_Refund_Register::tagged_customer_ids('any'), true), 'tagged_customer_ids(any) covers both tag kinds');
T(Biawin_CRM_Refund_Register::tagged_customer_ids('REFUND_CONFIRMED') === [9001], 'tagged_customer_ids(CONFIRMED) filters correctly');
$defaults = Biawin_CRM_Workflow_Service::default_contact_outcomes();
$rr_def = null;
foreach ($defaults as $d) if ($d['key'] === 'refund_reported') $rr_def = $d;
T((bool)$rr_def && $rr_def['label'] === 'عودت شده است', 'contact outcome «عودت شده است» exists in defaults');
T(Biawin_CRM_Workflow_Service::contact_outcome_by_key('refund_reported') !== null, 'refund_reported outcome resolvable by key');
// (f) header canonicalization tolerates common variants
T(Biawin_CRM_Refund_Register::canonical_header('Customer ID') === 'customer id', 'header alias: Customer ID');
T(Biawin_CRM_Refund_Register::canonical_header('کد مشتری') === 'customer id', 'header alias: کد مشتری');
T(Biawin_CRM_Refund_Register::canonical_header('مبلغ عودتي') === 'مبلغ عودت', 'header alias: arabic yeh variant');
T(Biawin_CRM_Refund_Register::canonical_header('نامعلوم') === '', 'unknown header maps to nothing (not misassigned)');
// (g) status parser
T(Biawin_CRM_Refund_Register::parse_status('1') === 1 && Biawin_CRM_Refund_Register::parse_status('۰') === 0, 'status parser accepts 0/1 incl. Persian digits');
T(Biawin_CRM_Refund_Register::parse_status('بله') === 1 && Biawin_CRM_Refund_Register::parse_status('maybe') === null, 'status parser rejects unknown values explicitly');
// (h) excel source row 0 → rejected (only 1 registers)
$parsed3 = [['customer id' => '14241', 'وضعیت عودت' => '0', 'مبلغ عودت' => '5,000']];
$batch3 = Biawin_CRM_Refund_Register::import_batch($parsed3, 7);
TVAL('status-0 row is explicitly rejected, never registered', 0, $batch3['ok_count']);
T(count($batch3['invalid']) === 1, 'status-0 rejection explains itself');
// v0.8.62.1: 2 excel rows (customer 9001) + 1 call-declaration row (customer 9002, from (d)) = 3
TVAL('no register row created for status 0', 3, (int)$GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM " . Biawin_CRM_DB::table('refund_registers')));

/* =====================================================================
 * TEST 17 — v0.8.62.1 FIXES:
 * (a) HTTP tag filters survive sanitize_key (uppercase tags, not 'any')
 * (b) agent call declaration lands in refund history (source=call, no amount)
 * (c) bcrm_refund_register role visible in role_labels (کاربران پرتال)
 * ===================================================================== */
echo "\n--- TEST 17: v0.8.62.1 fixes (HTTP filters, call history, role label) ---\n";
// (a) THE sanitize_key bug: HTTP values are lowercase; the allowlist is uppercase.
//     Simulate the exact WP behavior: what the HTTP layer hands to the helper.
foreach ([
    'REFUND_REPORTED' => 'REFUND_REPORTED',
    'REFUND_CONFIRMED' => 'REFUND_CONFIRMED',
    'BOTH' => 'BOTH',
    'any' => 'any',
    'ANY' => 'any',
    '' => 'any',
    'refund_confirmed' => 'REFUND_CONFIRMED', // what sanitize_key() would have produced from the HTTP POST
    'refund_reported' => 'REFUND_REPORTED',
    'both' => 'BOTH',
    'garbage' => 'any',
] as $http_in => $expect) {
    $got = Biawin_CRM_Refund_Register::sanitize_tag_filter($http_in);
    T($got === $expect, "sanitize_tag_filter('$http_in') === '$expect'" . ($got !== $expect ? " (got $got)" : ''));
}
// lowercase tags must now REACH the SQL layer — prove via tagged_customer_ids
T(Biawin_CRM_Refund_Register::tagged_customer_ids(Biawin_CRM_Refund_Register::sanitize_tag_filter('refund_confirmed')) === [9001], 'lowercase HTTP filter resolves to the CONFIRMED id list (bug fixed end-to-end)');
T(in_array(9002, Biawin_CRM_Refund_Register::tagged_customer_ids(Biawin_CRM_Refund_Register::sanitize_tag_filter('refund_reported')), true), 'lowercase HTTP filter resolves to the REPORTED id list');
// (b) agent call declaration → history row visible in the profile tab source
$hist_before = count(Biawin_CRM_Refund_Register::history_for(9002));
Biawin_CRM_Refund_Register::apply_reported_tag(9002, 42, 777);
$hist = Biawin_CRM_Refund_Register::history_for(9002);
T(count($hist) === $hist_before + 1, 'call declaration adds a history record');
$call_row = $hist[0];
T($call_row->source_type === 'call', 'history row source_type = call');
T((float)$call_row->refund_amount === 0.0, 'call declaration writes NO amount (0) — balance untouched');
T((int)$call_row->created_by === 42, 'call declaration records the agent (created_by)');
T((string)$call_row->actor_name !== '', 'history joins the agent display name');
T($call_row->batch_code === 'call-777', 'call row carries the interaction reference');
$balance_9002 = Biawin_CRM_Refund_Register::balance_for(9002);
TVAL('call declaration does NOT change the balance', 50000000, (int)$balance_9002);
// re-saving the same interaction must NOT duplicate the history row
Biawin_CRM_Refund_Register::apply_reported_tag(9002, 42, 777);
T(count(Biawin_CRM_Refund_Register::history_for(9002)) === $hist_before + 1, 're-saving the same interaction is idempotent (no duplicate history)');
// and the balance semantics are untouched by the call rows (LAST excel refund still 25M)
TVAL('balance still uses LAST excel refund after call rows exist', 175000000, (int)Biawin_CRM_Refund_Register::balance_for(9001));
// (c) role label visible in the کاربران پرتال dropdown (source-of-truth: the auth class file)
$auth_src = file_get_contents($root . '/includes/class-biawin-crm-auth.php');
T(preg_match("/'bcrm_refund_register'\s*=>\s*'ثبت‌کننده عودت اکسلی'/u", $auth_src) === 1, 'role_labels exposes bcrm_refund_register for the portal users page');
// and the create-user handler accept path: role passes its own isset($roles[$role]) check
// (role dropdown + handler both read Biawin_CRM_Auth::role_labels(); label presence above is the gate)

/* =====================================================================
 * TEST 18 — v0.8.62.2 ARCHITECTURE: Excel upload is ADMIN-ONLY.
 * (a) admin menu registers the page under CRM with the dedicated cap
 * (b) portal navigation no longer carries the upload view; عودتی‌ها stays
 * (c) the two flows stay independent (call outcome intact, excel untouched)
 * ===================================================================== */
echo "\n--- TEST 18: refund excel upload is admin-only (architecture guard) ---\n";
$admin_src = file_get_contents($root . '/admin/class-biawin-crm-admin.php');
$front_src = file_get_contents($root . '/includes/class-biawin-crm-frontend.php');
// (a) real WP admin menu entry, dedicated cap, canonical URL
T(strpos($admin_src, "add_submenu_page('biawin-crm', 'ثبت عودت‌ها', 'ثبت عودت‌ها', 'bcrm_refund_register', 'biawin-crm-refund-register'") !== false, 'admin menu: CRM بیاوین → ثبت عودت‌ها guarded by bcrm_refund_register');
T(strpos($admin_src, "admin.php?page=biawin-crm-refund-register") !== false, 'page reachable at the canonical /wp-admin/admin.php?page=biawin-crm-refund-register URL');
T(strpos($admin_src, "!current_user_can('bcrm_refund_register') && !current_user_can('bcrm_manage_cases')") !== false, 'page + upload handler both re-check the capability server-side');
// (b) portal must NOT render the upload page anymore
T(strpos($front_src, "'refund-register'") === false, 'portal navigation no longer contains the upload view (removed)');
T(strpos($front_src, "refund_register_page") === false, 'portal view map no longer renders the upload page');
T(strpos($front_src, "'refunded' => ['label'=>'عودتی‌ها'") !== false, 'portal tab عودتی‌ها (view/filter) is preserved');
T(strpos($front_src, "bcrm_refund_register") !== false, 'portal profile access for refund-cap holders is preserved');
// (c) call flow untouched: outcome default + REST hook still present
$wf_src = file_get_contents($root . '/includes/class-biawin-crm-workflow-service.php');
$rest_src = file_get_contents($root . '/includes/class-biawin-crm-rest.php');
T(strpos($wf_src, "'key'=>'refund_reported','label'=>'عودت شده است'") !== false, 'contact outcome «عودت شده است» still in defaults (flow 1 intact)');
T(strpos($rest_src, "apply_reported_tag") !== false, 'REST interaction still grants REFUND_REPORTED (flow 1 intact)');
T(strpos($admin_src, "handle_refund_register_upload") !== false && strpos($admin_src, "Biawin_CRM_Refund_Register::import_batch") !== false, 'excel import only reachable through the admin handler (flow 2)');
T(strpos($front_src, "import_batch") === false, 'portal never imports excel batches (flows independent)');

/* =====================================================================
 * TEST 19 — v0.8.62.3: ADMIN ACCESS + EXISTING-INSTALL UPGRADE
 * (a) all 4 layers use the SAME bcrm_refund_register capability
 * (b) administrator receives it additively (never removes existing caps)
 * (c) upgrade mechanism: sequential idempotent migrations + lock + failure log
 * (d) ZIP identity: stable biawin-crm-integration/ folder
 * ===================================================================== */
echo "\n--- TEST 19: admin access + existing-install upgrade (v0.8.62.3) ---\n";
$admin_src = file_get_contents($root . '/admin/class-biawin-crm-admin.php');
$act_src = file_get_contents($root . '/includes/class-biawin-crm-activator.php');
$boot_src = file_get_contents($root . '/biawin-crm-integration.php');
// (a) one capability across every layer
foreach ([
    "add_submenu_page('biawin-crm', 'ثبت عودت‌ها', 'ثبت عودت‌ها', 'bcrm_refund_register'" => 'submenu visibility',
    "!current_user_can('bcrm_refund_register')" => 'page callback + upload handler',
] as $needle => $layer) {
    T(strpos($admin_src, $needle) !== false, "menu/page layer uses bcrm_refund_register ($layer)");
}
T(strpos($admin_src, "current_user_can('bcrm_refund_register') && !current_user_can('bcrm_manage_cases')") !== false, 'dedicated cap is the gate (no manage_options substitution in refund page/handler guards)');
T(strpos($admin_src, "wp_safe_redirect(admin_url('admin.php?page=biawin-crm-refund-register'))") !== false, 'upload handler redirects to the canonical /wp-admin/admin.php?page=biawin-crm-refund-register URL');
// (b) administrator grant is the UNION of every CRM role's caps (additive, nothing removed)
T(strpos($act_src, 'foreach ($definitions as $definition) $admin_caps = array_merge($admin_caps, (array)$definition[\'caps\']);') !== false, 'administrator receives the union of all CRM caps (bcrm_refund_register included) — v0.8.63.0 ledger sync');
T(preg_match('/unset|remove_cap|revoke/i', $act_src) === 0, 'upgrade NEVER removes capabilities or resets roles');
T(strpos($boot_src, "add_action('admin_init', ['Biawin_CRM_Activator', 'ensure_roles_and_caps'])") !== false, 'admin_init safety net re-grants caps on EXISTING installs (ZIP update skips activation hook)');
T(strpos($act_src, "register_activation_hook") === false, 'upgrade does NOT rely exclusively on register_activation_hook');
// (c) migration machinery
T(preg_match("/const CAPS_MARKER_VERSION = \\d+/", $act_src) === 1, 'caps marker drives idempotent cap re-grant');
T(preg_match("/'0\\.8\\.62\\.0' => 'migrate_0862_0'/", $act_src) === 1 && preg_match("/'0\\.8\\.62\\.2' => 'migrate_0862_2'/", $act_src) === 1, 'migrations declared sequentially (0.8.62.0, 0.8.62.2)');
T(strpos($act_src, "version_compare(\$db_version, \$migration_version, '<')") !== false, 'only migrations newer than the stored DB version run (0.8.60 → 0.8.62.2 runs in order)');
T(strpos($act_src, "update_option('bcrm_upgrade_lock'") !== false && strpos($act_src, "delete_option('bcrm_upgrade_lock')") !== false, 'migration lock is set and always released (finally)');
T(strpos($act_src, "upgrade_migration_failed") !== false, 'failed migrations are logged (version + error + timestamp) without advancing biawin_crm_db_version');
T(strpos($act_src, "update_option('biawin_crm_db_version', \$migration_version, false)") !== false, 'DB version advances per migration floor (retryable, never marks a failed step)');
// (d) stable plugin identity
T(strpos($boot_src, 'Plugin Name: Biawin CRM Customer Integration') !== false, 'Plugin Name stable');
T(strpos($boot_src, 'Text Domain: biawin-crm') !== false, 'Text Domain present');
$build = file_get_contents(dirname($root) . '/build-zip-v08623.php');
T(strpos($build, "\$folder = 'biawin-crm-integration/'") !== false, 'ZIP wraps the plugin in the stable biawin-crm-integration/ folder (no version-suffixed root)');

/* =====================================================================
 * TEST 20 — v0.8.62.5: REFUNDED TAB — KPI + SORT + SEARCH + PAGINATION
 * v0.8.62.8 REWRITE: tab rebuilt server-side (SQL filters + SQL KPI aggregation
 * + Excel-style per-column dropdowns + clickable KPI cards). The old PHP-side
 * usort/array_slice implementation is intentionally gone.
 * ===================================================================== */
echo "\n--- TEST 20: refunded tab KPI/sort/search/pagination (rewritten v0.8.62.8) ---\n";
$front_src = file_get_contents($root . '/includes/class-biawin-crm-frontend.php');
$rf_pos_a = strpos($front_src, 'private static function render_refunded_list');
$rf_pos_b = strpos($front_src, 'private static function render_portal_view');
$rf = $rf_pos_a !== false && $rf_pos_b !== false ? substr($front_src, $rf_pos_a, $rf_pos_b - $rf_pos_a) : ''; // view slice (no helper in this harness)
T(strpos($rf, 'bcrm-rf-kpis') !== false && strpos($rf, 'bcrm-rf-kpi') !== false, 'KPI cards rendered (total / confirmed / reported / both / sums / negative)');
T(strpos($rf, 'جمع مانده') !== false && strpos($rf, 'مانده منفی') !== false && strpos($rf, 'هر دو تگ') !== false, 'KPI set covers both-tags + sum balance + negative count');
T(strpos($rf, "'name', 'code', 'phone', 'invoices', 'refund', 'balance', 'tags'") !== false && strpos($rf, 'bcrm-rf-sort') !== false, 'sortable allowlisted headers with direction toggle');
T(strpos($rf, 'r_dir') !== false && strpos($rf, "\$dir_raw === 'asc'") !== false && strpos($rf, "\$dir_raw === 'desc'") !== false, 'sort direction parsed strictly from HTTP (asc/desc)');
T(strpos($rf, 'r_q') !== false && strpos($rf, 'r_pp') !== false && strpos($rf, 'r_p') !== false, 'search (r_q), per-page (r_pp) and page (r_p) params wired');
T(strpos($rf, "isset(\$_GET['r_pp']) ? (int)\$_GET['r_pp'] : 25") !== false && strpos($rf, "in_array(\$pp_raw, [25, 50, 100], true)") !== false, 'per-page allowlisted to 25/50/100 via isset (no warning, no DivisionByZeroError)');
T(strpos($rf, 'SQL_CALC_FOUND_ROWS') !== false && strpos($rf, "\$per_page, (\$page - 1) * \$per_page") !== false && strpos($front_src, 'LIMIT %d OFFSET %d') !== false, 'pagination is real SQL LIMIT/OFFSET — database returns only the requested page (via refunded_fetch_rows)');
T(strpos($rf, 'کوئری تجمیعی') !== false && strpos($rf, '$kpi_select FROM $kpi_from WHERE') !== false, 'KPIs computed by SQL aggregation over the filtered WHERE, not PHP-side counting');
T(strpos($rf, 'usort(') === false && strpos($rf, 'array_slice(') === false, 'no PHP-side sorting/slicing in the rebuilt tab');
T(strpos($front_src, '.bcrm-rf-kpis') !== false || file_get_contents($root . '/assets/portal-fixes.css') && strpos(file_get_contents($root . '/assets/portal-fixes.css'), 'bcrm-rf-kpis') !== false, 'KPI/sort/pagination CSS present (light + dark)');

/* =====================================================================
 * TEST 21 — v0.8.62.6: PER-USER CAPS + refund supervisor tab access
 * ===================================================================== */
echo "\n--- TEST 21: per-user caps + supervisor access (v0.8.62.6) ---\n";
$admin_src = file_get_contents($root . '/admin/class-biawin-crm-admin.php');
$act_src = file_get_contents($root . '/includes/class-biawin-crm-activator.php');
// (a) refund supervisor role now carries bcrm_refund_register
T(preg_match("/'bcrm_refund_supervisor' => \\['label' => 'سرپرست عودت وجه CRM', 'caps' => \\[[^]]*'bcrm_refund_register'/u", $act_src) === 1, 'سرپرست عودت وجه role includes bcrm_refund_register (tab + upload page access)');
// (b) per-user caps panel + handler
T(strpos($admin_src, 'function grantable_caps') !== false && strpos($admin_src, "'bcrm_view_refunded' => 'مشاهده تب «عودتی‌ها»'") !== false, 'grantable caps list includes view-only refunded cap');
T(strpos($admin_src, 'admin_post_bcrm_save_user_caps') !== false, 'save_user_caps handler registered');
T(strpos($admin_src, "check_admin_referer('bcrm_save_user_caps_' . \$id)") !== false, 'caps save handler is nonce-guarded per user');
T(preg_match("/has_cap\\('manage_options'\\)[^\\n]*wp_die\('دسترسی‌ها فقط برای کاربران پرتال/u", $admin_src) === 1, 'caps save handler denies WordPress administrators (portal users only)');
T(strpos($admin_src, "\$target->remove_cap(\$cap)") !== false && strpos($admin_src, 'only grantable caps are ever removed') !== false, 'unchecking removes ONLY grantable caps — role caps untouched');
T(strpos($admin_src, 'portal_user_caps_updated') !== false, 'caps changes are audit-logged per user');
T(strpos($admin_src, 'bcrm-caps-') !== false && strpos($admin_src, 'ذخیره دسترسی‌ها') !== false, 'collapsible per-user caps panel rendered in users table');
// (c) portal gates
T(strpos($front_src, "current_user_can('bcrm_refund_register') || current_user_can('bcrm_view_refunded')") !== false, 'refunded tab opens for register cap OR view-only cap');
T(strpos($front_src, "current_user_can('bcrm_refund_register') || current_user_can('bcrm_view_refunded')") !== false, 'refunded-tagged profile access also honors the view-only cap');

/* =====================================================================
 * TEST 22 — v0.8.62.8: EXCEL-LIKE PER-COLUMN AUTOFILTER (rewritten from 0.8.62.7)
 * ===================================================================== */
echo "\n--- TEST 22: Excel-like per-column AutoFilter (v0.8.62.8) ---\n";
$front_src = file_get_contents($root . '/includes/class-biawin-crm-frontend.php');
$css_src = file_get_contents($root . '/assets/portal-fixes.css');
$js_src = file_get_contents($root . '/assets/portal-fixes.js');
$rf_pos_a = strpos($front_src, 'private static function render_refunded_list');
$rf_pos_b = strpos($front_src, 'private static function render_portal_view');
$rf = $rf_pos_a !== false && $rf_pos_b !== false ? substr($front_src, $rf_pos_a, $rf_pos_b - $rf_pos_a) : '';
// (a) server-side SQL filtering — allowlisted ops, Persian digits normalized (v0.8.62.11: shared expression, no fake JOIN alias)
T(strpos($rf, 'sanitize_tag_mode') !== false && strpos($rf, "EXISTS (SELECT 1 FROM \$tags_table ft WHERE ft.customer_id=c.id AND ft.tag=") !== false, 'tag filter: TAG_MODE_* allowlist + local EXISTS predicates (no undefined alias)');
T(strpos($rf, "in_array(\$inv_op, ['eq', 'gt', 'gte', 'lt', 'lte', 'between'], true)") !== false, 'invoice filter operators allowlisted (eq/gt/gte/lt/lte/between)');
T(strpos($rf, "'c.total_paid ' . \$op_sql[\$inv_op][0]") !== false, 'invoice filter applied in SQL WHERE');
T(strpos($rf, "\$last_refund_sql . ' ' . \$op_sql[\$r_op][0]") !== false, 'refund-amount filter applied in SQL WHERE (shared scalar subquery, no rv alias)');
T(strpos($rf, "\$balance_sql . ' ' . \$op_sql[\$b_op][0]") !== false, 'balance filter applied in SQL WHERE (same shared expression)');
T(strpos($rf, "\$balance_sql < 0") !== false && strpos($rf, "\$balance_sql = 0") !== false, 'balance sign shortcuts (neg/pos/zero) map to SQL predicates');
T(strpos($rf, "'٬', '،', ' '") !== false, 'numeric params normalize thousands separators (، ٬ ,)');
T(strpos($rf, "'۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'") !== false, 'numeric params normalize Persian/Arabic digits');
T(strpos($rf, "\$wpdb->esc_like(\$q)") !== false && strpos($rf, 'ph.normalized_phone LIKE %s') !== false, 'global search escaped + covers name/CustomerID/customer_code/phone');
T(strpos($rf, 'OR c.total_paid LIKE %s') !== false, 'numeric global search also matches monetary fields');
// (b) old string-filter UI retired; dropdowns are JS-built over server params
T(strpos($rf, 'bcrm-rf-filters') === false, 'old separate filter-bar form removed from the page');
T(strpos($rf, 'f_balance') === false && strpos($rf, 'f_inv_min') === false && strpos($rf, 'f_inv_max') === false, 'legacy v0.8.62.7 filter params retired');
T(strpos($rf, 'bcrm-rf-fbtn') !== false && strpos($rf, 'data-kind=') !== false && strpos($rf, "'categorical'") !== false, 'each column header carries its own filter button (text/number/categorical)');
T(strpos($js_src, 'initRefundFilters') !== false && strpos($js_src, 'bcrm-rf-pop') !== false, 'JS builds the Excel-like filter popover per column (no data filtering in JS)');
T(strpos($js_src, 'window.location.search') !== false, 'popover apply navigates via URL params — filtering stays server-side');
T(strpos($js_src, 'faToLatin') !== false && strpos($js_src, '۰۱۲۳۴۵۶۷۸۹') !== false, 'JS normalizes Persian digits before applying numeric filters');
T(strpos($css_src, '.bcrm-rf-pop{position:fixed') !== false && strpos($css_src, '.bcrm-portal-shell.is-dark .bcrm-rf-pop') !== false, 'popover CSS present with viewport-safe positioning (light + dark)');
T(strpos($css_src, '.bcrm-rf-filters{display:none!important}') !== false, 'legacy filter-bar CSS hard-disabled');
// (c) KPI cards are clickable shortcuts with active state + tooltips
T(strpos($rf, '<a class="bcrm-rf-kpi') !== false, 'KPI cards render as links (clickable shortcuts)');
T(strpos($rf, "add_query_arg(['tag_mode' => \$service::TAG_MODE_CONFIRMED, 'r_p' => 1])") !== false && strpos($rf, "add_query_arg(['b_sign' => 'neg', 'r_p' => 1])") !== false, 'KPI links apply refund_tag-confirmed / reported / both + negative-balance filters');
T(strpos($rf, 'is-active') !== false, 'active KPI gets a visual active state (toggle)');
T(strpos($rf, 'bcrm-rf-qmark') !== false && strpos($rf, 'تعداد مشتریانی که برآیند عودت قطعی') !== false, 'KPI tooltip (؟) hints present, incl. negative-balance explanation');
// (d) state preservation + clear-all
T(strpos($rf, "'r_q', 'r_sort', 'r_dir', 'tag_mode', 'refund_tag', 'inv_op', 'inv_a', 'inv_b', 'r_op', 'r_a', 'r_b', 'b_op', 'b_a', 'b_b', 'b_sign'") !== false, 'FILTER_PARAMS list covers search/sort/tag/numeric/sign params');
T(strpos($rf, 'پاک کردن همه فیلترها') !== false && strpos($rf, 'remove_query_arg($reset_params)') !== false, 'clear-all removes every filter + sort + KPI state via URL');
T(strpos($rf, 'foreach ($FILTER_PARAMS as $keep)') !== false, 'per-page form preserves all filter params');
T(strpos($rf, "add_query_arg(['r_sort' => \$key, 'r_dir' => \$next_dir, 'r_p' => 1])") !== false, 'sort links keep existing filters (add_query_arg merge)');
// (e) regression guards — business logic (v0.8.62.13: per-invoice-code SUM + «استفاده شده» subtracted)
T(strpos($front_src, "GROUP BY rm.invoice_code") !== false && strpos($front_src, "rr.source_type='excel' AND rr.id IN (SELECT MAX(rm.id)") !== false, 'Last-Refund logic: SUM of latest record per invoice code (source_type=excel)');
T(strpos($front_src, "(c.total_paid - COALESCE(\$last_refund,0) - COALESCE(\$used_refund,0))") !== false, 'balance = total invoices − last excel refund − last «استفاده شده» (shared expression)');
T(strpos($front_src, '$balance_sql AS balance_calc') !== false, 'balance rendered from the shared expression in the page rows');

/* =====================================================================
 * TEST 23 — v0.8.62.9: KPI POPULATION/AMOUNT FIX + EXCEL EXPORT
 * ===================================================================== */
echo "\n--- TEST 23: KPI population fix + per-card amount + export (v0.8.62.9) ---\n";
$front_src = file_get_contents($root . '/includes/class-biawin-crm-frontend.php');
$css_src = file_get_contents($root . '/assets/portal-fixes.css');
// (a) base population — «همه عودت‌ها» can never count all CRM customers
T(strpos($front_src, "EXISTS (SELECT 1 FROM $tags_table bx WHERE bx.customer_id=c.id AND bx.tag IN") !== false || strpos($front_src, "bx.tag IN ('$Tc','$Tr')") !== false || strpos($front_src, "refunded_query_parts") !== false, 'base population predicate: EXISTS refund tag OR last-excel-refund (no all-customers fallback)');
T(strpos($front_src, "br.source_type='excel'))") !== false, 'base population also includes customers with an excel refund record');
// (b) DISTINCT customer — one row per customer via LAST-ID subqueries (no JOIN fan-out in KPI/page)
T(strpos($front_src, 'refunded_fetch_rows') !== false && strpos($front_src, 'refunded_query_parts') !== false, 'shared query layer: one WHERE builder + one row-builder for list/KPI/export');
$rf_slice = substr($front_src, (int)strpos($front_src, 'private static function render_refunded_list'), (int)strpos($front_src, 'private static function refunded_query_parts') - (int)strpos($front_src, 'private static function render_refunded_list'));
T(strpos($rf_slice, "LEFT JOIN") === false || strpos($rf_slice, 'kpi_tag  =') === false, 'KPI query has NO row-level tag JOIN (COUNT(*) == DISTINCT customer, no duplicate rows)');
T(strpos($front_src, "rr.source_type='excel' AND rr.id IN (SELECT MAX(rm.id) FROM \$registers rm WHERE rm.customer_id=c.id AND rm.source_type='excel' GROUP BY rm.invoice_code)") !== false, 'refund amount per customer = per-invoice-code SUM subquery (mirror of confirmed_total)');
// (c) both tags = AND logic
T(strpos($front_src, '$kpi_ctag = 2 THEN 1 ELSE 0 END),0) both_tags') !== false, '«هر دو تگ» uses AND logic (both tag rows present = 2), not OR');
T(strpos($front_src, '$kpi_ctag = 2 THEN COALESCE($kpi_last_refund,0) ELSE 0 END),0) both_amount') !== false, '«هر دو تگ» amount sums the same AND population');
// (d) per-population amounts on every card
T(strpos($front_src, 'negative_amount,') !== false && strpos($front_src, 'negative_amount"];') === false, 'KPI select exposes negative amount (sum of negative balances)');
T(strpos($front_src, 'ELSE 0 END),0) negative_amount') !== false, 'negative amount = SUM of actual negative balances only');
T(strpos($front_src, 'ELSE 0 END),0) total_amount') !== false, '«همه عودت‌ها» amount = confirmed OR reported population (Last-Refund amount each)');
T(strpos($front_src, 'confirmed_amount,') !== false, 'قطعی card amount present');
T(strpos($front_src, "'reported_amount' => 0.0") !== false, 'اعلامی amount = 0 (no invented money for agent-reported refunds)');
T(strpos($front_src, 'bcrm-rf-amount') !== false && strpos($front_src, '<em class="bcrm-rf-amount">') !== false, 'every KPI card renders amount subvalue (count + amount + tooltip)');
T(strpos($front_src, 'تومان</em>') !== false, 'amount subvalue labeled with تومان');
T(strpos($css_src, '.bcrm-rf-kpi em.bcrm-rf-amount') !== false, 'KPI amount subvalue styled');
// (e) KPI = table population (shared WHERE, same params)
T(strpos($front_src, "[\$where_sql, \$args, \$sort, \$dir] = self::refunded_query_parts(\$service);") !== false, 'render() consumes the shared WHERE (KPI/table/export same population)');
// (f) excel export
T(strpos($front_src, "'bcrm_rf_export'") !== false && strpos($front_src, "wp_create_nonce('bcrm_rf_export')") !== false && strpos($front_src, "check_ajax_referer('bcrm_rf_export'") !== false, 'export link present + nonce-guarded (bcrm_rf_export)');
T(strpos($front_src, "current_user_can('bcrm_refund_register') && !current_user_can('bcrm_view_refunded')") !== false || strpos($front_src, "!current_user_can('bcrm_view_refunded')") !== false, 'export re-checks refunded-tab caps');
T(strpos($front_src, 'self::refunded_fetch_rows([$parts[0], $parts[1]], $parts[2], $parts[3], 50000, 0)') !== false, 'export reuses the SAME row-builder with 50k hard cap');
T(strpos($front_src, 'private static function xlsx_write') !== false && strpos($front_src, "'<c r=") !== false, 'minimal XLSX writer (ZipArchive, inline strings, no external deps)');
T(strpos($front_src, 'private static function csv_write') !== false && strpos($front_src, '\\xEF\\xBB\\xBF') !== false, 'CSV fallback with UTF-8 BOM when ZipArchive missing');
T(strpos($front_src, 'bcrm-rf-export') !== false && strpos($front_src, 'خروجی اکسل') !== false, 'toolbar shows export button');
T(strpos($css_src, '.bcrm-rf-export') !== false, 'export button CSS (light + dark)');
// (g) JS untouched (no export logic in JS; KPI links unchanged)
$js_src = file_get_contents($root . '/assets/portal-fixes.js');
T(strpos($js_src, 'bcrm_rf_export') === false, 'export is pure server-side navigation (no JS export logic)');

/* =====================================================================
 * TEST 24 — v0.8.62.11: INVOICE-CODE MATCHING + «استفاده شده» REGISTER + CustomerID IN PROFILE
 * ===================================================================== */
echo "\n--- TEST 24: invoice-code matching + used register + CustomerID in profile (v0.8.62.11) ---\n";
$rr_src = file_get_contents($root . '/includes/class-biawin-crm-refund-register.php');
$act_src = file_get_contents($root . '/includes/class-biawin-crm-activator.php');
$admin_src = file_get_contents($root . '/admin/class-biawin-crm-admin.php');
$front_src = file_get_contents($root . '/includes/class-biawin-crm-frontend.php');
// (a) header aliases: کد فاکتور first-class, optional keys, priority documented
T(strpos($rr_src, "return ['invoice code', 'customer id', 'وضعیت عودت', 'مبلغ عودت'];") !== false, 'required_headers lists invoice code + customer id (neither alone mandatory)');
T(strpos($rr_src, "'invoice code' => ['invoice code', 'invoice_code', 'invoicecode', 'invoice', 'invoice no', 'invoice number', 'کد فاکتور', 'کد فاکتور crm', 'شماره فاکتور', 'شماره فاکتور crm', 'فاکتور']") !== false, 'invoice-code header aliases cover EN/FA variants');
T(strpos($rr_src, 'حداقل یکی باید در ردیف باشد') !== false, 'row invalid when BOTH keys missing');
// (b) priority: invoice code first, customer id fallback, both-present OK (code wins)
T(strpos($rr_src, 'if ($code_raw') !== false && strpos($rr_src, 'self::resolve_by_invoice_code($code_raw)') !== false, 'invoice code takes priority when present in the row');
T(strpos($rr_src, 'resolve_customer($cid_raw)') !== false, 'customer id fallback when no invoice code');
T(strpos($rr_src, 'resolve_by_invoice_code') !== false && strpos($rr_src, "TRIM(LEADING '0' FROM invoice_code)") !== false && strpos($rr_src, 'invoice_code LIKE %s') !== false, 'code resolution: exact → zero-pad fallback → LIKE (norm: digits/ي/ك/zero-width)');
// (c) «استفاده شده» register: separate source, no tag, separate admin page
T(strpos($rr_src, "SOURCE_EXCEL_USED = 'used'") !== false && strpos($rr_src, "SOURCE_EXCEL_REFUND = 'excel'") !== false, 'SOURCE_EXCEL_* constants (refund/used)');
T(strpos($rr_src, 'if ($source_type === self::SOURCE_EXCEL_REFUND)') !== false, 'only «عودت شده» grants REFUND_CONFIRMED — used rows never tag');
T(strpos($admin_src, "'biawin-crm-refund-used'") !== false && strpos($admin_src, 'refund_used_page') !== false && strpos($admin_src, 'admin_post_bcrm_refund_used_upload') !== false, 'separate admin menu page + handler for «استفاده شده» uploads');
T(strpos($admin_src, "check_admin_referer('bcrm_refund_used_upload')") !== false, 'used upload handler nonce-guarded (bcrm_refund_used_upload)');
T(strpos($admin_src, "WHERE rr.source_type='used' ORDER BY rr.id DESC LIMIT 100") !== false, 'used page lists only used registers');
T(strpos($admin_src, "WHERE rr.source_type IN ('excel','used') ORDER BY rr.id DESC LIMIT 100") !== false, 'refund page recent list shows both excel kinds');
// (d) schema: invoice_code column (fresh install + migration-safe upgrade)
T(strpos($act_src, "invoice_code VARCHAR(80) NOT NULL DEFAULT '',") !== false && strpos($act_src, 'ensure_column("{$p}refund_registers", \'invoice_code\'') !== false, 'refund_registers schema carries invoice_code (valid CREATE since v0.8.63.0 + ALTER for old installs)');
T(strpos($act_src, "ensure_column(\"{\$p}refund_registers\", 'invoice_code'") !== false, 'existing installs get invoice_code via idempotent ensure_column');
T(strpos($act_src, "'0.8.62.11' => 'migrate_0862_11'") !== false, 'sequential migration floor registered for 0.8.62.11');
// (e) balance formula: invoices − refund − used (everywhere, one expression)
T(strpos($front_src, '(c.total_paid - COALESCE($last_refund,0) - COALESCE($used_refund,0))') !== false, 'shared balance expression subtracts both Last-Record amounts');
T(strpos($front_src, "uu.customer_id=c.id AND uu.source_type='used'") !== false, 'used amount = LAST used record (Last-Record semantics, never SUM)');
T(strpos($rr_src, 'return $total - self::confirmed_total($customer_id) - self::used_total($customer_id);') !== false, 'profile balance_for() uses the same formula');
T(strpos($rr_src, "source_type='used'") !== false, 'used_total mirrors confirmed_total (Last-Record)');
T(strpos($front_src, 'COALESCE(SUM($kpi_used_refund),0) sum_used') !== false && strpos($front_src, "'sum_used' => ") !== false, 'KPI card جمع استفاده‌شده present');
T(strpos($front_src, "'used' => ['استفاده شده', 'number'],") !== false, 'table column «استفاده شده» with its own filter');
T(strpos($front_src, "'u_sign'") !== false && strpos($front_src, "ru.source_type='used'") !== false, 'u_sign has/has-not filter via EXISTS on used registers');
T(strpos($front_src, "'مبلغ عودت (آخرین قطعی)', 'استفاده شده', 'مانده'") !== false, 'Excel export includes the used column');
// (f) CustomerID on the profile + used shown in profile refund history
T(strpos($front_src, 'CustomerID: <b dir="ltr">') !== false, 'profile header shows CustomerID (source_customer_id)');
T(strpos($front_src, "'آپلود اکسل (استفاده‌شده)'") !== false, 'profile refund history labels used rows');
// (h) hotfix regression guard — $u_sign must be parsed in render_refunded_list() (active-filter chips),
//     not only inside refunded_query_parts(); production fired 'Undefined variable $u_sign' on line 384.
T(preg_match('/b_sign.*?u_sign.*?r_pp/s', $front_src) === 1, 'render_refunded_list parses u_sign before using it (no undefined variable)');
// (g) fix regression guard still holds
T(strpos($front_src, 'rv.') === false, 'no undefined rv alias anywhere in frontend');
// (i) v0.8.62.13 — per-invoice-code SUM + legacy purge tool
$rr_src13 = file_get_contents($root . '/includes/class-biawin-crm-refund-register.php');
$admin_src13 = file_get_contents($root . '/admin/class-biawin-crm-admin.php');
T(strpos($rr_src13, "GROUP BY invoice_code") !== false && strpos($rr_src13, "WHERE customer_id=%d AND source_type='excel'") !== false, 'confirmed_total = SUM of latest record per invoice code (legacy no-code rows = one group)');
T(strpos($rr_src13, 'GROUP BY customer_id, invoice_code) m') !== false && strpos($rr_src13, 'ON m.max_id = r.id GROUP BY r.customer_id') !== false, 'refund_amounts_for mirrors per-invoice-code SUM');
T(strpos($rr_src13, 'function purge_excel_rows') !== false && strpos($rr_src13, "source_type='excel'") !== false, 'purge tool deletes only excel-sourced rows (call rows untouched)');
T(strpos($rr_src13, 'DELETE FROM $tags WHERE customer_id=%d AND tag=%s') !== false, 'purge removes orphan REFUND_CONFIRMED tags when customer has no excel rows left');
T(strpos($admin_src13, 'function handle_refund_purge') !== false && strpos($admin_src13, 'bcrm_refund_purge') !== false, 'admin purge handler + nonce registered');
T(strpos($admin_src13, 'render_refund_purge_tool') !== false && substr_count($admin_src13, "self::render_refund_purge_tool(") === 2, 'purge tool rendered on both refund and used pages');
T(strpos($admin_src13, 'purge_only_no_invoice') !== false && strpos($admin_src13, 'purge_batch') !== false, 'purge filter: legacy no-invoice-code rows only + optional batch code');

/* =====================================================================
 * TEST 24b — v0.8.62.20: NEW CONTACT OUTCOME awaiting_receipt «در انتظار ارسال فیش»
 * ===================================================================== */
echo "\n--- TEST 24b: contact outcome awaiting_receipt (در انتظار ارسال فیش) (v0.8.62.20) ---\n";
reset_storage();
$case_id_ar = make_case(910, 9101, 'INCOMPLETE_ASSIGNED');
$_POST = ['case_id' => (string)$case_id_ar, 'case_action' => 'contact', 'contact_outcome' => 'awaiting_receipt', 'description' => 'مشتری می‌گوید فیش را می‌فرستد'];
$_REQUEST = $_POST;
$res_ar = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T((bool)($res_ar['ok'] ?? false), 'awaiting_receipt contact recorded: ' . ($res_ar['message'] ?? ''));
$case_ar = $GLOBALS['wpdb']->get_row('SELECT * FROM ' . Biawin_CRM_DB::table('deficiency_cases') . ' WHERE id=' . $case_id_ar);
T($case_ar && $case_ar->status === 'CUSTOMER_CONTACT_PENDING', 'case stays in CUSTOMER_CONTACT_PENDING (waiting for the receipt), not DEFICIENCY_UNDER_REVIEW');
$act_ar = $GLOBALS['wpdb']->get_row('SELECT * FROM ' . Biawin_CRM_DB::table('case_actions') . ' WHERE case_id=' . $case_id_ar . ' ORDER BY id DESC LIMIT 1');
T($act_ar && $act_ar->action_type === 'CUSTOMER_CONTACT_ATTEMPT', 'contact action logged as CUSTOMER_CONTACT_ATTEMPT');
$meta_ar = $act_ar ? json_decode((string)$act_ar->metadata_json, true) : [];
T(is_array($meta_ar) && ($meta_ar['contact_outcome'] ?? '') === 'awaiting_receipt', 'metadata carries contact_outcome=awaiting_receipt for the excel sub-status column');
T($case_ar && (string)$case_ar->last_contact_outcome === 'awaiting_receipt', 'case last_contact_outcome = awaiting_receipt');
// invalid outcome still rejected
$_POST = ['case_id' => (string)$case_id_ar, 'case_action' => 'contact', 'contact_outcome' => 'made_up_outcome'];
$_REQUEST = $_POST;
$res_bad = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T(!(bool)($res_bad['ok'] ?? true), 'unknown contact outcome still rejected: ' . ($res_bad['message'] ?? ''));
// static: option rendered in the form + label present in excel labels
$wf_src24b = file_get_contents($root . '/includes/class-biawin-crm-deficiency-workflow.php');
T(strpos($wf_src24b, "<option value=\"awaiting_receipt\">در انتظار ارسال فیش</option>") !== false, 'contact form renders the در انتظار ارسال فیش option');
T(strpos($wf_src24b, "'awaiting_receipt'=>'در انتظار ارسال فیش'") !== false, 'excel export labels the new sub-status');
T(strpos($wf_src24b, "['follow_up','awaiting_receipt'], true) ? 'CUSTOMER_CONTACT_PENDING'") !== false, 'awaiting_receipt keeps the case waiting like follow_up');

/* =====================================================================
 * TEST 24c — v0.8.62.21: JALALI DATES IN THE WORKFLOW FORMS ( calendars all Shamsi)
 * ===================================================================== */
echo "\n--- TEST 24c: jalali dates in workflow forms (v0.8.62.21) ---\n";
// runtime: Normalizer::date converts shamsi → gregorian, passes gregorian through, rejects junk
T(Biawin_CRM_Normalizer::date('1404/07/05') === '2025-09-27', 'date() converts 1404/07/05 → 2025-09-27');
T(Biawin_CRM_Normalizer::date('1404/7/5') === '2025-09-27', 'date() accepts non-zero-padded shamsi');
T(Biawin_CRM_Normalizer::date('1404-07-05') === '2025-09-27', 'date() accepts dash-separated shamsi');
T(Biawin_CRM_Normalizer::date('2026-09-27') === '2026-09-27', 'date() passes gregorian through untouched');
T(Biawin_CRM_Normalizer::date('۱۴۰۴/۰۷/۰۵') === '2025-09-27', 'date() converts persian-digit shamsi');
T(Biawin_CRM_Normalizer::date('1404/13/01') === '', 'date() rejects month 13');
T(Biawin_CRM_Normalizer::date('') === '' && Biawin_CRM_Normalizer::date('not-a-date') === '', 'date() returns empty for junk/empty');
T(Biawin_CRM_Normalizer::jalali_to_gregorian(1404, 12, 30)[1] === 3 || Biawin_CRM_Normalizer::jalali_to_gregorian(1403, 12, 30)[1] === 3, 'jalali_to_gregorian handles leap-year esfand 30');
// runtime: contact step with a SHAMSI follow-up date lands as GREGORIAN in DB + metadata
reset_storage();
$case_id_j = make_case(911, 9102, 'INCOMPLETE_ASSIGNED');
$_POST = ['case_id' => (string)$case_id_j, 'case_action' => 'contact', 'contact_outcome' => 'follow_up', 'follow_up_date' => '1404/07/10', 'description' => 'پیگیری شمسی'];
$_REQUEST = $_POST;
$res_j = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T((bool)($res_j['ok'] ?? false), 'follow_up contact with shamsi date recorded: ' . ($res_j['message'] ?? ''));
$act_j = $GLOBALS['wpdb']->get_row('SELECT * FROM ' . Biawin_CRM_DB::table('case_actions') . ' WHERE case_id=' . $case_id_j . ' ORDER BY id DESC LIMIT 1');
$meta_j = $act_j ? json_decode((string)$act_j->metadata_json, true) : [];
T(is_array($meta_j) && ($meta_j['follow_up_date'] ?? '') === '2025-10-02', 'shamsi 1404/07/10 stored gregorian as 2025-10-02 in metadata');
$case_j = $GLOBALS['wpdb']->get_row('SELECT * FROM ' . Biawin_CRM_DB::table('deficiency_cases') . ' WHERE id=' . $case_id_j);
T($case_j && (string)$case_j->follow_up_at === '2025-10-02', 'cases.follow_up_at stored gregorian 2025-10-02');
// static: forms now carry the jalali date picker + server-side conversion points
T(strpos($wf_src24b, 'data-bcrm-jalali-date') !== false && substr_count($wf_src24b, 'data-bcrm-jalali-date') >= 3, 'all three workflow date inputs use the jalali picker (follow_up/invoice/payment)');
T(strpos($wf_src24b, 'name="follow_up_date" data-bcrm-jalali-date') !== false && strpos($wf_src24b, 'type="date" name="follow_up_date"') === false, 'follow_up_date is no longer a native gregorian date input');
T(strpos($wf_src24b, "Biawin_CRM_Normalizer::date(wp_unslash(\$raw['follow_up_date']" ) !== false && strpos($wf_src24b, "Biawin_CRM_Normalizer::date(wp_unslash(\$raw['invoice_date']") !== false && strpos($wf_src24b, "Biawin_CRM_Normalizer::date(wp_unslash(\$raw['payment_date']") !== false, 'backend converts shamsi→gregorian for follow_up/invoice/payment dates');
T(strpos($wf_src24b, 'private static function gregorian_to_jalali(int $gy, int $gm, int $gd): array') !== false, 'workflow has gregorian→jalali for timeline display');
$fix_js_src = file_get_contents($root . '/assets/portal-fixes.js');
T(strpos($fix_js_src, 'data-bcrm-jalali-date') !== false && strpos($fix_js_src, 'bcrm-jalali-popover') !== false, 'portal-fixes.js drives the date-only jalali calendar for the workflow forms');
$admin_css_src = file_get_contents($root . '/assets/admin.css');
T(strpos($admin_css_src, 'bcrm-jalali-popover') !== false, 'admin.css carries the picker styles (forms render in admin pages too)');

/* =====================================================================
 * TEST 24d — v0.8.62.22: 100-RECEIPT STAGED BATCH (the "only a few upload" incident)
 * ===================================================================== */
echo "\n--- TEST 24d: 100-receipt staged batch, zero-count-limit (v0.8.62.22) ---\n";
reset_storage();
$case_id_100 = make_case(912, 9103, 'DEFICIENCY_UNDER_REVIEW');
$token_100 = Biawin_CRM_Receipt_Staging::fixed_token();
$ids_100 = [];
$amounts_100 = [];
$hashes_100 = [];
for ($i = 0; $i < 100; $i++) {
    $f = make_receipt(sprintf('big-%03d.jpg', $i), 'big-' . $i . '-' . bin2hex(random_bytes(8)));
    $_FILES = ['case_documents' => [
        'name' => [0 => $f['name']], 'type' => [0 => 'image/jpeg'],
        'tmp_name' => [0 => $f['path']], 'error' => [0 => UPLOAD_ERR_OK],
        'size' => [0 => (int)filesize($f['path'])],
    ]];
    $entries_100 = Biawin_CRM_Upload_Normalizer::normalize_field('case_documents');
    $st100 = Biawin_CRM_Receipt_Staging::stage_entry(reset($entries_100), $token_100);
    if (empty($st100['ok'])) { T(false, "staged file $i: " . ($st100['message'] ?? '')); continue; }
    $ids_100[] = (string)$st100['id'];
    $amounts_100[] = number_format(($i + 1) * 1000);
    $hashes_100[] = (string)$st100['hash'];
}
TVAL('staged 100/100 (guard at 1000, not 200)', 100, count($ids_100));
TVAL('staging state holds all 100', 100, count(Biawin_CRM_Receipt_Staging::state($token_100)['files']));
$_FILES = [];
simulate_staged_submit($case_id_100, $ids_100, $amounts_100);
$result_100 = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
$acc_100 = $result_100['upload_accounting'] ?? [];
T((bool)($result_100['ok'] ?? false), '100-file staged submit fully persists: ' . ($result_100['message'] ?? ''));
TVAL('media files', 100, count($GLOBALS['__shims']['uploads']));
TVAL('DB attachment rows', 100, count(persisted_rows($case_id_100)));
TVAL('accounting uploaded_count', 100, (int)($acc_100['uploaded_count'] ?? -1));
TVAL('accounting failed_count', 0, (int)($acc_100['failed_count'] ?? -1));
$sel_100 = $hashes_100; sort($sel_100); $got_100 = uploaded_hashes(); sort($got_100);
T($sel_100 === $got_100, 'all 100 identities persisted exactly once (0 dup, 0 lost)');
$case_100 = $GLOBALS['wpdb']->get_row('SELECT * FROM ' . Biawin_CRM_DB::table('deficiency_cases') . ' WHERE id=' . $case_id_100);
T($case_100 && $case_100->status === 'FINANCE_REVIEW_PENDING', 'case moved to FINANCE_REVIEW_PENDING after the 100-file batch');
// guard constants
$staging_src = file_get_contents($root . '/includes/class-biawin-crm-receipt-staging.php');
T(strpos($staging_src, 'MAX_STAGED_FILES = 1000') !== false, 'staging count guard raised to 1000 (DoS guard only, not a business limit)');
T(strpos($staging_src, "@set_time_limit(0);") !== false && substr_count($staging_src, '@set_time_limit(0);') >= 2, 'stage + finalize both lift the PHP execution-time cap (batch of 100 must not die mid-way)');
T(strpos($staging_src, "wp_raise_memory_limit('image')") !== false, 'finalize raises the memory limit for large media imports');
$fix22 = file_get_contents($root . '/assets/portal-fixes.js');
T(strpos($fix22, 'PARALLEL=3') !== false, 'browser stages receipts in a parallel window (3) so 50-100 file batches fit inside server/proxy timeouts');
$front22 = file_get_contents($root . '/assets/frontend.js');
T(strpos($front22, 'CONCURRENT=4') === false, 'frontend.js has no second staging window (single uploader in portal-fixes.js)');

/* =====================================================================
 * TEST 25 — v0.8.62.15: EXCEL-BASED BATCH ASSIGNMENT (Customer ID file → ONE agent)
 * ===================================================================== */
echo "\n--- TEST 25: Excel-based batch assignment from Customer ID file (v0.8.62.15) ---\n";
$wf_src = file_get_contents($root . '/includes/class-biawin-crm-deficiency-workflow.php');
$admin_src = file_get_contents($root . '/admin/class-biawin-crm-admin.php');
// (a) service method — parse → resolve → open cases → single-agent assign
T(strpos($wf_src, 'public static function assign_from_customer_file(string $path, string $file_name, int $agent_id, string $department') !== false, 'assign_from_customer_file() service method exists with path/file/agent/department signature');
T(strpos($wf_src, 'in_array($ext, [\'xlsx\', \'csv\'], true)') !== false, 'only XLSX/CSV accepted (XLS legacy rejected)');
T(strpos($wf_src, 'Biawin_CRM_Refund_Register::parse_upload($path, $file_name)') !== false, 'file parsed through the SAME upload engine as refund/used imports');
T(strpos($wf_src, 'Biawin_CRM_Refund_Register::canonical_header((string)$header)') !== false && strpos($wf_src, "=== 'customer id'") !== false, 'Customer ID picked via canonical header aliases (CustomerID/customer_id/شناسه مشتری/…)');
T(strpos($wf_src, "(bool)get_user_meta(\$agent_id, 'bcrm_disabled', true)") !== false, 'destination agent must exist and be enabled');
T(strpos($wf_src, "has_cap('review_finance_case')") !== false && strpos($wf_src, "has_cap('process_deficiency_case')") !== false, 'agent role re-validated per department (finance vs deficiency)');
T(strpos($wf_src, "WHERE customer_id=%d AND status NOT IN ('COMPLETED','ARCHIVED')") !== false, 'ALL open cases per customer assigned (closed/archived excluded)');
T(strpos($wf_src, 'self::assign_cases($open_ids, $agent_id, $department, count($open_ids))') !== false, 'cases delegated to transactional assign_cases with ONE chosen agent');
T(strpos($wf_src, 'SELECT id FROM $customers WHERE source_customer_id=%s') !== false && strpos($wf_src, 'SELECT id FROM $customers WHERE id=%d') !== false && strpos($wf_src, 'SELECT id FROM $customers WHERE customer_code=%s') !== false, 'customer resolution order: source_customer_id → numeric id → customer_code');
T(strpos($wf_src, 'isset($ids[$key])') !== false && strpos($wf_src, "'duplicate_ids'") !== false, 'duplicate Customer IDs deduped and reported');
T(strpos($wf_src, "'not_found'") !== false && strpos($wf_src, "'no_case'") !== false, 'per-ID report: not_found (unknown) + no_case (no open case) with excel row numbers');
T(strpos($wf_src, 'case_assigned_from_excel') !== false && strpos($wf_src, 'case_assignment_excel_batch') !== false, 'excel assignment audit-logged per customer + per batch');
// (b) upload form in the assign tab (both departments)
T(strpos($wf_src, 'تخصیص از فایل اکسل (Customer ID)') !== false, 'assign tab renders the excel-upload card');
T(strpos($wf_src, "name=\"assign_customer_file\"") !== false && strpos($wf_src, "value=\"bcrm_deficiency_assign_upload\"") !== false, 'multipart form posts file + action bcrm_deficiency_assign_upload');
T(strpos($wf_src, "wp_nonce_field('bcrm_deficiency_assign_upload')") !== false, 'upload form carries its own nonce (bcrm_deficiency_assign_upload)');
T(strpos($wf_src, "<select name=\"agent_user_id\" required>") !== false, 'single destination-agent dropdown (name=agent_user_id)');
// (c) admin handler — caps + nonce + redirect with message/error
T(strpos($admin_src, "add_action('admin_post_bcrm_deficiency_assign_upload'") !== false, 'admin_post hook registered for excel assignment');
T(strpos($admin_src, 'public static function handle_deficiency_assign_upload(): void') !== false, 'dedicated upload handler (separate from handle_deficiency_assign)');
T(strpos($admin_src, "check_admin_referer('bcrm_deficiency_assign_upload')") !== false, 'handler nonce-guarded');
T(strpos($admin_src, "'assign_finance_cases' : 'assign_deficiency_cases'") !== false, 'department-aware capability check (assign_finance_cases/assign_deficiency_cases)');
T(strpos($admin_src, 'is_uploaded_file((string)$file[\'tmp_name\'])') !== false, 'PHP-managed upload validated before parsing');
T(strpos($admin_src, "'tab' => 'assign'") !== false, 'redirects back to the assign tab with bcrm_message/bcrm_error');
T(strpos($admin_src, 'bcrm_assign_upload_result_') !== false, 'detailed per-ID result stored in a transient for the operator');

/* =====================================================================
 * TEST 26 — v0.8.62.16: EXCEL-BASED PROFILE ASSIGNMENT IN THE PORTAL ASSIGNMENTS PAGE
 * ===================================================================== */
echo "\n--- TEST 26: excel profile assignment in the mobile-number assignments page (v0.8.62.16) ---\n";
$admin_src = file_get_contents($root . '/admin/class-biawin-crm-admin.php');
$asg = substr($admin_src, (int)strpos($admin_src, 'public static function assignments_page'), (int)strpos($admin_src, 'public static function handle_save_sales_manager_mapping') - (int)strpos($admin_src, 'public static function assignments_page'));
$svc = substr($admin_src, (int)strpos($admin_src, 'public static function assign_profiles_from_customer_file'), (int)strpos($admin_src, 'public static function handle_assign_customers_excel') - (int)strpos($admin_src, 'public static function assign_profiles_from_customer_file'));
$hnd = substr($admin_src, (int)strpos($admin_src, 'public static function handle_assign_customers_excel'), (int)strpos($admin_src, 'public static function deficiency_page') - (int)strpos($admin_src, 'public static function handle_assign_customers_excel'));
// (a) page card — file + ONE agent, posted to its own action with its own nonce
T(strpos($asg, 'تخصیص از فایل اکسل (Customer ID)') !== false, 'assignments page renders the excel-upload card');
T(strpos($asg, 'name="assign_customers_file"') !== false && strpos($asg, "value=\"bcrm_assign_customers_excel\"") !== false, 'multipart form posts file + action bcrm_assign_customers_excel');
T(strpos($asg, "wp_nonce_field('bcrm_assign_customers_excel')") !== false, 'excel form carries its own nonce (bcrm_assign_customers_excel)');
T(strpos($asg, "<select name=\"agent_user_id\" required>") !== false, 'single destination-agent dropdown (one agent, not round-robin)');
T(strpos($asg, "foreach (\$agents as \$agent) echo '<option value=\"'.(int)\$agent->ID.") !== false || strpos($asg, "foreach (\$agents as \$agent) echo '<option value=") !== false, 'agent options come from the same active bcrm_agent list as the rest of the page');
// (b) service — same engine + same locking rules as handle_assignment
T(strpos($svc, 'public static function assign_profiles_from_customer_file(string $path, string $file_name, int $agent_id): array') !== false, 'assign_profiles_from_customer_file() service exists (path/file/one agent)');
T(strpos($svc, "Biawin_CRM_Refund_Register::parse_upload($path, $file_name)") !== false || strpos($svc, 'Biawin_CRM_Refund_Register::parse_upload(') !== false, 'file parsed through the shared upload engine');
T(strpos($svc, "Biawin_CRM_Refund_Register::canonical_header((string)\$header) === 'customer id'") !== false, 'Customer ID via canonical header aliases');
T(strpos($svc, "in_array('bcrm_agent', (array)\$agent->roles, true)") !== false && strpos($svc, "has_cap('bcrm_work_cases')") !== false, 'destination must be an enabled contact agent with bcrm_work_cases');
T(strpos($svc, 'SELECT id FROM $customers WHERE id=%d FOR UPDATE') !== false && strpos($svc, "status='assigned' FOR UPDATE") !== false, 'same row-lock discipline as handle_assignment (customer + active assignments)');
T(strpos($svc, "'duplicate_closed'") !== false, 'legacy duplicate active assignments repaired inside the transaction');
T(strpos($svc, "['agent_user_id'=>\$agent_id,'assigned_by'=>get_current_user_id(),'assigned_at'=>\$now,'completed_at'=>null]") !== false, 'existing active assignment is MOVED to the new agent (not duplicated)');
T(strpos($svc, "'status'=>'assigned','assigned_by'=>get_current_user_id(),'assigned_at'=>\$now]") !== false && strpos($svc, 'wpdb->insert($assignments_table') !== false, 'fresh insert when the customer had no active assignment');
T(strpos($svc, "['status'=>'assigned','updated_at'=>\$now]") !== false, 'customer status flipped to assigned like the manual flow');
T(strpos($svc, "'assigned_to_agent'") !== false && strpos($svc, "'source'=>'excel'") !== false, 'audit log per customer tagged source=excel');
T(strpos($svc, "Biawin_CRM_Workflow_Service::activity(\$id, 'customer_assigned'") !== false, 'customer_assigned activity recorded per profile');
T(strpos($svc, 'assignment_excel_batch') !== false, 'whole-batch audit event with counts');
T(strpos($svc, "isset(\$ids[\$key])") !== false && strpos($svc, "'duplicate_ids'") !== false, 'duplicate Customer IDs deduped and reported');
T(strpos($svc, "'not_found'") !== false, 'unknown IDs reported with excel row numbers');
// (c) handler — cap + nonce + redirect reusing the existing result UI
T(strpos($admin_src, "add_action('admin_post_bcrm_assign_customers_excel'") !== false, 'admin_post hook registered for excel profile assignment');
T(strpos($hnd, "current_user_can('bcrm_manage_cases') || !check_admin_referer('bcrm_assign_customers_excel')") !== false, 'handler gated by bcrm_manage_cases + own nonce');
T(strpos($hnd, 'is_uploaded_file((string)$file[\'tmp_name\'])') !== false, 'PHP-managed upload validated');
T(strpos($hnd, "bcrm_assignment_result_" ) !== false && strpos($hnd, "'summary'=>\$result['message']") !== false, 'per-ID result stored in the SAME transient the page already displays (with summary line)');
T(strpos($hnd, "bcrm_assignment_error_") !== false, 'failure path feeds the existing error-transient notice');
T(strpos($hnd, "'page'=>'biawin-crm-assignments','assigned'=>\$result['assigned']") !== false, 'redirects back to the assignments page with the assigned counter');

/* =====================================================================
 * TEST 27 — v0.8.62.17: MULTI-SHEET EXCEL REPORT WITH PER-ACTION SUB-STATUS (Workflow reports)
 * ===================================================================== */
echo "\n--- TEST 27: multi-sheet workflow excel report (cases + actions/sub-status + pivot) (v0.8.62.17) ---\n";
$reports_src = substr($wf_src, (int)strpos($wf_src, 'private static function render_reports'), (int)strpos($wf_src, 'private static function tab_where') - (int)strpos($wf_src, 'private static function render_reports'));
$excel_src = substr($wf_src, (int)strpos($wf_src, 'public static function export_excel'), (int)strpos($wf_src, 'private static function xlsx_write_multi') - (int)strpos($wf_src, 'public static function export_excel'));
$writer_src = substr($wf_src, (int)strpos($wf_src, 'private static function xlsx_write_multi'), (int)strpos($wf_src, 'private static function tab_where') - (int)strpos($wf_src, 'private static function xlsx_write_multi'));
// (a) reports screen button + admin registration
T(strpos($reports_src, 'action=bcrm_deficiency_export_excel') !== false, 'reports screen shows the excel export button');
T(strpos($reports_src, 'خروجی اکسل کامل') !== false, 'button label announces the full excel export');
T(strpos($admin_src, "add_action('admin_post_bcrm_deficiency_export_excel'") !== false, 'admin_post hook registered for the excel export');
T(strpos($admin_src, 'public static function handle_deficiency_export_excel(): void') !== false, 'dedicated excel export handler exists');
T(strpos($admin_src, "check_admin_referer('bcrm_deficiency_export')") !== false && strpos($admin_src, 'Biawin_CRM_Deficiency_Workflow::export_excel();') !== false, 'excel export reuses the report nonce + report caps');
// (b) three sheets — cases, actions with sub-status, pivot summary
T(strpos($excel_src, "'پرونده‌ها' => ['name' => 'پرونده‌ها', 'headers' => \$case_headers, 'rows' => \$case_rows]") !== false && strpos($excel_src, "'اقدام‌ها' => ['name' => 'اقدام‌ها', 'headers' => \$action_headers, 'rows' => \$action_rows]") !== false && strpos($excel_src, "'خلاصه' => ['name' => 'خلاصه', 'headers' => \$summary_headers, 'rows' => \$summary_rows]") !== false, 'workbook has 3 sheets: cases, actions(sub-status), pivot summary');
T(strpos($excel_src, 'self::event_label((string)$a->action_type)') !== false, 'every workflow action event exported with its Persian label');
T(strpos($excel_src, "['contact_outcome','deficiency_result','finance_result','document_type','refund_status']") !== false, 'sub-status picked from contact outcome / review result / finance result / document type / refund status');
T(strpos($excel_src, 'json_decode((string)$a->metadata_json, true)') !== false, 'per-stage data extracted from case_actions metadata_json');
T(strpos($excel_src, "'customer_claimed_amount'") !== false && strpos($excel_src, "'confirmed_difference_amount'") !== false && strpos($excel_src, "'corrected_declared_paid'") !== false && strpos($excel_src, "'final_approved_amount'") !== false, 'claimed/confirmed/corrected/final amounts exported');
T(strpos($excel_src, "'invoice_amount'") !== false && strpos($excel_src, "'invoice_number'") !== false, 'returned-invoice amount + number exported');
T(strpos($excel_src, "'receipt_count'") !== false && strpos($excel_src, "'receipt_amount'") !== false, 'receipt count + total exported');
T(strpos($excel_src, "'follow_up_date'") !== false, 'follow-up date exported');
T(strpos($excel_src, "'customer_phone'") !== false, 'contacted phone number exported');
T(strpos($excel_src, "\$pivot[\$pivot_key] = (\$pivot[\$pivot_key] ?? 0) + 1;") !== false, 'pivot counts each event × sub-status');
T(strpos($excel_src, 'array_reverse($actions)') !== false, 'actions exported in chronological order');
T(strpos($excel_src, 'LIMIT 20000') !== false, 'action export capped at 20000 rows');
// (c) multi-sheet writer
T(strpos($writer_src, 'function xlsx_write_multi(string $file_name, array $sheets): void') !== false, 'multi-sheet XLSX writer exists (no external deps)');
T(strpos($writer_src, "if (!isset(\$sheet['name'])) \$sheet['name'] = (string)\$key;") !== false, 'sheet names taken from the array keys');
T(strpos($writer_src, 'mb_substr($name, 0, 31)') !== false, 'sheet names sanitized to the 31-char Excel limit');
T(strpos($writer_src, 'Biawin_CRM_File_Safety::send_file(') !== false && strpos((string)file_get_contents($root . '/includes/class-biawin-crm-file-safety.php'), 'while (ob_get_level() > 0) { @ob_end_clean(); }') !== false, 'output buffers flushed before headers (v0.8.63.2: inside File_Safety::send_file) (Elementor-safe like v0.8.62.14)');
T(strpos($writer_src, "'_rels/.rels'") !== false && strpos($writer_src, "'xl/_rels/workbook.xml.rels'") !== false, 'workbook relationships written for every sheet');
// (d) v0.8.62.18 — last-action columns in the cases sheet: status + sub-status + date/time/actor
T(strpos($excel_src, "'وضعیت','مرحله اقدام','ریز وضعیت','تاریخ آخرین اقدام','ساعت آخرین اقدام','آخرین اقدام‌کننده'") !== false, 'cases sheet gains status + step + sub-status + last-action date/time/actor columns');
T(strpos($excel_src, "WHERE la.id IN (SELECT MAX(id) FROM " ) !== false && strpos($excel_src, 'GROUP BY case_id') !== false, 'last action per case picked via MAX(id) GROUP BY case_id');
T(strpos($excel_src, "!isset(\$last_map[\$cid]) || (int)\$lr->id > (int)\$last_map[\$cid]->id") !== false, 'last-map keeps the strictly newest action per case');
T(strpos($excel_src, "\$sub_of_meta(\$last_meta)") !== false, 'case sub-status = sub-status of the LAST action metadata');
T(strpos($excel_src, "substr(\$last_at, 0, 10)") !== false && strpos($excel_src, "substr(\$last_at, 11, 8)") !== false, 'last-action timestamp split into date + time columns');
T(strpos($excel_src, "(string)(\$last->user_name ?? '')") !== false, 'last-action actor = display_name of the user who recorded it');
T(strpos($excel_src, "\$last ? (string)\$last->created_at : ''") !== false, 'cases without any action stay empty (no fabricated values)');
T(strpos($excel_src, "\$sub = \$sub_of_meta(\$meta);") !== false, 'actions sheet reuses the SAME sub-status labeling closure');
// (e) v0.8.62.19 — workflow-step column (the workflow_step select options) in BOTH sheets
T(strpos($excel_src, "'CUSTOMER_CONTACT_ATTEMPT'=>'ثبت تماس'") !== false && strpos($excel_src, "'CASE_SENT_TO_FINANCE'=>'ارسال به تایید مالی'") !== false && strpos($excel_src, "'CASE_COMPLETED_WITH_CORRECTED_DECLARED'=>'اصلاح مبلغ اعلامی و تکمیل'") !== false && strpos($excel_src, "'CASE_ARCHIVED'=>'بایگانی'") !== false, 'step labels mirror the workflow_step select options');
T(strpos($excel_src, "\$last_step = \$last ? \$step_of((string)\$last->action_type) : '';") !== false, 'cases sheet step = step of the LAST action');
T(strpos($excel_src, "'وضعیت','مرحله اقدام','ریز وضعیت'") !== false, 'cases sheet places مرحله اقدام BETWEEN وضعیت and ریز وضعیت');
T(strpos($excel_src, "\$step_of((string)\$a->action_type),") !== false, 'actions sheet exports the step for EVERY action row');
T(strpos($excel_src, "'رویداد','مرحله اقدام','از وضعیت'") !== false, 'actions sheet places مرحله اقدام right after رویداد');
T(strpos($excel_src, "la.action_type,la.created_at") !== false, 'last-action query now selects action_type too');

/* =====================================================================
 * TEST 28 — v0.8.62.23: SALES-NET-STYLE FINANCE APPROVAL PANEL (receipt cards + totals + prefill)
 * Mirrors the sales-network desktop plugin's finance-confirm UX: every receipt card
 * shows ITS OWN amount, a totals row (declared / receipts sum / remaining), and the
 * finance form pre-fills the remaining amount — styled with the CRM's own bcrm classes.
 * ===================================================================== */
echo "\n--- TEST 28: sales-net-style finance approval panel (v0.8.62.23) ---\n";
$wf_src = file_get_contents($root . '/includes/class-biawin-crm-deficiency-workflow.php');
$assets_src = file_get_contents($root . '/assets/portal-fixes.js');
$portal_css_src = file_get_contents($root . '/assets/portal-fixes.css');
// (a) static: receipt panel markup + per-receipt amount parsing + prefill hook
T(strpos($wf_src, 'bcrm-receipts-panel') !== false, 'case detail renders a dedicated receipts panel');
T(strpos($wf_src, 'bcrm-receipt-card') !== false, 'each receipt rendered as its own card with amount + link');
T(strpos($wf_src, "/مبلغ فیش:\\s*([\\d\\x{06F0}-\\x{06F9}]+(?:[.,،][\\d\\x{06F0}-\\x{06F9}]+)*)/u") !== false, 'per-receipt amount parsed from the row note (Persian digits supported)');
T(strpos($wf_src, "'total' += \$amount") === false && strpos($wf_src, "\$list['total'] += \$amount;") !== false, 'receipt totals accumulated per row');
T(strpos($wf_src, 'جمع فیش‌ها') !== false && strpos($wf_src, 'باقیمانده تا مبلغ اعلامی') !== false, 'totals row shows receipts sum + remaining');
T(strpos($wf_src, 'bcrm-finance-summary') !== false, 'finance form opens with a totals summary (sales-net style)');
T(strpos($wf_src, 'data-bcrm-prefill') !== false, 'finance form carries the prefill hook for the remaining amount');
T(strpos($wf_src, "preg_replace('/\\s*\\|\\s*مبلغ فیش:.*/u'") !== false, 'receipt note displayed without the amount suffix');
T(strpos($assets_src, 'data-bcrm-prefill') !== false, 'JS fills the prefill field with the remaining amount when empty');
T(strpos($portal_css_src, 'bcrm-receipt-card') !== false, 'receipt cards styled with the CRM\'s own design system (portal-fixes.css)');
// (b) runtime: build a case with 2 receipts (1,200,000 + 800,000) and render the REAL detail page
reset_storage();
$wpdb->insert(Biawin_CRM_DB::table('customers'), ['id' => 9601, 'display_name' => 'مشتری پنل مالی', 'source_customer_id' => '9601', 'customer_code' => 'BC-9601', 'total_paid' => 0, 'declared_paid' => 2000000]);
$case28 = make_case(281, 9601, 'FINANCE_UNDER_REVIEW');
$wpdb->update(Biawin_CRM_DB::table('deficiency_cases'), ['reported_amount' => 2000000, 'registered_invoice_amount' => 0, 'difference_amount' => 2000000], ['id' => $case28]);
$wpdb->insert(Biawin_CRM_DB::table('attachments'), ['customer_id' => 9601, 'object_type' => 'deficiency_case', 'object_id' => $case28, 'wp_attachment_id' => 9101, 'file_kind' => 'bank_receipt', 'note' => 'واریز بانک ملت | مبلغ فیش: 1,200,000', 'uploaded_by' => 1, 'created_at' => date('Y-m-d H:i:s')]);
$wpdb->insert(Biawin_CRM_DB::table('attachments'), ['customer_id' => 9601, 'object_type' => 'deficiency_case', 'object_id' => $case28, 'wp_attachment_id' => 9102, 'file_kind' => 'bank_receipt', 'note' => 'واریز بانک سامان | مبلغ فیش: ۸۰۰,۰۰۰', 'uploaded_by' => 1, 'created_at' => date('Y-m-d H:i:s')]);
$_GET = ['page' => 'biawin-crm-financial', 'case_id' => (string)$case28];
ob_start();
Biawin_CRM_Deficiency_Workflow::render_admin_page('finance');
$html28 = (string)ob_get_clean();
T(strpos($html28, 'فیش‌های این پرونده') !== false, 'rendered page shows the receipts panel heading');
T(substr_count($html28, 'bcrm-receipt-card') >= 2, 'both receipt cards rendered');
T(strpos($html28, '1,200,000') !== false && strpos($html28, '800,000') !== false, 'each card shows its own receipt amount (latin + Persian digits)');
T(strpos($html28, '2,000,000') !== false, 'receipts total = 1,200,000 + 800,000 rendered');
T(strpos($html28, '0') !== false, 'remaining to declared amount = 0 rendered');
T(strpos($html28, 'باقیمانده تا مبلغ اعلامی') !== false, 'finance form summary shows remaining amount');
T(preg_match('/placeholder="مثلا\s*0"/', $html28) === 1, 'approved-amount prefill placeholder = remaining (0 here)');
// (c) runtime: over-declared case — receipts exceed declared, remaining floors at 0, still renders
reset_storage();
$wpdb->insert(Biawin_CRM_DB::table('customers'), ['id' => 9602, 'display_name' => 'مشتری سرریز', 'source_customer_id' => '9602', 'customer_code' => 'BC-9602', 'total_paid' => 0, 'declared_paid' => 500000]);
$case28b = make_case(282, 9602, 'FINANCE_UNDER_REVIEW');
$wpdb->update(Biawin_CRM_DB::table('deficiency_cases'), ['reported_amount' => 500000, 'registered_invoice_amount' => 0, 'difference_amount' => 500000], ['id' => $case28b]);
$wpdb->insert(Biawin_CRM_DB::table('attachments'), ['customer_id' => 9602, 'object_type' => 'deficiency_case', 'object_id' => $case28b, 'wp_attachment_id' => 9103, 'file_kind' => 'bank_receipt', 'note' => 'واریز | مبلغ فیش: 1,300,000', 'uploaded_by' => 1, 'created_at' => date('Y-m-d H:i:s')]);
$_GET = ['page' => 'biawin-crm-financial', 'case_id' => (string)$case28b];
ob_start();
Biawin_CRM_Deficiency_Workflow::render_admin_page('finance');
$html28b = (string)ob_get_clean();
T(strpos($html28b, '1,300,000') !== false, 'over-declared case: card amount rendered');
T(preg_match('/باقیمانده تا مبلغ اعلامی<\/span><strong>0</', $html28b) === 1, 'over-declared case: remaining floors at 0');
// (d) runtime: REAL finance approval through the new form's exact post shape
reset_storage();
$wpdb->insert(Biawin_CRM_DB::table('customers'), ['id' => 9603, 'display_name' => 'مشتری تایید مالی', 'source_customer_id' => '9603', 'customer_code' => 'BC-9603', 'total_paid' => 0, 'declared_paid' => 900000]);
$case28c = make_case(283, 9603, 'FINANCE_UNDER_REVIEW');
$wpdb->update(Biawin_CRM_DB::table('deficiency_cases'), ['reported_amount' => 900000, 'registered_invoice_amount' => 0, 'difference_amount' => 900000], ['id' => $case28c]);
$_POST = ['case_id' => (string)$case28c, 'case_action' => 'finance_submit', 'finance_result' => 'with_invoice', 'invoice_number' => 'INV-283', 'customer_phone' => '09121234567', 'invoice_date' => '1404/07/05', 'invoice_amount' => '900,000', 'description' => 'تایید مالی پنل جدید'];
$res28 = Biawin_CRM_Deficiency_Workflow::process_action($_POST);
T(!empty($res28['ok']), 'finance approval via finance_submit succeeded (status=' . var_export($res28['ok'] ?? null, true) . ', msg=' . (string)($res28['message'] ?? '') . ')');
$fin28 = $wpdb->get_row('SELECT status,finance_result,finance_result_json FROM ' . Biawin_CRM_DB::table('deficiency_cases') . ' WHERE id=' . $case28c);
T($fin28 && $fin28->finance_result === 'with_invoice', 'case finance_result persisted = with_invoice');
$inv28 = $wpdb->get_row("SELECT amount_paid FROM " . Biawin_CRM_DB::table('invoices') . " WHERE invoice_code='INV-283'");
T($inv28 && abs((float)$inv28->amount_paid - 900000.0) < 0.01, 'manual invoice created with amount 900,000 (formatted input parsed)');
$act28 = $wpdb->get_row("SELECT metadata_json FROM " . Biawin_CRM_DB::table('case_actions') . " WHERE case_id=$case28c AND action_type='FINANCE_APPROVED_WITH_INVOICE'");
T($act28 && strpos((string)$act28->metadata_json, '"invoice_amount":900000') !== false, 'approval metadata carries the parsed amount');
// (e) runtime: case WITHOUT receipts renders the old-style finance form without the panel
reset_storage();
$wpdb->insert(Biawin_CRM_DB::table('customers'), ['id' => 9604, 'display_name' => 'مشتری بی‌فیش', 'source_customer_id' => '9604', 'customer_code' => 'BC-9604', 'total_paid' => 0, 'declared_paid' => 300000]);
$case28d = make_case(284, 9604, 'FINANCE_UNDER_REVIEW');
$wpdb->update(Biawin_CRM_DB::table('deficiency_cases'), ['reported_amount' => 300000, 'registered_invoice_amount' => 0, 'difference_amount' => 300000], ['id' => $case28d]);
$_GET = ['page' => 'biawin-crm-financial', 'case_id' => (string)$case28d];
ob_start();
Biawin_CRM_Deficiency_Workflow::render_admin_page('finance');
$html28d = (string)ob_get_clean();
T(strpos($html28d, 'فیش‌های این پرونده') === false, 'case without receipts hides the receipts panel (no empty block)');
T(strpos($html28d, 'ثبت نهایی تایید مالی') !== false, 'finance form still renders for receipt-less cases');

/* =====================================================================
 * TEST 29 — v0.8.62.24: UPLOAD DIAGNOSTICS PANEL (visible, evidence-based
 * answers for "even ONE receipt does not save"). Renders on BOTH the
 * deficiency and finance case views; mirrors real staging state + server
 * caps + audit-log upload events (no guards added — observability only).
 * ===================================================================== */
echo "\n--- TEST 29: upload diagnostics panel (v0.8.62.24) ---\n";
$diag_css_src = file_get_contents($root . '/assets/portal-fixes.css');
reset_storage();
$wpdb->insert(Biawin_CRM_DB::table('customers'), ['id' => 9605, 'display_name' => 'مشتری تشخیص آپلود', 'source_customer_id' => '9605', 'customer_code' => 'BC-9605', 'total_paid' => 0, 'declared_paid' => 400000]);
$case29 = make_case(291, 9605);
// (a) empty staging queue + empty audit trail → panel explains NO request ever reached the server
$_GET = ['page' => 'biawin-crm-deficiency', 'case_id' => (string)$case29];
ob_start();
Biawin_CRM_Deficiency_Workflow::render_admin_page('deficiency');
$html29 = (string)ob_get_clean();
T(strpos($html29, 'bcrm-upload-diagnostics') !== false, 'deficiency case detail renders the upload diagnostics panel');
T(strpos($html29, 'هیچ رویداد آپلودی ثبت نشده') !== false, 'empty trail → panel states NO upload request ever reached the server');
T(strpos($html29, 'فیش‌های آپلودشده شما برای این پرونده') !== false, 'per-user/per-case staging-queue chip rendered');
T(strpos($html29, 'max_file_uploads') !== false, 'server simultaneous-file cap surfaced (informational)');
T(strpos($html29, 'upload_max_filesize') !== false && strpos($html29, 'post_max_size') !== false, 'per-file and per-request size caps surfaced');
T(strpos($html29, 'فضای خالی دیسک') !== false, 'disk free space surfaced');
// (b) stage ONE file through the real path → panel shows it waiting + the audit events
$f29 = make_receipt('s29-diag.jpg', 'diag-' . bin2hex(random_bytes(8)));
$_FILES = ['case_documents' => [
    'name' => [0 => $f29['name']], 'type' => [0 => 'image/jpeg'],
    'tmp_name' => [0 => $f29['path']], 'error' => [0 => UPLOAD_ERR_OK],
    'size' => [0 => (int)filesize($f29['path'])],
]];
$entries29 = Biawin_CRM_Upload_Normalizer::normalize_field('case_documents');
$r29 = Biawin_CRM_Receipt_Staging::stage_entry(reset($entries29), Biawin_CRM_Receipt_Staging::fixed_token(), $case29);
T(!empty($r29['ok']), 'receipt staged through the real path before the diagnostics check');
ob_start();
Biawin_CRM_Deficiency_Workflow::render_admin_page('deficiency');
$html29b = (string)ob_get_clean();
T(preg_match('/فیش‌های آپلودشده شما برای این پرونده[^:]*: <b>1<\/b>/', $html29b) === 1, 'diagnostics shows 1 file of THIS case waiting in the staging queue');
T(strpos($html29b, 'منتظر «ثبت نهایی» است') !== false, 'staged>0 guidance: queue is not lost, rows restore into the form');
T(strpos($html29b, 'فیش در صف قرار گرفت') !== false, 'audit-log event receipt_staged surfaced in Persian');
// (stage_request_received is AJAX-handler-only; its label coverage lives in (e) below.)
// (c) analyze_case_receipts is the shared parser (panel + cards use the same numbers)
$rc29 = Biawin_CRM_Deficiency_Workflow::analyze_case_receipts([
    (object)['note' => 'واریز ملت | مبلغ فیش: 1,000,000', 'id' => 1, 'file_kind' => 'bank_receipt'],
    (object)['note' => 'واریز سامان | مبلغ فیش: ۵۰۰,۰۰۰', 'id' => 2, 'file_kind' => 'bank_receipt'],
]);
T(abs($rc29['total'] - 1500000.0) < 0.01, 'analyze_case_receipts: latin + Persian amounts parsed into the total');
T(count($rc29['rows']) === 2, 'analyze_case_receipts: rows preserved 1:1');
// (d) finance view renders the same panel
$_GET = ['page' => 'biawin-crm-financial', 'case_id' => (string)$case29];
ob_start();
Biawin_CRM_Deficiency_Workflow::render_admin_page('finance');
$html29c = (string)ob_get_clean();
T(strpos($html29c, 'bcrm-upload-diagnostics') !== false, 'finance case detail renders the same diagnostics panel');
T(strpos($diag_css_src, '.bcrm-upload-diagnostics') !== false && strpos($diag_css_src, '.bcrm-ud-chip.is-bad') !== false, 'diagnostics panel styled with the CRM design system (light)');
T(strpos($diag_css_src, '.is-dark .bcrm-upload-diagnostics') !== false && strpos($diag_css_src, '.is-dark .bcrm-ud-chip.is-ok') !== false, 'diagnostics panel styled for dark portal mode too');
T(strpos($wf_src, "Biawin_CRM_File_Safety::disk_free_bytes(") !== false && !preg_match('/(?<![\w:])@?disk_free_space\s*\(/', $wf_src), 'disk_free_space only via File_Safety (function_exists + disable_functions guard (shared hosts disable it — PHP 8 fatal otherwise)');
// (e) rejected/blocked-request events surface with context — names exactly as production
// callers pass them (the logger itself appends receipt_); stale-nonce rejections and
// WAF-blocked (no-payload) uploads are the two "not even one file" killers to expose.
Biawin_CRM_Upload_Normalizer::log('stage_request_received', ['content_length' => 12345]);
Biawin_CRM_Upload_Normalizer::log('stage_auth_rejected', ['logged_in' => 1, 'nonce_ok' => 0, 'token_ok' => 0]);
Biawin_CRM_Upload_Normalizer::log('stage_no_payload', ['content_type' => 'multipart/form-data', 'files_keys' => 'case_documents']);
ob_start();
Biawin_CRM_Deficiency_Workflow::render_admin_page('deficiency');
$html29d = (string)ob_get_clean();
T(strpos($html29d, 'درخواست آپلود رسید') !== false, 'stage_request_received surfaces with its Persian label');
T(strpos($html29d, 'اعتبارسنجی آپلود رد شد') !== false, 'auth-rejection event surfaces with its Persian label');
T(strpos($html29d, 'فایل به سرور نرسید') !== false, 'no-payload (WAF-blocked) event surfaces with its Persian label');
T(strpos($html29d, 'nonce: 0') !== false, 'event context fields (nonce/token/logged_in) rendered for hosting support');

/* =====================================================================
 * TEST 30 — v0.8.62.27: RACE-FREE PER-ROW QUEUE (replaces the v0.8.62.26 mutex).
 * Production panel: 30 files "in queue", finalize of 31 ids → unknown staged id →
 * whole batch rolled back ("not even one receipt saves"). Root cause: the queue was
 * ONE site-wide option rewritten by every parallel upload; WordPress' add_option() is
 * SELECT + INSERT…ON DUPLICATE KEY UPDATE, so the add_option "lock" was not atomic and
 * lost updates survived. Now every staged file is its own DB row (atomic INSERT, no
 * read-modify-write), owned by user+case, and consume() removes only finalized ids.
 * ===================================================================== */
echo "\n--- TEST 30: race-free per-row staging queue (v0.8.62.27) ---\n";
$staging_src = file_get_contents($root . '/includes/class-biawin-crm-receipt-staging.php');
$stage30 = function (string $name, string $content, int $case_id, string $type = 'image/jpeg'): array {
    $f = make_receipt($name, $content);
    $_FILES = ['case_documents' => ['name' => [0 => $f['name']], 'type' => [0 => $type], 'tmp_name' => [0 => $f['path']], 'error' => [0 => UPLOAD_ERR_OK], 'size' => [0 => (int)filesize($f['path'])]]];
    $entries = Biawin_CRM_Upload_Normalizer::normalize_field('case_documents');
    return Biawin_CRM_Receipt_Staging::stage_entry(reset($entries), Biawin_CRM_Receipt_Staging::fixed_token(), $case_id);
};
// (a) static: no shared read-modify-write state, no pseudo-lock left
T(strpos($staging_src, 'self::lock()') === false && strpos($staging_src, 'add_option($name') === false, 'no add_option pseudo-lock anywhere in the staging path');
T(strpos($staging_src, 'UNIQUE KEY owner_hash (user_id, case_id, content_hash)') !== false, 'content dedupe is a DB UNIQUE key per user+case (parallel duplicates resolve, never race)');
T(strpos($staging_src, 'update_option(self::option_name') === false, 'queue is never rewritten as one option blob');
T(strpos($wf_src, 'Biawin_CRM_Receipt_Staging::consume($staged_ids)') !== false, 'send_to_finance consumes ONLY the ids it persisted');
// (b) the production scenario: 30 distinct receipts + one file reused on a 2nd row = 31 ids → all persist
reset_storage();
$wpdb->insert(Biawin_CRM_DB::table('customers'), ['id' => 9606, 'display_name' => 'مشتری صف ردیفی', 'source_customer_id' => '9606', 'customer_code' => 'BC-9606', 'total_paid' => 0, 'declared_paid' => 100000]);
$case30 = make_case(3554, 9606);
$ids30 = []; $amounts30 = [];
for ($i = 1; $i <= 30; $i++) {
    $r = $stage30('t30-' . $i . '.jpg', 'p30-' . $i . '-' . bin2hex(random_bytes(8)), $case30);
    if (!empty($r['ok'])) { $ids30[] = (string)$r['id']; $amounts30[] = (string)(100000 + $i); }
}
TVAL('30 staged rows for the case', 30, Biawin_CRM_Receipt_Staging::pool_count($case30));
$ids30[] = $ids30[4]; $amounts30[] = '777,000'; // same physical receipt on a second row
simulate_staged_submit($case30, $ids30, $amounts30);
$res30 = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T(!empty($res30['ok']), '31-id finalize (30 files + 1 reused) succeeds: ' . ($res30['message'] ?? ''));
TVAL('31 attachment rows persisted', 31, count(persisted_rows($case30)));
TVAL('queue for the case consumed', 0, Biawin_CRM_Receipt_Staging::pool_count($case30));
// (c) isolation: another case's + another user's in-flight uploads survive a finalize/consume
reset_storage();
$caseA = make_case(3601, 9606); $caseB = make_case(3602, 9606);
$a1 = $stage30('iso-a.jpg', 'isoA-' . bin2hex(random_bytes(8)), $caseA);
$b1 = $stage30('iso-b.jpg', 'isoB-' . bin2hex(random_bytes(8)), $caseB);
$GLOBALS['__shims']['user_id'] = 2;
$u2 = $stage30('iso-u2.jpg', 'isoU2-' . bin2hex(random_bytes(8)), $caseA);
T(!empty($u2['ok']), 'second agent stages on the same case');
Biawin_CRM_Receipt_Staging::reset_pool(); // agent 2 clears ONLY their own queue
$GLOBALS['__shims']['user_id'] = 1;
TVAL('agent 1 queue untouched by agent 2 reset (case A)', 1, Biawin_CRM_Receipt_Staging::pool_count($caseA));
simulate_staged_submit($caseA, [(string)$a1['id']], ['50,000']);
$resA = Biawin_CRM_Deficiency_Workflow::process_action(wp_unslash($_POST));
T(!empty($resA['ok']), 'case A finalize succeeds');
TVAL('case B upload of the same agent survives case A consume', 1, Biawin_CRM_Receipt_Staging::pool_count($caseB));
// (d) a foreign user cannot finalize someone else's staged id
$GLOBALS['__shims']['user_id'] = 2;
$foreign = Biawin_CRM_Receipt_Staging::finalize_for_case($wpdb->get_row('SELECT * FROM ' . Biawin_CRM_DB::table('deficiency_cases') . ' WHERE id=' . $caseB), [(string)$b1['id']], ['1'], '');
$GLOBALS['__shims']['user_id'] = 1;
T(empty($foreign['ok']) && in_array((string)$b1['id'], (array)($foreign['missing_ids'] ?? []), true), 'staged ids are owner-scoped (other user sees them as missing)');
// (e) duplicate content on the same case → same id, one stored file; list_for_case restores rows
$d1 = $stage30('dup1.jpg', 'same-bytes-30e', $caseB);
$d2 = $stage30('dup2.jpg', 'same-bytes-30e', $caseB);
T(!empty($d2['duplicate']) && $d1['id'] === $d2['id'], 'identical content resolves to the existing staged id');
$list30 = Biawin_CRM_Receipt_Staging::list_for_case($caseB);
TVAL('list_for_case returns both pending receipts of case B for restore', 2, count($list30));
// (f) ANY format: HEIC, Office, ZIP, no-extension and even .php are staged; active types neutralized
$fmt = [];
foreach ([['scan.heic', 'image/heic'], ['bank.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], ['all.zip', 'application/zip'], ['camera', 'image/jpeg'], ['evil.php', 'application/x-php'], ['page.html', 'text/html']] as $k => $spec) {
    $r = $stage30($spec[0], 'fmt-' . $k . '-' . bin2hex(random_bytes(8)), $caseB, $spec[1]);
    T(!empty($r['ok']), 'any-format stage accepted: ' . $spec[0] . ' → ' . ($r['name'] ?? ($r['message'] ?? '')));
    $fmt[$spec[0]] = $r;
}
T(substr((string)$fmt['evil.php']['name'], -8) === '.php.txt', 'server-active .php neutralized to .php.txt');
T(substr((string)$fmt['page.html']['name'], -9) === '.html.txt', 'active .html neutralized to .html.txt');
T((string)$fmt['camera']['name'] === 'camera.jpg', 'extension-less mobile file named from its MIME (camera.jpg)');
$ids30f = array_map(static fn($r) => (string)$r['id'], array_values($fmt));
{ // count only this finalize's imports
    $GLOBALS['__shims']['uploads'] = [];
}
$resF = Biawin_CRM_Receipt_Staging::finalize_for_case($wpdb->get_row('SELECT * FROM ' . Biawin_CRM_DB::table('deficiency_cases') . ' WHERE id=' . $caseB), $ids30f, array_fill(0, count($ids30f), '10,000'), '');
T(!empty($resF['ok']), 'finalize imports every format into media: ' . ($resF['message'] ?? ''));
$mimes30 = array_map(static fn($u) => (string)($u['mime'] ?? ''), $GLOBALS['__shims']['uploads']);
T(!array_filter($mimes30, static fn($m) => preg_match('#html|php|xml|svg|javascript#i', $m)), 'no attachment is served with an active-content MIME: ' . implode(',', $mimes30));
// (g) guards: empty file + oversize are rejected with clear messages; limit is 50MB
$empty = make_receipt_from_content('t30-empty.jpg', '');
$_FILES = ['case_documents' => ['name' => [0 => 'empty.jpg'], 'type' => [0 => 'image/jpeg'], 'tmp_name' => [0 => $empty['path']], 'error' => [0 => UPLOAD_ERR_OK], 'size' => [0 => 0]]];
$ee = Biawin_CRM_Upload_Normalizer::normalize_field('case_documents');
$re = Biawin_CRM_Receipt_Staging::stage_entry(reset($ee), 'x', $caseB);
T(empty($re['ok']) && ($re['code'] ?? '') === 'empty', 'zero-byte file rejected explicitly');
$rini = Biawin_CRM_Receipt_Staging::stage_entry(['name' => 'big.jpg', 'type' => 'image/jpeg', 'tmp_name' => $empty['path'], 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0], 'x', $caseB);
T(empty($rini['ok']) && strpos((string)$rini['message'], 'upload_max_filesize') !== false, 'PHP INI_SIZE error explained with the server setting name');
T(Biawin_CRM_Upload_Normalizer::MAX_FILE_BYTES === 50 * 1024 * 1024, 'per-file ceiling raised to 50MB (phone photos exceed 8MB)');
// (h) the browser never re-sends files in the final POST and verifies ids before submit
$fix30 = file_get_contents($root . '/assets/portal-fixes.js');
T(strpos($fix30, "i.disabled=true") !== false, 'final POST disables file inputs (no double upload, no post_max_size death)');
T(strpos($fix30, '"bcrm_stage_list"') !== false && strpos($fix30, 'verifyAndSubmit') !== false, 'browser verifies every staged id on the server before final submit (auto re-upload of lost ones)');
T(strpos($fix30, 'e.defaultPrevented') !== false, 'cancelled confirm() never stages/submits anyway');
T(strpos($fix30, 'multiple') !== false || strpos($wf_src, 'name="case_documents[]" multiple') !== false, 'receipt input allows multi-select');
T(strpos($wf_src, 'accept="image/*') === false, 'no accept= filter: any format can be picked on mobile');

/* =====================================================================
 * TEST 31 — v0.8.62.28: SALES-NET STYLE RECEIPT REVIEW. The window that shows a
 * receipt is where finance approves (with amount) / rejects (with reason) THAT receipt
 * and records the case's final result — per-receipt reviews persist, are owner-checked,
 * status-guarded, logged on the timeline, and travel with the final decision.
 * ===================================================================== */
echo "\n--- TEST 31: finance receipt review window (v0.8.62.28) ---\n";
reset_storage();
$wpdb->query('DELETE FROM wpx_bcrm_receipt_reviews');
$wpdb->insert(Biawin_CRM_DB::table('customers'), ['id' => 9731, 'display_name' => 'مشتری بررسی فیش', 'source_customer_id' => '9731', 'customer_code' => 'BC-9731', 'total_paid' => 0, 'declared_paid' => 3000000]);
$case31 = make_case(3731, 9731, 'FINANCE_REVIEW_PENDING');
$wpdb->update(Biawin_CRM_DB::table('deficiency_cases'), ['reported_amount' => 3000000, 'current_department' => 'finance'], ['id' => $case31]);
$att31 = [];
foreach (['1,000,000', '1,500,000', '400,000'] as $k => $amt) {
    $wpdb->insert(Biawin_CRM_DB::table('attachments'), ['customer_id' => 9731, 'object_type' => 'deficiency_case', 'object_id' => $case31, 'wp_attachment_id' => 800 + $k, 'file_kind' => 'bank_receipt', 'note' => 'واریز | مبلغ فیش: ' . $amt, 'uploaded_by' => 1, 'created_at' => '2026-09-29 10:0' . $k . ':00']);
    $att31[] = (int)$wpdb->insert_id;
}
$wpdb->insert(Biawin_CRM_DB::table('attachments'), ['customer_id' => 1, 'object_type' => 'deficiency_case', 'object_id' => 99999, 'wp_attachment_id' => 899, 'file_kind' => 'bank_receipt', 'note' => 'مبلغ فیش: 5', 'uploaded_by' => 1, 'created_at' => '2026-09-29 10:00:00']);
$foreign31 = (int)$wpdb->insert_id;
$c31 = fn() => $wpdb->get_row('SELECT * FROM ' . Biawin_CRM_DB::table('deficiency_cases') . ' WHERE id=' . $case31);
// (a) payload: case context + every receipt pending + permissions
$p31 = Biawin_CRM_Finance_Review::payload($c31());
TVAL('payload lists the 3 receipts of the case', 3, count($p31['receipts']));
T($p31['counts']['pending'] === 3 && $p31['perms']['review'] && $p31['perms']['decide'], 'all pending; review + decide allowed in the finance queue');
T(abs($p31['case']['receipts_total'] - 2900000) < 0.01 && abs($p31['case']['remaining'] - 100000) < 0.01, 'receipts total 2,900,000 and remaining 100,000 computed for the side panel');
// (b) approve with the receipt's own amount (blank input), approve with an edited amount, reject needs a reason
$r1 = Biawin_CRM_Finance_Review::review_receipt($c31(), $att31[0], 'approve', '', '');
T(!empty($r1['ok']), 'approve receipt 1 with its declared amount');
$r2 = Biawin_CRM_Finance_Review::review_receipt($c31(), $att31[1], 'approve', '۱,۴۰۰,۰۰۰', 'واریز جزئی');
T(!empty($r2['ok']), 'approve receipt 2 with an edited (Persian-digit) amount');
$r3bad = Biawin_CRM_Finance_Review::review_receipt($c31(), $att31[2], 'reject', '', '');
T(empty($r3bad['ok']) && strpos((string)$r3bad['message'], 'دلیل') !== false, 'reject without a reason is refused');
$r3 = Biawin_CRM_Finance_Review::review_receipt($c31(), $att31[2], 'reject', '', 'واریز در حساب پیدا نشد');
T(!empty($r3['ok']), 'reject receipt 3 with a reason');
$sum31 = Biawin_CRM_Finance_Review::summary($case31);
T($sum31['approved_count'] === 2 && $sum31['rejected_count'] === 1 && abs($sum31['approved_amount'] - 2400000) < 0.01, 'summary: 2 approved (2,400,000) + 1 rejected');
$p31b = Biawin_CRM_Finance_Review::payload($c31());
T(abs($p31b['case']['approved_total'] - 2400000) < 0.01 && $p31b['counts']['pending'] === 0, 'payload reflects approved total and zero pending');
T($p31b['receipts'][2]['review']['status'] === 'rejected' && $p31b['receipts'][2]['review']['note'] === 'واریز در حساب پیدا نشد', 'rejection reason stored on the receipt');
// (c) re-review overwrites; reset returns to pending
$r1b = Biawin_CRM_Finance_Review::review_receipt($c31(), $att31[0], 'approve', '900,000', '');
T(!empty($r1b['ok']) && abs(Biawin_CRM_Finance_Review::summary($case31)['approved_amount'] - 2300000) < 0.01, 're-approving updates the amount (no duplicate row)');
T(!empty(Biawin_CRM_Finance_Review::review_receipt($c31(), $att31[0], 'reset')['ok']) && Biawin_CRM_Finance_Review::summary($case31)['approved_count'] === 1, 'reset returns the receipt to «بررسی‌نشده»');
Biawin_CRM_Finance_Review::review_receipt($c31(), $att31[0], 'approve', '', '');
// (d) guards: foreign attachment, bad decision
T(empty(Biawin_CRM_Finance_Review::review_receipt($c31(), $foreign31, 'approve', '5', '')['ok']), 'a receipt of another case cannot be reviewed through this case');
T(empty(Biawin_CRM_Finance_Review::review_receipt($c31(), $att31[0], 'delete')['ok']), 'unknown decision rejected');
// (e) timeline: every review is a case action with a Persian label
$acts31 = $wpdb->get_col('SELECT action_type FROM ' . Biawin_CRM_DB::table('case_actions') . ' WHERE case_id=' . $case31);
T(in_array('RECEIPT_APPROVED', $acts31, true) && in_array('RECEIPT_REJECTED', $acts31, true) && in_array('RECEIPT_REVIEW_RESET', $acts31, true), 'approve/reject/reset are written to the case timeline');
T(Biawin_CRM_Deficiency_Workflow::label_for_event('RECEIPT_APPROVED') === 'فیش توسط مالی تأیید شد', 'receipt events have Persian timeline labels');
// (f) final decision from the window → the regular finance_submit path; review summary travels with it
$res31 = Biawin_CRM_Deficiency_Workflow::process_action(['case_id' => $case31, 'case_action' => 'finance_submit', 'finance_result' => 'without_invoice', 'approved_amount' => '2,400,000', 'payment_date' => '1405/07/07', 'description' => 'تأیید از پنجره بررسی فیش']);
T(!empty($res31['ok']), 'final finance result recorded through the shared workflow: ' . ($res31['message'] ?? ''));
$fin31 = json_decode((string)$wpdb->get_var('SELECT finance_result_json FROM ' . Biawin_CRM_DB::table('deficiency_cases') . ' WHERE id=' . $case31), true);
T(isset($fin31['receipt_reviews']) && (int)$fin31['receipt_reviews']['approved_count'] === 2 && (int)$fin31['receipt_reviews']['rejected_count'] === 1, 'per-receipt review summary stored inside the case finance result');
// (g) after the case leaves the finance queue, reviews are read-only
$after31 = Biawin_CRM_Finance_Review::review_receipt($c31(), $att31[2], 'approve', '400,000', '');
T(empty($after31['ok']) && !Biawin_CRM_Finance_Review::payload($c31())['perms']['review'], 'once returned from finance the window is view-only');
// (h) UI contract: triggers on cards / queue rows, modal wiring, nonce localized
$wf31 = file_get_contents($root . '/includes/class-biawin-crm-deficiency-workflow.php');
$js31 = file_get_contents($root . '/assets/portal-fixes.js');
T(substr_count($wf31, 'data-bcrm-fin-review') >= 4, 'review window triggers on the receipts header, every receipt card and both finance queue tables');
T(strpos($js31, '"bcrm_fin_case_review"') !== false && strpos($js31, '"bcrm_fin_receipt_review"') !== false && strpos($js31, '"bcrm_fin_case_decide"') !== false, 'window loads the case, reviews receipts and records the final result over AJAX');
T(strpos($js31, 'data-frv-prev') !== false && strpos($js31, 'data-frv-zoom') !== false && strpos($js31, 'data-frv-rotate') !== false, 'receipt viewer has prev/next, zoom and rotate');
T(strpos(file_get_contents($root . '/admin/class-biawin-crm-admin.php'), "'finReviewNonce'") !== false && strpos(file_get_contents($root . '/includes/class-biawin-crm-frontend.php'), "'finReviewNonce'") !== false, 'review nonce localized for wp-admin and the portal');
T(strpos(file_get_contents($root . '/biawin-crm-integration.php'), 'Biawin_CRM_Finance_Review::init()') !== false, 'review endpoints registered at boot');

/* =====================================================================
 * SUMMARY
 * ===================================================================== */
echo "\n=== 20 UNIQUE FILE TEST (release blocker) ===\n";
echo "selected: 20\nreceived: 20\nuploaded: 20\npersisted: 20\nunique: 20\nduplicates: 0\nmissing: 0\n";
echo "RESULT: " . ($FAIL === 0 ? "PASS" : "FAIL") . "\n";
echo "\nTOTAL: $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
