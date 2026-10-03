<?php
/**
 * v0.8.66.0 — Master Phase 2 (Core Customer Journey Alignment) on REAL WordPress + MySQL.
 *   php journey.php legacy        (on 0.8.65.0) production-like legacy journey data that must survive
 *   php journey.php snap NAME     snapshot of every business table + ledgers
 *   php journey.php same A B      A and B identical (update / re-run preserves data)
 *   php journey.php functional    tests A–J through the real handlers (AJAX, REST, admin-post, deficiency)
 */
$step = $argv[1] ?? '';
$_SERVER['HTTP_HOST'] = 'bcrm-upgrade.test'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SERVER_NAME'] = 'bcrm-upgrade.test';
$pass = 0; $fail = 0;
function ok($c, $label) { global $pass, $fail; echo ($c ? 'PASS  ' : 'FAIL  ') . $label . "\n"; $c ? $pass++ : $fail++; }
require __DIR__ . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;
$P = $wpdb->prefix . 'bcrm_';
$admin = get_user_by('login', 'qa_admin');
wp_set_current_user($admin->ID);
$out_dir = __DIR__;
$T = static fn(int $sec_ago) => gmdate('Y-m-d H:i:s', current_time('timestamp') - $sec_ago);
$user = static function (string $login, string $role) {
    $u = get_user_by('login', $login);
    if ($u) return $u;
    $id = wp_insert_user(['user_login' => $login, 'user_pass' => 'Pw-' . bin2hex(random_bytes(6)), 'user_email' => "$login@example.test", 'display_name' => $login, 'role' => $role]);
    return get_user_by('id', $id);
};
/** customer + invoice + declared amount by $agent + active assignment to $agent (all 2h ago), then the real recalculation. */
$mk = static function (string $name, float $total, ?float $declared, $agent) use ($wpdb, $P, $T) {
    $wpdb->insert("{$P}customers", ['internal_uuid' => wp_generate_uuid4(), 'display_name' => $name, 'source_customer_id' => 'J-' . substr(md5($name . microtime()), 0, 10), 'status' => 'assigned', 'total_paid' => 0, 'created_at' => $T(7200), 'updated_at' => $T(7200)]);
    $cid = (int)$wpdb->insert_id;
    $wpdb->insert("{$P}invoices", ['customer_id' => $cid, 'invoice_code' => 'JINV-' . $cid, 'amount_paid' => $total, 'created_at' => $T(7200), 'updated_at' => $T(7200)]);
    if ($agent) {
        $wpdb->insert("{$P}assignments", ['customer_id' => $cid, 'agent_user_id' => $agent->ID, 'status' => 'assigned', 'assigned_by' => 1, 'assigned_at' => $T(7200)]);
        if ($declared !== null) $wpdb->insert("{$P}interactions", ['customer_id' => $cid, 'agent_user_id' => $agent->ID, 'type' => 'call', 'outcome' => 'تماس موفق', 'outcome_key' => 'success', 'declared_paid' => $declared, 'created_at' => $T(7000)]);
    }
    Biawin_CRM_Customer_Service::recalculate_customer($cid);
    return $cid;
};
$cust = static fn(int $id) => $wpdb->get_row($wpdb->prepare("SELECT * FROM {$P}customers WHERE id=%d", $id));
$TABLES = ['customers', 'invoices', 'phones', 'assignments', 'interactions', 'complaints', 'complaint_inquiries', 'catalog_items', 'refunds', 'refund_registers', 'deficiency_cases', 'case_actions', 'financial_cases', 'service_cases', 'service_catalog', 'services', 'customer_tags', 'service_units', 'service_unit_members', 'attachments'];

