<?php
/**
 * Baseline-agnostic production-like customizer (works on 0.8.63.x, no seeded staff accounts needed).
 *   php simb.php customize     admin customizations: users, roles, caps, services, units, business rows
 *   php simb.php force_cycle   forced replay of every migration + caps resync + repair (worst case)
 */
$step = $argv[1] ?? '';
$_SERVER['HTTP_HOST'] = 'bcrm-upgrade.test'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SERVER_NAME'] = 'bcrm-upgrade.test';
require __DIR__ . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb; $P = $wpdb->prefix . 'bcrm_';
wp_set_current_user(get_user_by('login', 'qa_admin')->ID);
$now = current_time('mysql');
switch ($step) {
case 'customize':
    $mk = function ($login, $role) { return wp_insert_user(['user_login' => $login, 'user_pass' => 'P-' . bin2hex(random_bytes(6)), 'user_email' => "$login@example.test", 'display_name' => $login, 'role' => $role]); };
    $sup = $mk('b_supervisor', 'bcrm_supervisor'); $ag = $mk('b_agent', 'bcrm_agent'); $dis = $mk('b_disabled', 'bcrm_agent');
    $del = $mk('b_deleted', 'bcrm_agent'); $rr = $mk('b_reroled', 'bcrm_agent'); $mk('b_subscriber', 'subscriber');
    $mk('b_complaint_agent', 'bcrm_complaint_agent');
    wp_set_password('Changed-' . bin2hex(random_bytes(4)), $ag);
    update_user_meta($dis, 'bcrm_disabled', 1);
    wp_delete_user($del);
    (new WP_User($rr))->set_role('bcrm_complaint_agent');
    (new WP_User($sup))->add_cap('custom_user_cap');
    wp_update_user(['ID' => $sup, 'display_name' => 'سرپرست ویرایش‌شده', 'nickname' => 'edited']);
    get_role('bcrm_agent')->add_cap('custom_role_cap');
    get_role('administrator')->add_cap('admin_custom_cap');
    get_role('bcrm_supervisor')->remove_cap(array_keys(array_filter(get_role('bcrm_supervisor')->capabilities))[0]);
    $first = $wpdb->get_row("SELECT * FROM {$P}service_catalog ORDER BY id LIMIT 1", ARRAY_A);
    $wpdb->update("{$P}service_catalog", ['name' => 'ویرایش مدیر', 'credit_cost' => 123000000], ['id' => $first['id']]);
    $wpdb->query("UPDATE {$P}service_catalog SET is_active=0 ORDER BY id DESC LIMIT 1");
    $wpdb->query("DELETE FROM {$P}service_catalog WHERE id=(SELECT id FROM (SELECT id FROM {$P}service_catalog ORDER BY id LIMIT 1 OFFSET 1) t)");
    $wpdb->insert("{$P}service_catalog", ['service_code' => 'custom-svc', 'name' => 'خدمت سفارشی', 'credit_cost' => 7000000, 'unit_key' => 'custom_unit', 'is_active' => 1, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $wpdb->update("{$P}service_units", ['unit_name' => 'واحد تغییرنام', 'supervisor_user_id' => $sup], ['unit_key' => 'dissatisfaction']);
    $wpdb->update("{$P}service_units", ['parent_unit_id' => null], ['unit_key' => 'treasury']);
    $wpdb->delete("{$P}service_units", ['unit_key' => 'after_sales_deleted_probe']);
    $u = (int)$wpdb->get_var("SELECT id FROM {$P}service_units WHERE unit_key='finance_approval'");
    if ($u) $wpdb->insert("{$P}service_unit_members", ['unit_id' => $u, 'user_id' => $ag, 'member_role' => 'agent', 'created_at' => $now]);
    for ($i = 1; $i <= 3; $i++) {
        $wpdb->insert("{$P}customers", ['internal_uuid' => wp_generate_uuid4(), 'display_name' => "مشتری $i", 'source_customer_id' => "B90$i", 'status' => 'new', 'total_paid' => 5000000 * $i, 'created_at' => $now, 'updated_at' => $now]);
        $cid = (int)$wpdb->insert_id;
        $wpdb->insert("{$P}phones", ['customer_id' => $cid, 'raw_phone' => "0913000000$i", 'normalized_phone' => "0913000000$i", 'created_at' => $now]);
        $wpdb->insert("{$P}invoices", ['customer_id' => $cid, 'invoice_code' => "BINV-$i", 'amount_paid' => 5000000 * $i, 'created_at' => $now, 'updated_at' => $now]);
        $wpdb->insert("{$P}refund_registers", ['customer_id' => $cid, 'invoice_code' => "BINV-$i", 'refund_status' => 1, 'refund_amount' => 1000000, 'source_type' => 'excel', 'created_at' => $now]);
        $wpdb->insert("{$P}deficiency_cases", ['case_code' => "BDEF-$i", 'customer_id' => $cid, 'status' => 'INCOMPLETE_NEW', 'current_department' => 'deficiency', 'created_at' => $now, 'updated_at' => $now]);
    }
    echo "customized; last db error: '" . $wpdb->last_error . "'\n";
    break;
case 'force_cycle':
    Biawin_CRM_Activator::activate();
    update_option('biawin_crm_db_version', $argv[2] ?? '0.8.62.28', false);
    update_option('biawin_crm_caps_marker', 3, false);
    Biawin_CRM_Activator::maybe_upgrade();
    Biawin_CRM_Activator::ensure_roles_and_caps();
    echo "forced cycle done, db_version " . get_option('biawin_crm_db_version') . "\n";
    break;
}
