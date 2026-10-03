<?php
/**
 * v0.8.66.1 — Phase 2 correction pass on REAL WordPress + MySQL (run after journey.php functional, same site).
 *   php correction.php functional   D-07 route authority · D-08 open case · D-09 declared source · D-10 finance
 *                                   double count · new-contact definition · archive isolation (current state)
 *   php correction.php zero          D-01 on THIS existing install: rule still legacy; then the approved switch
 *                                   simulated on the throwaway site (lazy transition, no data loss)
 *   php correction.php fresh         fresh install starts with the zero-mismatch rule
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
$T = static fn(int $sec_ago) => gmdate('Y-m-d H:i:s', current_time('timestamp') - $sec_ago);
$W = 'Biawin_CRM_Workflow_Service';
$mk = static function (string $name, array $invoices, ?float $declared, $agent) use ($wpdb, $P, $T) {
    $wpdb->insert("{$P}customers", ['internal_uuid' => wp_generate_uuid4(), 'display_name' => $name, 'source_customer_id' => 'K-' . substr(md5($name . microtime()), 0, 10), 'status' => 'assigned', 'total_paid' => 0, 'created_at' => $T(7200), 'updated_at' => $T(7200)]);
    $cid = (int)$wpdb->insert_id;
    foreach ($invoices as $i => $amt) $wpdb->insert("{$P}invoices", ['customer_id' => $cid, 'invoice_code' => "KINV-$cid-$i", 'amount_paid' => $amt, 'created_at' => $T(7200), 'updated_at' => $T(7200)]);
    if ($agent) {
        $wpdb->insert("{$P}assignments", ['customer_id' => $cid, 'agent_user_id' => $agent->ID, 'status' => 'assigned', 'assigned_by' => 1, 'assigned_at' => $T(7200)]);
        if ($declared !== null) $wpdb->insert("{$P}interactions", ['customer_id' => $cid, 'agent_user_id' => $agent->ID, 'type' => 'call', 'outcome' => 'تماس موفق', 'outcome_key' => 'success', 'declared_paid' => $declared, 'created_at' => $T(7000)]);
    }
    Biawin_CRM_Customer_Service::recalculate_customer($cid);
    return $cid;
};
$cust = static fn(int $id) => $wpdb->get_row($wpdb->prepare("SELECT * FROM {$P}customers WHERE id=%d", $id));
$count = static fn(string $t, int $cid) => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$P}$t WHERE customer_id=%d", $cid));
$case = static fn(int $cid) => $wpdb->get_row($wpdb->prepare("SELECT * FROM {$P}deficiency_cases WHERE customer_id=%d ORDER BY id DESC LIMIT 1", $cid));
$as = static function ($u, callable $fn) use ($admin) { wp_set_current_user($u->ID); try { return $fn(); } finally { wp_set_current_user($admin->ID); } };
$recalc = static fn(int $cid) => Biawin_CRM_Customer_Service::recalculate_customer($cid);
$defact = static fn(array $raw) => Biawin_CRM_Deficiency_Workflow::process_action($raw);
$ag1 = get_user_by('login', 'j_ag1'); $ag2 = get_user_by('login', 'j_ag2');

switch ($step) {
case 'functional':
    add_filter('wp_doing_ajax', '__return_true');
    add_filter('wp_die_ajax_handler', function () { return function () { throw new RuntimeException('AJAX_END'); }; });
    $ajax = static function ($u, string $action, array $fields) use ($as) {
        return $as($u, static function () use ($action, $fields) {
            $_POST = $fields + ['nonce' => wp_create_nonce('bcrm_front_ajax')]; $_REQUEST = $_POST;
            ob_start(); try { do_action('wp_ajax_' . $action); } catch (RuntimeException $e) {}
            $j = json_decode(ob_get_clean(), true); return is_array($j) ? $j : ['success' => false];
        });
    };
    $http = 0; add_filter('pre_http_request', function ($pre) use (&$http) { $http++; return $pre; });

    echo "--- D-07 route authority: assigned contact agent only\n";
    $a = $mk('R مسیر عادی', [20000000], 20000000, $ag1);
    ok($W::journey_gate($a, $ag1->ID)['can_select'] && !$W::journey_gate($a, $admin->ID)['can_select'], 'assignee yes; CRM manager no');
    $res = $ajax($admin, 'bcrm_set_journey', ['customer_id' => $a, 'journey' => 'no_request']);
    ok(empty($res['success']) && $cust($a)->journey_state === null, 'manager AJAX route selection refused, nothing written');
    $res = $ajax($admin, 'bcrm_select_services', ['customer_id' => $a, 'service_ids' => (string)$wpdb->get_var("SELECT id FROM {$P}service_catalog WHERE is_active=1 ORDER BY id LIMIT 1")]);
    ok(empty($res['success']) && $count('service_cases', $a) === 0, 'manager service selection refused');
    ok(user_can($admin, 'bcrm_manage_cases') && user_can(get_user_by('login', 'b_supervisor') ?: $admin, 'bcrm_work_cases'), 'bcrm_manage_cases itself untouched (only the route gate stopped honouring it)');
    $res = $ajax($ag1, 'bcrm_set_journey', ['customer_id' => $a, 'journey' => 'no_request', 'satisfaction' => 'satisfied']);
    ok(!empty($res['success']), 'assigned contact agent selects the route');

    echo "--- D-08 open deficiency case blocks the route\n";
    $o = $mk('R پرونده باز', [10000000], 12000000, $ag1);
    ok($cust($o)->profile_completeness === 'incomplete' && $case($o) && $case($o)->status === 'INCOMPLETE_NEW', 'incomplete + deficiency case');
    $wpdb->insert("{$P}interactions", ['customer_id' => $o, 'agent_user_id' => $ag1->ID, 'type' => 'call', 'outcome' => 'تماس موفق', 'outcome_key' => 'success', 'declared_paid' => 10000000, 'created_at' => $T(60)]);
    $recalc($o);
    $g = $W::journey_gate($o, $ag1->ID);
    ok($cust($o)->profile_completeness === 'complete' && $g['state'] === 'deficiency_open' && !$g['can_select'], 'calculation shows COMPLETE but the open case locks the route');
    $res = $ajax($ag1, 'bcrm_set_journey', ['customer_id' => $o, 'journey' => 'no_request']);
    ok(empty($res['success']) && $cust($o)->journey_state === null, 'route refused server-side while the case is open');
    $res = $defact(['case_id' => (int)$case($o)->id, 'case_action' => 'deficiency_submit', 'workflow_step' => 'complete_corrected', 'description' => 'رفع شد']);
    ok(!empty($res['ok']) && $case($o)->status === 'COMPLETED' && $W::journey_gate($o, $ag1->ID)['state'] === 'awaiting_reallocation', 'genuine completion → supervisor pool (returned) → still locked');
    $wpdb->insert("{$P}assignments", ['customer_id' => $o, 'agent_user_id' => $ag2->ID, 'status' => 'assigned', 'assigned_by' => 1, 'assigned_at' => current_time('mysql')]);
    ok($W::journey_gate($o, $ag2->ID)['state'] === 'new_contact_required', 'reassigned → new contact required');

    echo "--- new-contact definition (successful contact only)\n";
    $res = $ajax($ag2, 'bcrm_save_interaction', ['customer_id' => $o, 'outcome_key' => 'no_answer', 'save_mode' => 'manual']);
    ok(!empty($res['success']) && $W::journey_gate($o, $ag2->ID)['state'] === 'new_contact_required', '«عدم پاسخ» recorded but does NOT unlock the route');
    $res = $ajax($ag2, 'bcrm_save_interaction', ['customer_id' => $o, 'outcome_key' => 'callback', 'save_mode' => 'manual']);
    ok($W::journey_gate($o, $ag2->ID)['state'] === 'new_contact_required', '«درخواست تماس مجدد» does NOT unlock');
    $res = $ajax($ag2, 'bcrm_save_interaction', ['customer_id' => $o, 'outcome_key' => 'success', 'declared_paid' => '10000000', 'save_mode' => 'manual']);
    ok($W::journey_gate($o, $ag2->ID)['state'] === 'open' && $W::journey_gate($o, $ag2->ID)['can_select'], '«تماس موفق» by the reassigned agent unlocks');
    ok($W::successful_contact_keys() === ['success'], 'successful outcomes = operational_status contact_success (default: success)');

    echo "--- D-09 declared amount stays stable after a valid correction\n";
    $d9 = $mk('R اصلاح مبلغ', [10000000], 12000000, $ag1);
    $int_before = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$P}interactions WHERE customer_id=%d ORDER BY id", $d9), ARRAY_A);
    $res = $defact(['case_id' => (int)$case($d9)->id, 'case_action' => 'deficiency_submit', 'workflow_step' => 'complete_corrected', 'corrected_declared_paid' => '10000000', 'description' => 'مشتری مبلغ را اشتباه گفته بود']);
    ok(!empty($res['ok']) && $cust($d9)->profile_completeness === 'complete', 'corrected → complete');
    $recalc($d9); $recalc($d9);
    $c9 = $cust($d9);
    ok($c9->profile_completeness === 'complete' && (float)$c9->declared_paid === 10000000.0 && $count('deficiency_cases', $d9) === 1, 'stable across recalculation: declared 10,000,000, still complete, no new deficiency case');
    ok($wpdb->get_results($wpdb->prepare("SELECT * FROM {$P}interactions WHERE customer_id=%d ORDER BY id", $d9), ARRAY_A) === $int_before && $count('invoices', $d9) === 1, 'interactions and invoices untouched (no copy, no new field)');
    $wpdb->insert("{$P}assignments", ['customer_id' => $d9, 'agent_user_id' => $ag2->ID, 'status' => 'assigned', 'assigned_by' => 1, 'assigned_at' => current_time('mysql')]);
    $ajax($ag2, 'bcrm_save_interaction', ['customer_id' => $d9, 'outcome_key' => 'success', 'declared_paid' => '11000000', 'save_mode' => 'manual']);
    ok($cust($d9)->profile_completeness === 'incomplete' && (float)$cust($d9)->declared_paid === 11000000.0, 'a NEWER customer statement supersedes the correction (latest statement wins)');

    echo "--- D-10 finance confirmation counts an invoice once\n";
    $f1 = $mk('R مالی با فاکتور', [10000000], 11000000, $ag1);
    $wpdb->update("{$P}deficiency_cases", ['status' => 'FINANCE_UNDER_REVIEW', 'current_department' => 'finance', 'finance_agent_id' => $admin->ID], ['id' => (int)$case($f1)->id]);
    $res = $defact(['case_id' => (int)$case($f1)->id, 'case_action' => 'finance_result', 'finance_result' => 'with_invoice', 'invoice_number' => 'KF-' . $f1, 'invoice_amount' => '1000000', 'invoice_date' => '2026-10-01', 'description' => 'تأیید']);
    $cf = $cust($f1);
    ok(!empty($res['ok']) && $case($f1)->status === 'COMPLETED' && $cf->profile_completeness === 'complete' && (float)$cf->total_paid === 11000000.0, 'with invoice: auto-completed; total 11,000,000 (invoice counted once)');
    ok((float)$case($f1)->final_approved_amount === 11000000.0 && $count('invoices', $f1) === 2 && (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$P}customer_tags WHERE customer_id=%d AND tag='FINANCE_RECONCILED'", $f1)), 'reconciled amount = invoice total (no 12,000,000 double count); one new invoice');
    ok($W::journey_gate($f1, $ag1->ID)['state'] === 'awaiting_reallocation', 'finance completion → same hand-off (supervisor pool)');
    $again = $defact(['case_id' => (int)$case($f1)->id, 'case_action' => 'finance_result', 'finance_result' => 'with_invoice', 'invoice_number' => 'KF-' . $f1, 'invoice_amount' => '1000000', 'invoice_date' => '2026-10-01', 'description' => 'تکرار']);
    ok(empty($again['ok']) && $count('invoices', $f1) === 2 && (float)$cust($f1)->total_paid === 11000000.0, 'repeated processing refused; no second invoice, no second count');
    $recalc($f1); ok($cust($f1)->profile_completeness === 'complete', 'durable across recalculation');

    $f2 = $mk('R مالی با رسید', [10000000], 11500000, $ag1);
    $wpdb->update("{$P}deficiency_cases", ['status' => 'FINANCE_UNDER_REVIEW', 'current_department' => 'finance', 'finance_agent_id' => $admin->ID], ['id' => (int)$case($f2)->id]);
    $res = $defact(['case_id' => (int)$case($f2)->id, 'case_action' => 'finance_result', 'finance_result' => 'with_invoice', 'invoice_number' => 'KR-' . $f2, 'invoice_amount' => '1500000', 'invoice_date' => '2026-10-01', 'receipt_attachment_id' => '999', 'description' => 'با رسید']);
    ok(!empty($res['ok']) && $case($f2)->status === 'COMPLETED' && (float)$cust($f2)->total_paid === 11500000.0, 'finance confirmation with receipt: completed, counted once');

    $f3 = $mk('R چند فاکتور', [4000000, 3000000, 2000000], 10000000, $ag1);
    $wpdb->update("{$P}deficiency_cases", ['status' => 'FINANCE_UNDER_REVIEW', 'current_department' => 'finance', 'finance_agent_id' => $admin->ID], ['id' => (int)$case($f3)->id]);
    $res = $defact(['case_id' => (int)$case($f3)->id, 'case_action' => 'finance_result', 'finance_result' => 'with_invoice', 'invoice_number' => 'KM-' . $f3, 'invoice_amount' => '1000000', 'invoice_date' => '2026-10-01', 'description' => 'چند فاکتور']);
    ok(!empty($res['ok']) && $case($f3)->status === 'COMPLETED' && (float)$cust($f3)->total_paid === 10000000.0 && $count('invoices', $f3) === 4, 'multiple invoices: 4+3+2+1 = 10,000,000 = declared → completed');

    $f4 = $mk('R مالی ناکافی', [10000000], 12000000, $ag1);
    $wpdb->update("{$P}deficiency_cases", ['status' => 'FINANCE_UNDER_REVIEW', 'current_department' => 'finance', 'finance_agent_id' => $admin->ID], ['id' => (int)$case($f4)->id]);
    $res = $defact(['case_id' => (int)$case($f4)->id, 'case_action' => 'finance_result', 'finance_result' => 'with_invoice', 'invoice_number' => 'KP-' . $f4, 'invoice_amount' => '1000000', 'invoice_date' => '2026-10-01', 'description' => 'بخشی']);
    ok(!empty($res['ok']) && $case($f4)->status === 'RETURNED_TO_DEFICIENCY' && $cust($f4)->profile_completeness === 'incomplete', 'partial finance confirmation: not completed (11,000,000 ≠ 12,000,000), back to deficiency');
    $res = $defact(['case_id' => (int)$case($f4)->id, 'case_action' => 'deficiency_submit', 'workflow_step' => 'complete_corrected', 'corrected_declared_paid' => '11000000', 'description' => 'اصلاح پس از مالی']);
    $recalc($f4);
    ok(!empty($res['ok']) && $case($f4)->status === 'COMPLETED' && $cust($f4)->profile_completeness === 'complete' && $W::journey_gate($f4, $ag1->ID)['state'] === 'awaiting_reallocation', 'deficiency completion after finance: durable + hand-off');

    $f5 = $mk('R مالی بدون فاکتور', [10000000], 11000000, $ag1);
    $wpdb->update("{$P}deficiency_cases", ['status' => 'FINANCE_UNDER_REVIEW', 'current_department' => 'finance', 'finance_agent_id' => $admin->ID], ['id' => (int)$case($f5)->id]);
    $defact(['case_id' => (int)$case($f5)->id, 'case_action' => 'finance_result', 'finance_result' => 'without_invoice', 'approved_amount' => '1000000', 'payment_date' => '2026-10-01', 'description' => 'بدون فاکتور']);
    $s1 = $cust($f5)->profile_completeness; $recalc($f5);
    echo '  INFO D-11 (finance architecture, not changed): without_invoice → ' . $s1 . ' after finance, ' . $cust($f5)->profile_completeness . ' after recalculation (approved payment is not an invoice); deficiency cases: ' . $count('deficiency_cases', $f5) . "\n";

    echo "--- archive isolation (بدون خدمات) — current representation, no engine change\n";
    $ar = $mk('R بایگانی', [5000000], 5000000, $ag1);
    $ajax($ag1, 'bcrm_set_journey', ['customer_id' => $ar, 'journey' => 'no_request', 'satisfaction' => 'satisfied']);
    $c = $cust($ar);
    ok($c->journey_state === 'no_request' && $count('invoices', $ar) === 1 && $count('interactions', $ar) >= 1 && $count('complaints', $ar) === 0 && $count('service_cases', $ar) === 0 && $count('deficiency_cases', $ar) === 0, 'archived: in CRM with invoices + history; no complaint, service, deficiency');
    $in_pool = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$P}customers c WHERE c.id=%d AND NOT EXISTS (SELECT 1 FROM {$P}assignments a WHERE a.customer_id=c.id AND a.status='assigned')", $ar));
    ok($in_pool === 0, 'NOT in the supervisor allocation pool (assignment stays active)');
    echo "  INFO D-05: still counted in the agent's active workload (assignment active) — engine/report change required, reported\n";
    ok($http === 0, 'no external HTTP request');
    break;

case 'zero':
    $L = get_option('bcrm_p2_legacy_ids');
    ok($W::completeness_rule() === 'legacy_tolerance' && get_option($W::COMPLETENESS_RULE_OPTION, null) === null, 'existing install after update: rule NOT switched automatically (no silent mass transition)');
    $recalc($L['l7']);
    ok($cust($L['l7'])->profile_completeness === 'complete', 'L7 (mismatch 100,000) still complete under the legacy rule');
    $dry = Biawin_CRM_Journey_Reports::completeness_dry_run();
    ok($dry['rule'] === 'legacy_tolerance' && $dry['would_change']['n'] >= 1, 'dry-run reports the would-change population');
    // --- simulate the APPROVED switch on this throwaway site ---
    $before = ['inv' => $count('invoices', $L['l7']), 'int' => $count('interactions', $L['l7']), 'journey' => $cust($L['l7'])->journey_state];
    update_option($W::COMPLETENESS_RULE_OPTION, 'zero', false);
    ok($W::completeness_tolerance() === 0.0 && $cust($L['l7'])->profile_completeness === 'complete', 'after the switch nothing changes until the profile is recalculated (no mass rewrite)');
    $recalc($L['l7']);
    $c = $cust($L['l7']);
    ok($c->profile_completeness === 'incomplete' && $count('deficiency_cases', $L['l7']) === 1, 'on recalculation: L7 → incomplete + one deficiency case');
    ok($before === ['inv' => $count('invoices', $L['l7']), 'int' => $count('interactions', $L['l7']), 'journey' => $c->journey_state], 'no data lost: invoices, interactions and recorded route kept');
    $z = $mk('Z مغایرت ۱۰۰ تومان', [10000000], 10000100, $ag1);
    ok($cust($z)->profile_completeness === 'incomplete', 'zero rule: a 100 Toman mismatch is INCOMPLETE');
    $z2 = $mk('Z بدون مغایرت', [10000000], 10000000, $ag1);
    ok($cust($z2)->profile_completeness === 'complete', 'zero rule: equal amounts are COMPLETE');
    $pre = Biawin_CRM_Frontend::class;
    $_POST = ['customer_id' => $z, 'declared_paid' => '10000000', 'nonce' => wp_create_nonce('bcrm_front_ajax')]; $_REQUEST = $_POST;
    add_filter('wp_doing_ajax', '__return_true'); add_filter('wp_die_ajax_handler', function () { return function () { throw new RuntimeException('E'); }; });
    ob_start(); try { do_action('wp_ajax_bcrm_preview_reconciliation'); } catch (RuntimeException $e) {} $j = json_decode(ob_get_clean(), true);
    ok(!empty($j['data']['complete']), 'preview uses the same rule (equal → complete)');
    break;

case 'fresh':
    ok(get_option($W::COMPLETENESS_RULE_OPTION) === 'zero' && $W::completeness_rule() === 'zero', 'fresh install: business rule (mismatch 0) active');
    $u = get_user_by('id', 1);
    $f = $mk('F تازه', [10000000], 10000500, $u);
    ok($cust($f)->profile_completeness === 'incomplete', 'fresh: 500 Toman mismatch → incomplete');
    break;
default:
    exit("unknown step\n");
}
echo "TOTAL: $pass passed, $fail failed\n";
