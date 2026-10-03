<?php
/**
 * Real-WordPress update simulation for Biawin CRM v0.8.63.0 (Phase 1).
 * Each step is a separate PHP process = a separate WordPress request.
 * Usage: php sim.php <install|old_activate|old_request|customize|request|rerun|post|units|snapshot NAME|verify>
 */
$step = $argv[1] ?? '';
$out_dir = __DIR__ . '/sim-out';
if (!is_dir($out_dir)) mkdir($out_dir);
$_SERVER['HTTP_HOST'] = 'bcrm-upgrade.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_NAME'] = 'bcrm-upgrade.test';

if ($step === 'install') {
    define('WP_INSTALLING', true);
    require __DIR__ . '/wp-load.php';
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $r = wp_install('Biawin QA', 'qa_admin', 'qa-admin@example.test', false, '', 'QA-only-' . bin2hex(random_bytes(6)));
    echo "installed user_id=" . $r['user_id'] . "\n";
    exit;
}

require __DIR__ . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;
$admin = get_user_by('login', 'qa_admin');
wp_set_current_user($admin->ID);
$P = $wpdb->prefix . 'bcrm_';

function sim_snapshot(string $name): array {
    global $wpdb, $out_dir;
    $P = $wpdb->prefix . 'bcrm_';
    $snap = ['users' => [], 'roles' => get_option($wpdb->prefix . 'user_roles'), 'tables' => [], 'rows' => []];
    foreach ($wpdb->get_results("SELECT ID,user_login,user_pass,user_email,display_name FROM {$wpdb->users} ORDER BY ID", ARRAY_A) as $u) {
        $u['capabilities'] = get_user_meta((int)$u['ID'], $wpdb->prefix . 'capabilities', true);
        $u['bcrm_disabled'] = get_user_meta((int)$u['ID'], 'bcrm_disabled', true);
        $u['nickname'] = get_user_meta((int)$u['ID'], 'nickname', true);
        $snap['users'][$u['user_login']] = $u;
    }
    foreach (['service_catalog', 'service_units', 'service_unit_members'] as $t) {
        $snap['rows'][$t] = $wpdb->get_results("SELECT * FROM {$P}$t ORDER BY id", ARRAY_A);
    }
    foreach (['customers','phones','invoices','complaints','financial_cases','refunds','refund_registers','assignments','deficiency_cases','service_cases','case_actions','attachments','interactions'] as $t) {
        $snap['tables'][$t] = ['count' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$P}$t"), 'checksum' => (string)$wpdb->get_var("CHECKSUM TABLE {$P}$t", 1)];
    }
    // v0.8.64.0: complaints gains a nullable subject_id column (CHECKSUM TABLE covers every column, so it
    // changes with the schema). Keep a row-by-row dump of the ORIGINAL columns to prove the data is unchanged.
    $snap['complaints_original'] = $wpdb->get_results("SELECT id,customer_id,interaction_id,subject,related_unit,description,priority,needs_manager_call,due_at,owner_user_id,resolution,resolution_outcome,status,created_at,closed_at FROM {$P}complaints ORDER BY id", ARRAY_A);
    $snap['options'] = [
        'caps_marker' => get_option('biawin_crm_caps_marker'),
        'caps_ledger' => get_option('biawin_crm_caps_ledger'),
        'service_seed_ledger' => get_option('biawin_crm_service_seed_ledger'),
        'unit_seed_ledger' => get_option('biawin_crm_unit_seed_ledger'),
        'db_version' => get_option('biawin_crm_db_version'),
    ];
    $snap['plugin_version'] = defined('BIAWIN_CRM_VERSION') ? BIAWIN_CRM_VERSION : '?';
    file_put_contents("$out_dir/$name.json", json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $snap;
}

switch ($step) {
case 'old_activate':
    $r = activate_plugin('biawin-crm-integration/biawin-crm-integration.php');
    echo is_wp_error($r) ? 'ERR ' . $r->get_error_message() : 'activated ' . BIAWIN_CRM_VERSION, "\n";
    break;

case 'old_request':
case 'request':
    // plugins_loaded already ran maybe_upgrade(); emulate an admin screen hitting admin_init too.
    Biawin_CRM_Activator::ensure_roles_and_caps();
    echo "request ok, plugin " . BIAWIN_CRM_VERSION . ", db_version " . get_option('biawin_crm_db_version') . "\n";
    break;

case 'customize': // production-like state made by admins on the OLD version
    $u = get_user_by('login', '09125906908'); wp_set_password('Changed-By-Admin-' . bin2hex(random_bytes(4)), $u->ID);
    $u = get_user_by('login', '09125172258'); wp_delete_user($u->ID);            // deleted by admin
    $u = get_user_by('login', '09926042925'); $u->set_role('bcrm_complaint_agent'); // re-roled by admin
    $u = get_user_by('login', '09197958525'); update_user_meta($u->ID, 'bcrm_disabled', 1); // disabled by admin
    $u = get_user_by('login', 'deficiency_supervisor'); wp_update_user(['ID' => $u->ID, 'display_name' => 'مهسا — سرپرست ناقصی', 'nickname' => 'mahsa']);
    $u = get_user_by('login', 'svc_furniture_sup'); if ($u) wp_delete_user($u->ID);
    $u = get_user_by('login', '09302515844'); $u->add_cap('custom_user_cap');
    $manual = wp_insert_user(['user_login' => 'manual_agent', 'user_pass' => 'M-' . bin2hex(random_bytes(6)), 'user_email' => 'manual@example.test', 'display_name' => 'کارشناس دستی', 'role' => 'bcrm_agent']);
    $plain = wp_insert_user(['user_login' => 'plain_subscriber', 'user_pass' => 'S-' . bin2hex(random_bytes(6)), 'user_email' => 'sub@example.test', 'role' => 'subscriber']);
    get_role('bcrm_agent')->add_cap('custom_role_cap');
    get_role('administrator')->add_cap('admin_custom_cap');
    // services customized by admin
    $wpdb->update("{$P}service_catalog", ['name' => 'امتیاز وام مبل (ویرایش مدیر)', 'credit_cost' => 123000000, 'sort_order' => 999, 'description' => 'توضیح مدیر'], ['service_code' => 'furniture-loan-score']);
    $wpdb->update("{$P}service_catalog", ['is_active' => 0], ['service_code' => 'bedding-loan-score']);
    $wpdb->update("{$P}service_catalog", ['is_active' => 1], ['service_code' => 'dentistry']); // legacy code re-activated by admin
    if (!$wpdb->get_var("SELECT id FROM {$P}service_catalog WHERE service_code='dentistry'")) $wpdb->insert("{$P}service_catalog", ['service_code' => 'dentistry', 'name' => 'دندانپزشکی (فعال‌شده توسط مدیر)', 'credit_cost' => 5000000, 'unit_key' => 'dentistry', 'is_active' => 1, 'sort_order' => 5, 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')]);
    $wpdb->insert("{$P}service_catalog", ['service_code' => 'custom-svc', 'name' => 'خدمت سفارشی', 'credit_cost' => 7000000, 'unit_key' => 'custom_unit', 'is_active' => 1, 'sort_order' => 1, 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')]);
    // units customized by admin
    $wpdb->update("{$P}service_units", ['unit_name' => 'واحد مبل ویژه', 'supervisor_user_id' => $manual], ['unit_key' => 'furniture_loan']);
    $wpdb->update("{$P}service_units", ['is_active' => 0], ['unit_key' => 'clothing_loan']);
    // business data
    $now = current_time('mysql');
    for ($i = 1; $i <= 3; $i++) {
        $wpdb->insert("{$P}customers", ['internal_uuid' => wp_generate_uuid4(), 'display_name' => "مشتری $i", 'source_customer_id' => "C90$i", 'status' => 'new', 'total_paid' => 5000000 * $i, 'declared_paid' => 5000000 * $i, 'created_at' => $now, 'updated_at' => $now]);
        $cid = (int)$wpdb->insert_id;
        $wpdb->insert("{$P}phones", ['customer_id' => $cid, 'raw_phone' => "0912000000$i", 'normalized_phone' => "0912000000$i", 'created_at' => $now]);
        $wpdb->insert("{$P}invoices", ['customer_id' => $cid, 'invoice_code' => "INV-$i", 'amount_paid' => 5000000 * $i, 'created_at' => $now, 'updated_at' => $now]);
        $wpdb->insert("{$P}complaints", ['customer_id' => $cid, 'subject' => "شکایت $i", 'description' => 'شرح', 'status' => $i === 3 ? 'closed' : 'open', 'created_at' => $now]);
        $wpdb->insert("{$P}financial_cases", ['case_code' => "FIN-T-$i", 'customer_id' => $cid, 'status' => 'new', 'created_at' => $now, 'updated_at' => $now]);
        $wpdb->insert("{$P}refunds", ['refund_code' => "REF-T-$i", 'customer_id' => $cid, 'status' => 'new', 'created_at' => $now, 'updated_at' => $now]);
        $wpdb->insert("{$P}refund_registers", ['customer_id' => $cid, 'invoice_code' => "INV-$i", 'refund_status' => 1, 'refund_amount' => 1000000, 'source_type' => 'excel', 'created_at' => $now]);
        $wpdb->insert("{$P}assignments", ['customer_id' => $cid, 'agent_user_id' => $manual, 'status' => 'assigned', 'assigned_by' => $admin->ID, 'assigned_at' => $now]);
        $wpdb->insert("{$P}deficiency_cases", ['case_code' => "DEF-T-$i", 'customer_id' => $cid, 'status' => 'INCOMPLETE_NEW', 'current_department' => 'deficiency', 'created_at' => $now, 'updated_at' => $now]);
        $wpdb->insert("{$P}service_cases", ['case_code' => "SRV-T-$i", 'customer_id' => $cid, 'service_id' => 1, 'credit_amount' => 1000000, 'unit_key' => 'furniture_loan', 'status' => 'unassigned', 'created_at' => $now, 'updated_at' => $now]);
        $wpdb->insert("{$P}case_actions", ['case_id' => 1, 'customer_id' => $cid, 'department' => 'deficiency', 'action_type' => 'QA_SEED', 'created_at' => $now]);
    }
    echo "customized; last db error: '" . $wpdb->last_error . "'\n";
    break;

case 'rerun': // "run migration again": repair button + forced migration replay + forced caps resync
    Biawin_CRM_Activator::activate();
    update_option('biawin_crm_db_version', '0.8.62.28', false);
    update_option('biawin_crm_caps_marker', 3, false);
    Biawin_CRM_Activator::maybe_upgrade();
    Biawin_CRM_Activator::ensure_roles_and_caps();
    echo "rerun done, db_version " . get_option('biawin_crm_db_version') . "\n";
    break;

case 'post': // admin changes AFTER the upgrade, then another forced full update cycle
    if ($step === 'post' && ($argv[2] ?? '') === 'apply') {
        $u = get_user_by('login', '09033067185'); wp_delete_user($u->ID);
        get_role('bcrm_supervisor')->remove_cap('bcrm_import');
        get_role('bcrm_after_sales_manager')->remove_cap('view_treasury');
        get_role('bcrm_agent')->add_cap('explicitly_denied_x', false);
        remove_role('bcrm_loan_agent');
        $wpdb->delete("{$P}service_catalog", ['service_code' => 'life-insurance']);
        $wpdb->delete("{$P}service_units", ['unit_key' => 'loan']);
        $wpdb->update("{$P}service_units", ['parent_unit_id' => null], ['unit_key' => 'motorcycle_loan']);
        $wpdb->update("{$P}service_units", ['unit_name' => 'نارضایتی و شکایات (نام مدیر)', 'can_receive_inquiry' => 1], ['unit_key' => 'dissatisfaction']);
        echo "post changes applied\n";
    } else {
        Biawin_CRM_Activator::activate();
        update_option('biawin_crm_db_version', '0.8.62.28', false);
        update_option('biawin_crm_caps_marker', 3, false);
        Biawin_CRM_Activator::maybe_upgrade();
        echo "post update cycle done\n";
    }
    break;

case 'units': // admin UI handler: membership / supervisor / active / flags / guards
    add_filter('wp_redirect', function ($loc) { throw new RuntimeException('REDIRECT ' . $loc); });
    add_filter('wp_die_handler', function () { return function ($msg) { throw new RuntimeException('DIE ' . (is_string($msg) ? $msg : '')); }; });
    $res = [];
    $call = function (array $post) use (&$res) {
        $_POST = $post + ['action' => 'bcrm_save_service_unit', '_wpnonce' => wp_create_nonce('bcrm_save_service_unit'), 'unit_form_v2' => '1'];
        $_REQUEST = $_POST;
        try { Biawin_CRM_Admin::handle_save_service_unit(); return 'NO-EXIT'; } catch (RuntimeException $e) { return $e->getMessage(); }
    };
    $sup = get_user_by('login', 'manual_agent')->ID;
    $a1 = get_user_by('login', '09302515844')->ID;
    $a2 = get_user_by('login', '09125906908')->ID;
    $dis = $wpdb->get_row("SELECT * FROM {$P}service_units WHERE unit_key='dissatisfaction'");
    $after = $wpdb->get_row("SELECT * FROM {$P}service_units WHERE unit_key='after_sales'");
    $r = $call(['unit_key' => 'dissatisfaction', 'unit_name' => $dis->unit_name, 'unit_type' => 'dissatisfaction', 'parent_unit_id' => $after->id, 'is_active' => '1', 'display_order' => '20', 'supervisor_user_id' => $sup, 'agent_user_ids' => [$a1, $a2]]);
    $res['save_membership'] = strpos($r, 'REDIRECT') === 0 ? 'PASS' : "FAIL $r";
    $m = $wpdb->get_results($wpdb->prepare("SELECT user_id, member_role FROM {$P}service_unit_members WHERE unit_id=%d ORDER BY member_role,user_id", $dis->id), ARRAY_A);
    $res['supervisor_member'] = in_array(['user_id' => (string)$sup, 'member_role' => 'supervisor'], $m, false) ? 'PASS' : 'FAIL ' . json_encode($m);
    $agents = array_map('intval', array_column(array_filter($m, fn($x) => $x['member_role'] === 'agent'), 'user_id'));
    sort($agents); $want = [$a1, $a2]; sort($want);
    $res['agent_members'] = $agents === $want ? 'PASS' : 'FAIL ' . json_encode($agents);
    $res['supervised_unit_keys'] = in_array('dissatisfaction', Biawin_CRM_Workflow_Service::supervised_unit_keys($sup), true) ? 'PASS' : 'FAIL';
    // multi-unit membership: same agent also in treasury
    $tr = $wpdb->get_row("SELECT * FROM {$P}service_units WHERE unit_key='treasury'");
    $r = $call(['unit_key' => 'treasury', 'unit_name' => $tr->unit_name, 'unit_type' => 'treasury', 'parent_unit_id' => $after->id, 'is_active' => '1', 'can_receive_inquiry' => '1', 'requires_portal_response' => '1', 'display_order' => '60', 'supervisor_user_id' => 0, 'agent_user_ids' => [$a1]]);
    $res['multi_unit_membership'] = ((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$P}service_unit_members WHERE user_id=%d", $a1)) >= 2) ? 'PASS' : "FAIL $r";
    // inactive + flags
    $r = $call(['unit_key' => 'treasury', 'unit_name' => $tr->unit_name, 'unit_type' => 'treasury', 'parent_unit_id' => $after->id, 'display_order' => '61', 'supervisor_user_id' => 0, 'agent_user_ids' => [$a1], 'can_receive_inquiry' => '1']);
    $t2 = $wpdb->get_row("SELECT * FROM {$P}service_units WHERE unit_key='treasury'");
    $res['inactive_flag'] = ((int)$t2->is_active === 0) ? 'PASS' : 'FAIL';
    $res['inquiry_flags'] = ((int)$t2->can_receive_inquiry === 1 && (int)$t2->requires_portal_response === 0 && (int)$t2->display_order === 61) ? 'PASS' : 'FAIL';
    // guards
    $r = $call(['unit_key' => 'after_sales', 'unit_name' => $after->unit_name, 'unit_type' => 'after_sales', 'parent_unit_id' => $dis->id, 'is_active' => '1', 'supervisor_user_id' => 0]);
    $res['cycle_rejected'] = (strpos($r, 'DIE') === 0 && (int)$wpdb->get_var("SELECT COALESCE(parent_unit_id,0) FROM {$P}service_units WHERE unit_key='after_sales'") === 0) ? 'PASS' : "FAIL $r";
    $plain = get_user_by('login', 'plain_subscriber')->ID;
    $r = $call(['unit_key' => 'dissatisfaction', 'unit_name' => $dis->unit_name, 'unit_type' => 'dissatisfaction', 'parent_unit_id' => $after->id, 'is_active' => '1', 'supervisor_user_id' => $plain]);
    $res['non_crm_user_rejected'] = strpos($r, 'DIE') === 0 ? 'PASS' : "FAIL $r";
    // capability check on the handler
    wp_set_current_user(get_user_by('login', '09302515844')->ID); // bcrm_agent
    $r = $call(['unit_key' => 'x_unit', 'unit_name' => 'x']);
    $res['agent_cannot_save_units'] = (strpos($r, 'DIE') === 0 && !$wpdb->get_var("SELECT id FROM {$P}service_units WHERE unit_key='x_unit'")) ? 'PASS' : "FAIL $r";
    foreach ($res as $k => $v) echo str_pad($k, 26) . " $v\n";
    file_put_contents("$out_dir/units.json", json_encode($res));
    break;

case 'snapshot':
    sim_snapshot($argv[2]);
    echo "snapshot {$argv[2]} (plugin " . BIAWIN_CRM_VERSION . ")\n";
    break;
}
