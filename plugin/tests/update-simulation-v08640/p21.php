<?php
/**
 * v0.8.64.1 — Phase 2.1 (inquiry architecture hardening) tests on REAL WordPress + MySQL.
 *   php p21.php history64    (on 0.8.64.0) Phase 2 data: subject, complaint, portal+manual inquiries,
 *                            admin inquiry configs incl. an inactive-but-inquiry-enabled unit and an
 *                            invalid stored responder value
 *   php p21.php functional   tests 1–9 (+ 10 for records created before) through the real handlers
 * Preservation/idempotency (tests 10–16) use p2.php snap/same; failure+retry (17) uses run-p2.sh.
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
$now = current_time('mysql');
$C = 'Biawin_CRM_After_Sales_Config';
$unit = static fn($key) => $wpdb->get_row($wpdb->prepare("SELECT * FROM {$P}service_units WHERE unit_key=%s", $key), ARRAY_A);

if ($step === 'history64') {
    $cust = (int)$wpdb->get_var("SELECT id FROM {$P}customers ORDER BY id LIMIT 1");
    $wpdb->update("{$P}customers", ['declared_paid' => 5000000], ['id' => $cust]);
    $s = $C::save_item($C::CATALOG_SUBJECT, ['label' => 'تاخیر', 'item_key' => 'delay', 'is_active' => 1]);
    $c = Biawin_CRM_Workflow_Service::create_complaint($cust, ['subject_id' => (string)$s['id'], 'description' => 'phase2 record']);
    $fin = $unit('finance_approval'); $loan = $unit('loan');
    $a = $C::request_portal_inquiry((int)$c['id'], (int)$fin['id'], 'سؤال پرتالی');
    $b = $C::record_manual_inquiry((int)$c['id'], (int)$loan['id'], 'پاسخ تلفنی', 'یادداشت', '2026-10-01 09:00:00');
    // Admin decisions on 0.8.64.0 that must survive: responders, an inactive unit with inquiry enabled,
    // and one invalid stored value (as if hand-edited) — preserved, normalized only at read time.
    $wpdb->update("{$P}service_units", ['inquiry_responders' => 'supervisor'], ['unit_key' => 'loan']);
    $wpdb->update("{$P}service_units", ['is_active' => 0, 'can_receive_inquiry' => 1], ['unit_key' => 'treasury']);
    $wpdb->update("{$P}service_units", ['inquiry_responders' => 'xyz'], ['unit_key' => 'services']);
    echo 'history64: subject ' . ($s['ok'] ? 'ok' : $s['message']) . ', complaint ' . (!empty($c['ok']) ? 'ok' : ($c['message'] ?? '?')) . ', inquiries ' . (!empty($a['ok']) && !empty($b['ok']) ? 'ok' : 'FAILED') . "; db error: '" . $wpdb->last_error . "'\n";
    exit;
}

if ($step !== 'functional') exit("unknown step\n");

add_filter('wp_redirect', function ($loc) { throw new RuntimeException('REDIRECT ' . $loc); });
add_filter('wp_die_handler', function () { return function ($msg) { throw new RuntimeException('DIE ' . (is_string($msg) ? $msg : '')); }; });
$post = function (string $action, string $nonce, array $fields) {
    $_POST = $fields + ['action' => $action];
    $_REQUEST = $_POST + ['_wpnonce' => wp_create_nonce($nonce)];
    $_POST['_wpnonce'] = $_REQUEST['_wpnonce'];
    $out = '';
    try { do_action('admin_post_' . $action); } catch (RuntimeException $e) { $out = $e->getMessage(); }
    $notice = get_transient('bcrm_cfg_notice_' . get_current_user_id());
    delete_transient('bcrm_cfg_notice_' . get_current_user_id());
    return ['out' => $out, 'notice' => $notice];
};
$inq = static function (int $id, array $f) use ($post) { // exactly what the inquiry tab posts (hidden 0 + checkbox 1)
    return $post('bcrm_cfg_units_save', 'bcrm_cfg_units_save', ['units' => [$id => ['present' => '1'] + $f]]);
};
$members = static fn($id) => $wpdb->get_results($wpdb->prepare("SELECT * FROM {$P}service_unit_members WHERE unit_id=%d ORDER BY id", $id), ARRAY_A);
$inquiries_before = $wpdb->get_results("SELECT * FROM {$P}complaint_inquiries ORDER BY id", ARRAY_A);
$complaints_before = $wpdb->get_results("SELECT * FROM {$P}complaints ORDER BY id", ARRAY_A);
$sup = get_user_by('login', 'b_supervisor'); $ag = get_user_by('login', 'b_agent');
$ag2 = get_user_by('login', 'b_complaint_agent'); $off = get_user_by('login', 'b_disabled');
$parent = $unit('after_sales');

echo "--- TEST 1 create an active unit (existing «واحدها و اعضا» handler)\n";
$post('bcrm_save_service_unit', 'bcrm_save_service_unit', ['unit_form_v2' => '1', 'unit_key' => 'p21_unit', 'unit_name' => 'واحد آزمون ۲.۱', 'unit_type' => 'other', 'parent_unit_id' => (string)$parent['id'], 'is_active' => '1', 'can_receive_inquiry' => '1', 'requires_portal_response' => '1', 'display_order' => '77', 'supervisor_user_id' => (string)$sup->ID, 'agent_user_ids' => [(string)$ag->ID, (string)$ag2->ID, (string)$off->ID]]);
$u = $unit('p21_unit'); $id = (int)$u['id'];
ok($u && (int)$u['is_active'] === 1 && (int)$u['can_receive_inquiry'] === 1, 'TEST 1 active = 1 (inquiry enabled = 1)');
$members_t1 = $members($id);

echo "--- TEST 2 disable inquiry\n";
$r = $inq($id, ['inquiry_enabled' => '0', 'requires_portal_response' => '0', 'inquiry_responders' => 'members']);
$u2 = $unit('p21_unit');
ok((int)$u2['can_receive_inquiry'] === 0 && (int)$u2['is_active'] === 1, 'TEST 2 inquiry_enabled = 0, active = 1 (unit stays active)');
ok(!in_array('p21_unit', array_map(static fn($x) => $x->unit_key, $C::inquiry_units(false)), true), 'TEST 2 unit leaves the inquiry-provider list');

echo "--- TEST 3 re-enable inquiry\n";
$inq($id, ['inquiry_enabled' => '1', 'requires_portal_response' => '1', 'inquiry_responders' => 'members']);
$u3 = $unit('p21_unit');
ok((int)$u3['can_receive_inquiry'] === 1 && (int)$u3['is_active'] === 1 && (int)$u3['requires_portal_response'] === 1, 'TEST 3 inquiry_enabled = 1, active = 1');

echo "--- TEST 4 disable the unit itself («واحدها و اعضا»)\n";
$post('bcrm_save_service_unit', 'bcrm_save_service_unit', ['unit_form_v2' => '1', 'unit_key' => 'p21_unit', 'unit_name' => 'واحد آزمون ۲.۱', 'unit_type' => 'other', 'parent_unit_id' => (string)$parent['id'], 'can_receive_inquiry' => '1', 'requires_portal_response' => '1', 'display_order' => '77', 'supervisor_user_id' => (string)$sup->ID, 'agent_user_ids' => [(string)$ag->ID, (string)$ag2->ID, (string)$off->ID]]);
$u4 = $unit('p21_unit');
ok((int)$u4['is_active'] === 0 && (int)$u4['can_receive_inquiry'] === 1 && $u4['inquiry_responders'] === 'members', 'TEST 4 active = 0, inquiry_enabled unchanged (1), responders unchanged');
ok(!$C::unit_accepts_inquiries((object)$u4) && !in_array('p21_unit', array_map(static fn($x) => $x->unit_key, $C::inquiry_units()), true), 'TEST 4 inactive unit is not operational (eligibility = active AND inquiry enabled)');
$cid = (int)$wpdb->get_var("SELECT id FROM {$P}complaints ORDER BY id DESC LIMIT 1");
$blocked = $C::request_portal_inquiry($cid, $id, 'x');
ok(empty($blocked['ok']) && strpos((string)$blocked['message'], 'غیرفعال') !== false, 'TEST 4 inquiry to the inactive unit refused ("واحد غیرفعال است")');
$tre = $unit('treasury');
ok((int)$tre['is_active'] === 0 && (int)$tre['can_receive_inquiry'] === 1 && empty($C::record_manual_inquiry($cid, (int)$tre['id'], 'x')['ok']), 'TEST 4 pre-existing inactive+inquiry-enabled unit (treasury) also refused, its flags kept');
$inq($id, ['inquiry_enabled' => '1', 'requires_portal_response' => '1', 'inquiry_responders' => 'members']);
ok((int)$unit('p21_unit')['is_active'] === 0, 'TEST 4 saving inquiry settings cannot re-activate the unit');
$post('bcrm_save_service_unit', 'bcrm_save_service_unit', ['unit_form_v2' => '1', 'unit_key' => 'p21_unit', 'unit_name' => 'واحد آزمون ۲.۱', 'unit_type' => 'other', 'parent_unit_id' => (string)$parent['id'], 'is_active' => '1', 'can_receive_inquiry' => '1', 'requires_portal_response' => '1', 'display_order' => '77', 'supervisor_user_id' => (string)$sup->ID, 'agent_user_ids' => [(string)$ag->ID, (string)$ag2->ID, (string)$off->ID]]);
update_user_meta($off->ID, 'bcrm_disabled', 1);

echo "--- TEST 5–7 responder resolver\n";
$sorted = static function (array $a) { sort($a); return $a; };
$inq($id, ['inquiry_enabled' => '1', 'requires_portal_response' => '1', 'inquiry_responders' => 'members']);
ok($sorted($C::get_inquiry_responders($id)) === $sorted([(int)$ag->ID, (int)$ag2->ID]), 'TEST 5 members → only the unit\'s eligible member agents (disabled member excluded, supervisor excluded)');
$inq($id, ['inquiry_enabled' => '1', 'requires_portal_response' => '1', 'inquiry_responders' => 'supervisor']);
ok($C::get_inquiry_responders($id) === [(int)$sup->ID], 'TEST 6 supervisor → the unit supervisor only');
$inq($id, ['inquiry_enabled' => '1', 'requires_portal_response' => '1', 'inquiry_responders' => 'both']);
ok($sorted($C::get_inquiry_responders($id)) === $sorted([(int)$sup->ID, (int)$ag->ID, (int)$ag2->ID]), 'TEST 7 both → members + supervisor (no duplicates)');
$users_before = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
$m_before = $members($id); $roles_before = get_option($wpdb->prefix . 'user_roles');
for ($i = 0; $i < 3; $i++) $C::get_inquiry_responders($id);
ok($users_before === (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}") && $m_before === $members($id) && $roles_before === get_option($wpdb->prefix . 'user_roles'), 'TEST 5–7 resolver is read-only (no users, memberships or roles changed)');
$loan = $unit('loan');
ok($loan['inquiry_responders'] === 'supervisor', 'TEST 5–7 value chosen on 0.8.64.0 (loan = supervisor) preserved');

echo "--- TEST 8 invalid responder mode / booleans\n";
foreach (['admin', 'ALL', '', 'members;drop', 'Members '] as $bad) {
    $before = $unit('p21_unit');
    $r = $C::save_unit_inquiry_config($id, ['inquiry_enabled' => '1', 'requires_portal_response' => '1', 'inquiry_responders' => $bad]);
    $norm = in_array(strtolower(trim($bad)), ['members', 'supervisor', 'both'], true);
    ok($norm ? (!empty($r['ok']) && $unit('p21_unit')['inquiry_responders'] === 'members') : (empty($r['ok']) && $unit('p21_unit') === $before), 'TEST 8 mode ' . json_encode($bad) . ($norm ? ' normalized (case/space) to a valid mode' : ' rejected, nothing written'));
}
$r = $C::save_unit_inquiry_config($id, ['inquiry_enabled' => '2', 'requires_portal_response' => '1', 'inquiry_responders' => 'both']);
$r2 = $C::save_unit_inquiry_config($id, ['inquiry_enabled' => ['1'], 'requires_portal_response' => '1', 'inquiry_responders' => 'both']);
$r3 = $C::save_unit_inquiry_config(999999, ['inquiry_enabled' => '1', 'requires_portal_response' => '1', 'inquiry_responders' => 'both']);
ok(empty($r['ok']) && empty($r2['ok']) && empty($r3['ok']), 'TEST 8 invalid boolean / array / unknown unit rejected server-side');
$svc = $unit('services');
ok($svc['inquiry_responders'] === 'xyz' && $C::normalize_responders($svc['inquiry_responders']) === 'members', 'TEST 8 invalid STORED value: preserved in DB, resolved safely as "members" at read time');
$inq((int)$svc['id'], ['inquiry_enabled' => (string)$svc['can_receive_inquiry'], 'requires_portal_response' => (string)$svc['requires_portal_response'], 'inquiry_responders' => 'members']);
ok($unit('services')['inquiry_responders'] === 'members' && (int)$unit('services')['can_receive_inquiry'] === (int)$svc['can_receive_inquiry'], 'TEST 8 an admin save normalizes only that invalid value');

echo "--- TEST 9 inquiry save touches nothing else\n";
$full_before = $unit('p21_unit'); $mem_before = $members($id);
$queries = [];
add_filter('query', function ($q) use (&$queries) { if (stripos($q, 'UPDATE') === 0 && strpos($q, 'service_units') !== false) $queries[] = $q; return $q; });
$other = $unit('dissatisfaction');
$r = $post('bcrm_cfg_units_save', 'bcrm_cfg_units_save', ['units' => [$id => ['present' => '1', 'inquiry_enabled' => '0', 'requires_portal_response' => '0', 'inquiry_responders' => 'supervisor',
    'is_active' => '0', 'unit_name' => 'HACK', 'parent_unit_id' => '0', 'supervisor_user_id' => (string)$ag->ID, 'display_order' => '1', 'agent_user_ids' => [(string)$admin->ID], 'updated_at' => '2000-01-01 00:00:00']]]);
$full_after = $unit('p21_unit');
$changed = array_keys(array_diff_assoc(array_map('strval', $full_after), array_map('strval', $full_before)));
sort($changed);
ok($changed === ['can_receive_inquiry', 'inquiry_responders', 'requires_portal_response'], 'TEST 9 only inquiry columns changed (' . implode(',', $changed) . ')');
ok($full_after['unit_name'] === $full_before['unit_name'] && $full_after['parent_unit_id'] === $full_before['parent_unit_id'] && $full_after['supervisor_user_id'] === $full_before['supervisor_user_id'] && $full_after['is_active'] === $full_before['is_active'] && $full_after['display_order'] === $full_before['display_order'] && $full_after['updated_at'] === $full_before['updated_at'], 'TEST 9 name, parent, supervisor, active, order, updated_at unchanged');
ok($mem_before === $members($id), 'TEST 9 members unchanged');
ok(count($queries) === 1 && preg_match('/^UPDATE `[^`]+service_units` SET (.*) WHERE `id` = \d+$/', $queries[0], $mm) && !preg_match('/is_active|unit_name|parent_unit_id|supervisor_user_id|display_order|updated_at/', $mm[1]), 'TEST 9 the SQL UPDATE is scoped to inquiry columns: ' . ($queries[0] ?? '-'));
ok($unit('dissatisfaction') === $other, 'TEST 9 other units untouched');

echo "--- TEST 10/11 records created before\n";
ok($inquiries_before === $wpdb->get_results("SELECT * FROM {$P}complaint_inquiries ORDER BY id", ARRAY_A) && count($inquiries_before) >= 2, 'TEST 10 existing inquiry records unchanged (' . count($inquiries_before) . ', portal + manual)');
ok($complaints_before === $wpdb->get_results("SELECT * FROM {$P}complaints ORDER BY id", ARRAY_A), 'TEST 11 existing complaint records unchanged');

echo "--- capabilities\n";
wp_set_current_user($ag->ID);
$d = $post('bcrm_cfg_units_save', 'bcrm_cfg_units_save', ['units' => [$id => ['present' => '1', 'inquiry_enabled' => '1', 'requires_portal_response' => '1', 'inquiry_responders' => 'both']]]);
wp_set_current_user($admin->ID);
ok(strpos($d['out'], 'DIE') === 0 && $unit('p21_unit') === $full_after, 'normal agent cannot change inquiry settings');
$_GET = ['tab' => 'units']; ob_start(); Biawin_CRM_After_Sales_Config_Admin::page(); $html = ob_get_clean();
ok(strpos($html, 'type="radio" name="units[' . $id . '][inquiry_responders]" value="both"') !== false && strpos($html, 'name="units[' . $id . '][is_active]"') === false && strpos($html, 'name="units[' . $id . '][display_order]"') === false && strpos($html, 'وضعیت واحد') !== false && strpos($html, 'غیرفعال (واحد)') !== false, 'UI: radio responders, no active/order inputs, unit status shown read-only, inactive+enabled marked');
echo "TOTAL: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