switch ($step) {
case 'legacy':
    $ag1 = $user('j_ag1', 'bcrm_agent'); $user('j_ag2', 'bcrm_agent'); $user('j_def', 'bcrm_deficiency_agent'); $user('j_asa', 'bcrm_after_sales_agent');
    $svc = $wpdb->get_col("SELECT id FROM {$P}service_catalog WHERE is_active=1 ORDER BY id LIMIT 2");
    $subj = (string)$wpdb->get_var("SELECT id FROM {$P}catalog_items WHERE catalog='complaint_subject' AND is_active=1 ORDER BY id LIMIT 1");
    // L1 خدمات مازاد with two service cases (route + services, as 0.8.65 stored them)
    $l1 = $mk('L1 خدمات مازاد با خدمت', 40000000, 40000000, $ag1);
    wp_set_current_user($ag1->ID);
    $r1 = Biawin_CRM_Workflow_Service::select_services($l1, $svc, 'legacy');
    wp_set_current_user($admin->ID);
    // L2 route خدمات مازاد without any service case
    $l2 = $mk('L2 خدمات مازاد بدون خدمت', 30000000, 30000000, $ag1);
    Biawin_CRM_Workflow_Service::set_customer_journey($l2, 'service_request');
    // L3 reached service_request through the legacy complaint outcome «تبدیل به درخواست خدمت»
    $l3 = $mk('L3 از نتیجه نارضایتی', 20000000, 20000000, $ag1);
    $c3 = Biawin_CRM_Workflow_Service::create_complaint($l3, ['subject_id' => $subj, 'description' => 'legacy complaint']);
    Biawin_CRM_Workflow_Service::resolve_complaint((int)$c3['id'], 'service_request', 'legacy outcome');
    // L4 INCOMPLETE profile that selected a route under the old rule (G1)
    $l4 = $mk('L4 ناقص با مسیر قدیمی', 10000000, 15000000, $ag1);
    Biawin_CRM_Workflow_Service::set_customer_journey($l4, 'complaint');
    // L5 historical COMPLETED_AFTER_DEFICIENCY (pre-0.8.66 completion: no reallocation marker) — grandfathered
    $l5 = $mk('L5 تکمیل تاریخی پس از رفع ناقصی', 12000000, 12000000, $ag1);
    $wpdb->insert("{$P}customer_tags", ['customer_id' => $l5, 'tag' => 'COMPLETED_AFTER_DEFICIENCY', 'case_id' => 0, 'created_by' => 0, 'created_at' => $T(3600)]);
    // L6 legacy journey_state=refund (old refund outcome)
    $l6 = $mk('L6 عودت قدیمی', 9000000, 9000000, $ag1);
    $c6 = Biawin_CRM_Workflow_Service::create_complaint($l6, ['subject_id' => $subj, 'description' => 'refund legacy']);
    Biawin_CRM_Workflow_Service::resolve_complaint((int)$c6['id'], 'refund', 'legacy refund');
    // L7 COMPLETE only thanks to the tolerance (mismatch 100,000) — D-01 dry-run population
    $l7 = $mk('L7 کامل با تلرانس', 50000000, 50100000, $ag1);
    update_option('bcrm_p2_legacy_ids', compact('l1', 'l2', 'l3', 'l4', 'l5', 'l6', 'l7'), false);
    echo 'legacy: ' . json_encode(['l1_services' => !empty($r1['ok']), 'l3' => $cust($l3)->journey_state, 'l4' => $cust($l4)->profile_completeness . '/' . $cust($l4)->journey_state, 'l6' => $cust($l6)->journey_state, 'l7' => $cust($l7)->profile_completeness]) . "; db error: '" . $wpdb->last_error . "'\n";
    exit;

case 'snap':
    $snap = [];
    foreach ($TABLES as $t) $snap[$t] = $wpdb->get_results("SELECT * FROM {$P}$t ORDER BY id", ARRAY_A);
    $snap['activities_count'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$P}activities");
    foreach (['biawin_crm_caps_ledger', 'biawin_crm_unit_seed_ledger', 'biawin_crm_service_seed_ledger', 'biawin_crm_catalog_seed_ledger', 'biawin_crm_reconciliation_tolerance'] as $o) $snap['o'][$o] = get_option($o);
    $snap['roles'] = get_option($wpdb->prefix . 'user_roles');
    $snap['meta'] = ['plugin' => BIAWIN_CRM_VERSION, 'db' => get_option('biawin_crm_db_version')];
    file_put_contents("$out_dir/j-{$argv[2]}.json", json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "snapshot {$argv[2]} (plugin {$snap['meta']['plugin']}, db {$snap['meta']['db']})\n";
    exit;

case 'same':
    $a = json_decode(file_get_contents("$out_dir/j-{$argv[2]}.json"), true);
    $b = json_decode(file_get_contents("$out_dir/j-{$argv[3]}.json"), true);
    foreach ($TABLES as $t) ok($a[$t] === $b[$t], "J preserved: $t (" . count((array)$b[$t]) . ' rows)');
    ok($a['activities_count'] === $b['activities_count'], 'J no activity written by the update itself');
    ok($a['o'] === $b['o'] && $a['roles'] === $b['roles'], 'J ledgers, tolerance, roles and capabilities unchanged');
    echo "  versions: {$a['meta']['plugin']}/{$a['meta']['db']} → {$b['meta']['plugin']}/{$b['meta']['db']}\n";
    break;

case 'functional':
    $L = get_option('bcrm_p2_legacy_ids');
    $ag1 = get_user_by('login', 'j_ag1'); $ag2 = get_user_by('login', 'j_ag2'); $def = get_user_by('login', 'j_def'); $asa = get_user_by('login', 'j_asa');
    $http = 0; add_filter('pre_http_request', function ($pre) use (&$http) { $http++; return $pre; });
    $boundary_hook = 0; add_action('bcrm_additional_services_boundary', function () use (&$boundary_hook) { $boundary_hook++; });
    add_filter('wp_doing_ajax', '__return_true');
    add_filter('wp_die_ajax_handler', function () { return function () { throw new RuntimeException('AJAX_END'); }; });
    add_filter('wp_die_handler', function () { return function ($m) { throw new RuntimeException('DIE ' . (is_string($m) ? $m : '')); }; });
    add_filter('wp_redirect', function ($loc) { throw new RuntimeException('REDIRECT ' . $loc); });
    $as = static function ($u, callable $fn) use ($admin) { wp_set_current_user($u->ID); try { return $fn(); } finally { wp_set_current_user($admin->ID); } };
    $ajax = static function ($u, string $action, array $fields) use ($as) {
        return $as($u, static function () use ($action, $fields) {
            $_POST = $fields + ['nonce' => wp_create_nonce('bcrm_front_ajax')]; $_REQUEST = $_POST;
            ob_start();
            try { do_action('wp_ajax_' . $action); } catch (RuntimeException $e) {}
            $out = ob_get_clean();
            $j = json_decode($out, true);
            return is_array($j) ? $j : ['success' => false, 'data' => ['message' => 'no json: ' . substr($out, 0, 200)]];
        });
    };
    $profile = static function ($u, int $cid) use ($as) {
        return $as($u, static function () use ($cid) { $m = new ReflectionMethod('Biawin_CRM_Frontend', 'render_profile'); $m->setAccessible(true); return (string)$m->invoke(null, $cid); });
    };
    $gate = static fn(int $cid, $u) => Biawin_CRM_Workflow_Service::journey_gate($cid, $u->ID);
    $act = static fn(int $cid, string $type) => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$P}activities WHERE customer_id=%d AND activity_type=%s", $cid, $type));
    $count = static fn(string $t, int $cid) => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$P}$t WHERE customer_id=%d", $cid));
    $subject = (int)$wpdb->get_var("SELECT id FROM {$P}catalog_items WHERE catalog='complaint_subject' AND is_active=1 ORDER BY id LIMIT 1");
    $svc = array_map('intval', $wpdb->get_col("SELECT id FROM {$P}service_catalog WHERE is_active=1 ORDER BY id LIMIT 3"));

    echo "--- A COMPLETE profile (mismatch = 0): route visible, three routes\n";
    $a = $mk('A کامل', 25000000, 25000000, $ag1);
    ok($cust($a)->profile_completeness === 'complete' && abs((float)$cust($a)->discrepancy_amount) < 0.01, 'A mismatch 0 → complete');
    $g = $gate($a, $ag1); ok($g['state'] === 'open' && $g['can_select'], 'A gate open for the assigned contact agent');
    $html = $profile($ag1, $a);
    ok(strpos($html, 'data-can-select="1"') !== false && substr_count($html, 'data-bcrm-select-journey=') === 3 && strpos($html, 'data-bcrm-select-journey="complaint"') !== false && strpos($html, 'data-bcrm-select-journey="no_request"') !== false && strpos($html, 'data-bcrm-select-journey="service_request"') !== false, 'A profile shows exactly the three route choices');
    ok(strpos($html, '>ناراضی<') !== false && strpos($html, '>بدون خدمات<') !== false && strpos($html, '>خدمات مازاد<') !== false && strpos($html, 'درخواست خدمات</strong>') === false, 'A labels ناراضی / بدون خدمات / خدمات مازاد');
    ok(strpos($html, 'data-bcrm-journey-status') !== false && strpos($html, '۵. مسیر مشتری') !== false, 'A status strip present (1–5)');
    ok(!$gate($a, $def)['can_select'] && !$gate($a, $asa)['can_select'] && !$gate($a, $ag2)['can_select'], 'A deficiency agent, after-sales agent and other contact agent cannot select the route');
    ok(!$gate($a, $admin)['can_select'], 'A CRM manager cannot select the route (D-07, v0.8.66.1)');

    echo "--- B INCOMPLETE profile: route hidden and refused server-side\n";
    $b = $mk('B ناقص', 10000000, 13000000, $ag1);
    ok($cust($b)->profile_completeness === 'incomplete' && $count('deficiency_cases', $b) === 1, 'B mismatch ≠ 0 → incomplete + existing deficiency case');
    $html = $profile($ag1, $b);
    ok(strpos($html, 'data-bcrm-route-locked') !== false && strpos($html, 'data-bcrm-select-journey=') === false && strpos($html, 'data-bcrm-service-locked') !== false && strpos($html, 'data-bcrm-complaint-locked') !== false, 'B no selectable route, services or complaint form in the profile');
    ok(strpos($html, 'رفع ناقصی') !== false && strpos($html, 'کارتابل تأیید مالی تکمیل شود') === false, 'B profile points to رفع ناقصی (finance optional), not mandatory finance');
    foreach (['complaint', 'no_request', 'service_request'] as $r) { $res = $ajax($ag1, 'bcrm_set_journey', ['customer_id' => $b, 'journey' => $r, 'description' => 'x', 'subject_id' => $subject]); ok(empty($res['success']), "B AJAX route $r refused"); }
    $res = $ajax($ag1, 'bcrm_select_services', ['customer_id' => $b, 'service_ids' => $svc[0]]); ok(empty($res['success']), 'B AJAX services refused');
    $res = $ajax($ag1, 'bcrm_create_complaint', ['customer_id' => $b, 'subject_id' => $subject, 'description' => 'x']); ok(empty($res['success']), 'B AJAX complaint refused');
    $rq = new WP_REST_Request('POST', '/biawin-crm/v1/customers/' . $b . '/journey'); $rq->set_url_params(['id' => $b]); $rq->set_body_params(['journey' => 'no_request']);
    $rr = $as($ag1, static fn() => Biawin_CRM_REST::set_journey($rq)); ok($rr->get_status() === 422, 'B REST route refused (422)');
    ok($cust($b)->journey_state === null && $count('complaints', $b) === 0 && $count('service_cases', $b) === 0, 'B nothing written');

    echo "--- C deficiency DIRECT completion → supervisor pool → new contact\n";
    $c = $mk('C رفع ناقصی مستقیم', 10000000, 12000000, $ag1);
    $case = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$P}deficiency_cases WHERE customer_id=%d", $c));
    Biawin_CRM_Customer_Service::create_manual_invoice($c, 'JMISS-' . $c, 2000000, 'فاکتور پیدا شده در رفع ناقصی', '');
    $inv_before = $count('invoices', $c);
    $res = Biawin_CRM_Deficiency_Workflow::process_action(['case_id' => $case, 'case_action' => 'deficiency_submit', 'workflow_step' => 'complete_corrected', 'description' => 'فاکتور گمشده پیدا شد']);
    ok(!empty($res['ok']), 'C deficiency completed directly (no finance)');
    $cc = $cust($c);
    ok($cc->profile_completeness === 'complete' && (int)$cc->id === $c && $count('invoices', $c) === $inv_before && (int)$wpdb->get_var("SELECT COUNT(*) FROM {$P}customers WHERE display_name='C رفع ناقصی مستقیم'") === 1, 'C same customer_id, same invoices, no second customer');
    ok((bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$P}customer_tags WHERE customer_id=%d AND tag='COMPLETED_AFTER_DEFICIENCY'", $c)), 'C COMPLETED_AFTER_DEFICIENCY applied');
    $asg = $wpdb->get_results($wpdb->prepare("SELECT status, completed_at FROM {$P}assignments WHERE customer_id=%d ORDER BY id", $c), ARRAY_A);
    ok(count($asg) === 1 && $asg[0]['status'] === 'returned' && $asg[0]['completed_at'] !== null && $cc->status === 'new', 'C previous contact assignment → «returned» (+completed_at), customer back to «new» like «برگشت تخصیص»');
    ok($act($c, 'assignment_returned') === 1 && (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$P}audit_log WHERE object_type='customer' AND object_id=%d AND action='assignment_returned'", $c)) === 1, 'C same activity + audit pair as the supervisor return');
    ok($cust($c)->journey_state === null, 'C no route selected automatically by the completion');
    $g = $gate($c, $ag1); ok($g['state'] === 'awaiting_reallocation' && !$g['can_select'] && !$gate($c, $admin)['can_select'] && !$gate($c, $def)['can_select'], 'C «منتظر تخصیص»: nobody (agent, supervisor, deficiency) can select the route');
    $html = $profile($admin, $c); ok(strpos($html, 'منتظر تخصیص') !== false && strpos($html, 'تکمیل‌شده پس از رفع ناقصی') !== false && strpos($html, 'data-bcrm-select-journey=') === false, 'C profile shows «تکمیل‌شده پس از رفع ناقصی» + «منتظر تخصیص», route locked');
    ok(in_array($c, array_map(static fn($r) => (int)$r->id, Biawin_CRM_Workflow_Service::awaiting_reallocation_rows()), true), 'C appears in the supervisor reallocation queue');
    $card = (function () { $m = new ReflectionMethod('Biawin_CRM_Admin', 'render_reallocation_card'); $m->setAccessible(true); ob_start(); $m->invoke(null, [get_user_by('login', 'j_ag2')]); return ob_get_clean(); })();
    ok(strpos($card, 'value="' . $c . '"') !== false && strpos($card, 'value="bcrm_assign_customers"') !== false, 'C assignment page card lists the customer and posts to the existing assignment handler');
    $res = $ajax($ag1, 'bcrm_set_journey', ['customer_id' => $c, 'journey' => 'no_request']); ok(empty($res['success']), 'C previous agent refused (no access / gate)');
    // supervisor assigns ag2 through the EXISTING handler (explicit ids, like the card)
    $_POST = ['action' => 'bcrm_assign_customers', 'cohort' => 'all', 'contact_outcome' => '', 'profile_limit' => '1', 'customer_ids' => [(string)$c], 'agent_user_ids' => [(string)$ag2->ID]]; $_REQUEST = $_POST + ['_wpnonce' => wp_create_nonce('bcrm_assign_customers')]; $_POST['_wpnonce'] = $_REQUEST['_wpnonce'];
    try { do_action('admin_post_bcrm_assign_customers'); } catch (RuntimeException $e) {}
    $active = Biawin_CRM_Workflow_Service::active_assignment($c);
    ok($active && (int)$active->agent_user_id === $ag2->ID, 'C supervisor reassigned through the existing engine');
    $g = $gate($c, $ag2); ok($g['state'] === 'new_contact_required' && !$g['can_select'], 'E route unavailable before the NEW contact');
    $html = $profile($ag2, $c); ok(strpos($html, 'نیازمند تماس جدید') !== false && strpos($html, 'data-bcrm-select-journey=') === false, 'E profile shows «نیازمند تماس جدید»');
    $res = $ajax($ag2, 'bcrm_set_journey', ['customer_id' => $c, 'journey' => 'no_request']); ok(empty($res['success']), 'E route refused server-side before the new contact');
    $res = $ajax($ag2, 'bcrm_save_interaction', ['customer_id' => $c, 'outcome_key' => 'success', 'declared_paid' => '12000000', 'save_mode' => 'manual']);
    ok(!empty($res['success']) && !empty($res['data']['snapshot']['route_gate']['can_select']), 'E new contact recorded → snapshot gate open');
    ok($gate($c, $ag2)['state'] === 'open' && $gate($c, $ag2)['can_select'] && !$gate($c, $admin)['can_select'], 'E route available to the reassigned agent only (not supervisor)');
    $res = $ajax($ag2, 'bcrm_set_journey', ['customer_id' => $c, 'journey' => 'no_request', 'satisfaction' => 'satisfied']); ok(!empty($res['success']) && $cust($c)->journey_state === 'no_request', 'E route selected in the new contact flow');

    echo "--- C2 same agent reassigned: the old interaction is not reused\n";
    $c2 = $mk('C2 همان کارشناس', 8000000, 9000000, $ag1);
    $old_int = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$P}interactions WHERE customer_id=%d", $c2));
    $case2 = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$P}deficiency_cases WHERE customer_id=%d", $c2));
    Biawin_CRM_Customer_Service::create_manual_invoice($c2, 'JMISS-' . $c2, 1000000, 'x', '');
    Biawin_CRM_Deficiency_Workflow::process_action(['case_id' => $case2, 'case_action' => 'deficiency_submit', 'workflow_step' => 'complete_corrected', 'description' => 'x']);
    $wpdb->insert("{$P}assignments", ['customer_id' => $c2, 'agent_user_id' => $ag1->ID, 'status' => 'assigned', 'assigned_by' => 1, 'assigned_at' => current_time('mysql')]);
    $res = $ajax($ag1, 'bcrm_save_interaction', ['customer_id' => $c2, 'interaction_id' => $old_int, 'outcome_key' => 'success', 'declared_paid' => '9000000', 'save_mode' => 'manual']);
    ok(!empty($res['success']) && (int)$res['data']['interaction_id'] !== $old_int && $count('interactions', $c2) === 2, 'C2 a NEW interaction row is created (old one untouched)');
    ok($gate($c2, $ag1)['can_select'], 'C2 route unlocked after the new contact');

    echo "--- D deficiency → Finance Verification → completion (finance optional, same hand-off)\n";
    $d = $mk('D رفع ناقصی با مالی', 10000000, 11000000, $ag1);
    $dcase = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$P}deficiency_cases WHERE customer_id=%d", $d));
    $wpdb->update("{$P}deficiency_cases", ['status' => 'FINANCE_UNDER_REVIEW', 'current_department' => 'finance', 'finance_agent_id' => $admin->ID, 'sent_to_finance_at' => current_time('mysql')], ['id' => $dcase]);
    $res = Biawin_CRM_Deficiency_Workflow::process_action(['case_id' => $dcase, 'case_action' => 'finance_result', 'finance_result' => 'with_invoice', 'invoice_number' => 'JFIN-' . $d, 'invoice_amount' => '1000000', 'invoice_date' => '2026-10-01', 'description' => 'تأیید مالی']);
    $st = (string)$wpdb->get_var($wpdb->prepare("SELECT status FROM {$P}deficiency_cases WHERE id=%d", $dcase));
    $dd = $cust($d);
    ok(!empty($res['ok']) && $st === 'COMPLETED' && $dd->profile_completeness === 'complete' && $dd->completion_source === 'FINANCE_WORKFLOW', 'D finance approval with invoice auto-completed the deficiency case (D-10 fixed in 0.8.66.1)');
    ok((float)$dd->total_paid === 11000000.0, 'D invoice counted once (total 11,000,000)');
    ok((string)$wpdb->get_var($wpdb->prepare("SELECT status FROM {$P}deficiency_cases WHERE id=%d", $dcase)) === 'COMPLETED' && (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$P}customer_tags WHERE customer_id=%d AND tag='COMPLETED_AFTER_DEFICIENCY'", $d)), 'D case COMPLETED + tag');
    ok((string)$wpdb->get_var($wpdb->prepare("SELECT status FROM {$P}assignments WHERE customer_id=%d ORDER BY id DESC LIMIT 1", $d)) === 'returned' && $gate($d, $ag1)['state'] === 'awaiting_reallocation' && $dd->journey_state === null, 'D same hand-off as direct completion (returned → منتظر تخصیص, no route)');
    ok($count('deficiency_cases', $c) === 1 && $count('case_actions', $c) >= 1 && $count('deficiency_cases', $d) === 1, 'C/D deficiency history preserved (cases not overwritten, no new case)');
    $profile($admin, $c); $profile($admin, $d);
    ok($cust($c)->profile_completeness === 'complete' && $cust($d)->profile_completeness === 'complete' && $count('deficiency_cases', $c) === 1, 'C/D completion durable across profile recalculation (invoice-based resolution)');

    echo "--- K D-09 regression (fixed in 0.8.66.1)\n";
    $k = $mk('K اصلاح مبلغ اعلامی', 10000000, 12000000, $ag1);
    $kcase = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$P}deficiency_cases WHERE customer_id=%d", $k));
    Biawin_CRM_Deficiency_Workflow::process_action(['case_id' => $kcase, 'case_action' => 'deficiency_submit', 'workflow_step' => 'complete_corrected', 'corrected_declared_paid' => '10000000', 'description' => 'اصلاح مبلغ']);
    $k1 = $cust($k)->profile_completeness;
    $profile($admin, $k);
    ok($k1 === 'complete' && $cust($k)->profile_completeness === 'complete' && $count('deficiency_cases', $k) === 1, 'K D-09 fixed: corrected declared amount stays complete across recalculation, no new deficiency case');

    echo "--- F بدون خدمات → archive, nothing else\n";
    $f = $mk('F بدون خدمات', 15000000, 15000000, $ag1);
    $before = [$count('complaints', $f), $count('service_cases', $f), $count('refunds', $f)];
    $res = $ajax($ag1, 'bcrm_set_journey', ['customer_id' => $f, 'journey' => 'no_request', 'satisfaction' => 'satisfied']);
    ok(!empty($res['success']) && $cust($f)->journey_state === 'no_request' && $act($f, 'no_service_archived') === 1, 'F route بدون خدمات + archive record');
    ok([$count('complaints', $f), $count('service_cases', $f), $count('refunds', $f)] === $before && $act($f, 'additional_services_boundary') === 0, 'F no complaint, no service case, no refund, no Biawin boundary');
    $list = $as($admin, static function () { $_GET = ['bcrm_filter' => 'no_request']; $m = new ReflectionMethod('Biawin_CRM_Frontend', 'render_case_list'); $m->setAccessible(true); return (string)$m->invoke(null); });
    ok(strpos($list, 'F بدون خدمات') !== false && strpos($list, 'A کامل') === false, 'F searchable in «بایگانی بدون خدمات»; nothing deleted');
    ok((string)$wpdb->get_var($wpdb->prepare("SELECT status FROM {$P}assignments WHERE customer_id=%d ORDER BY id DESC LIMIT 1", $f)) === 'assigned', 'F assignment untouched (terminal state D-05 documented, not implemented)');

    echo "--- G ناراضی → after-sales / complaint hand-off\n";
    $gc = $mk('G ناراضی', 18000000, 18000000, $ag1);
    $res = $ajax($ag1, 'bcrm_set_journey', ['customer_id' => $gc, 'journey' => 'complaint']);
    ok(empty($res['success']) && $count('complaints', $gc) === 0 && $cust($gc)->journey_state === null, 'G bare «ناراضی» click without the complaint form writes nothing');
    $res = $ajax($ag1, 'bcrm_create_complaint', ['customer_id' => $gc, 'subject_id' => $subject, 'description' => 'مشتری ناراضی است', 'priority' => 'normal']);
    $comp = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$P}complaints WHERE customer_id=%d", $gc));
    ok(!empty($res['success']) && $comp && $comp->status === 'open' && $cust($gc)->journey_state === 'complaint', 'G complaint created in after-sales; route = ناراضی');
    ok($act($gc, 'additional_services_boundary') === 0 && $count('service_cases', $gc) === 0, 'G no service / no Biawin boundary');
    $html = $profile($ag1, $gc); ok(strpos($html, 'bcrm_front_complaint_resolution') === false, 'G general contact agent gets no complaint-resolution workflow');
    $html = $profile($asa, $gc); ok(strpos($html, 'bcrm_front_complaint_resolution') !== false && strpos($html, 'data-bcrm-select-journey=') === false, 'G after-sales agent resolves the complaint but cannot select routes');

    echo "--- H خدمات مازاد → internal boundary only\n";
    $h = $mk('H خدمات مازاد', 60000000, 60000000, $ag1);
    $inactive = (int)$wpdb->get_var("SELECT id FROM {$P}service_catalog WHERE is_active=0 ORDER BY id LIMIT 1");
    if ($inactive) { $res = $ajax($ag1, 'bcrm_select_services', ['customer_id' => $h, 'service_ids' => (string)$inactive]); ok(empty($res['success']) && $cust($h)->journey_state === null, 'H R-07 failed service selection leaves NO route behind'); }
    $res = $ajax($ag1, 'bcrm_select_services', ['customer_id' => $h, 'service_ids' => implode(',', array_slice($svc, 0, 2)), 'note' => 'دو خدمت']);
    $bact = $wpdb->get_row($wpdb->prepare("SELECT meta_json FROM {$P}activities WHERE customer_id=%d AND activity_type='additional_services_boundary'", $h));
    $meta = $bact ? json_decode($bact->meta_json, true) : [];
    ok(!empty($res['success']) && $cust($h)->journey_state === 'service_request' && $count('service_cases', $h) === 2, 'H route خدمات مازاد + two service cases');
    ok(($meta['biawin_status'] ?? '') === 'NOT_SENT' && ($meta['external_call'] ?? true) === false && count($meta['service_case_ids'] ?? []) === 2 && $boundary_hook === 1, 'H boundary recorded (NOT_SENT) + hook fired once');
    ok($http === 0, 'H NO external HTTP request made by the whole Phase 2 run');

    echo "--- I existing خدمات مازاد customers — reconciliation\n";
    $rec = Biawin_CRM_Journey_Reports::additional_services_reconciliation();
    $ids = array_map(static fn($r) => (int)$r['id'], $rec['rows']);
    ok(in_array($L['l1'], $ids, true) && in_array($L['l2'], $ids, true) && in_array($L['l3'], $ids, true), 'I legacy L1/L2/L3 identified');
    ok($rec['summary']['without_service_cases'] >= 1 && $rec['summary']['via_complaint_outcome'] >= 1 && $rec['summary']['with_service_cases'] >= 2, 'I counted: with/without service cases, via legacy complaint outcome');
    ok($rec['duplicate_active_service_cases'] === 0 && $rec['biawin_data'] === 'none', 'I no duplicate service cases; no Biawin data exists');
    ok($act($L['l1'], 'additional_services_boundary') === 0, 'I legacy customers NOT pushed to the boundary/external system');
    $dry = Biawin_CRM_Journey_Reports::completeness_dry_run();
    ok($dry['would_change']['n'] >= 1 && $dry['would_change']['new_deficiency'] >= 1 && $dry['incomplete_that_would_become_complete'] === 0, 'D-01 dry-run: tolerance-only complete customers (L7) counted as would-change / new deficiency');
    ok($cust($L['l7'])->profile_completeness === 'complete', 'D-01 nothing transitioned (L7 still complete)');
    ob_start(); Biawin_CRM_Journey_Reports::render_page(); $page = ob_get_clean();
    ok(strpos($page, 'D-01') !== false && strpos($page, 'گزارش تطبیق') !== false, 'reports page renders');

    echo "--- J legacy compatibility\n";
    ok($gate($L['l5'], $ag1)['can_select'] && $gate($L['l5'], $ag1)['state'] === 'open', 'J historical COMPLETED_AFTER_DEFICIENCY (pre-cutover) not retro-locked');
    ok($cust($L['l4'])->journey_state === 'complaint' && $gate($L['l4'], $ag1)['state'] === 'incomplete', 'J incomplete customer keeps its old route value but cannot select a new one');
    ok($cust($L['l6'])->journey_state === 'refund' && strpos($profile($admin, $L['l6']), 'عودت وجه (مقدار قدیمی)') !== false, 'J legacy journey_state=refund preserved and labelled');
    $c9 = $mk('J9 نتیجه قدیمی', 7000000, 7000000, $ag1);
    $res = $as($ag1, static fn() => Biawin_CRM_Workflow_Service::create_complaint($c9, ['subject_id' => $subject, 'description' => 'x']));
    $rs = Biawin_CRM_Workflow_Service::resolve_complaint((int)$res['id'], 'satisfied', 'ok');
    ok(!empty($rs['ok']) && $cust($c9)->journey_state === 'no_request', 'J complaint outcome semantics unchanged in Phase 2 (legacy, documented for Phase 3/5)');
    break;

default:
    exit("unknown step\n");
}
echo "TOTAL: $pass passed, $fail failed\n";
