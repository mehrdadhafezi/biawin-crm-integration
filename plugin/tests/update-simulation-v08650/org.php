<?php
/**
 * v0.8.65.0 — Master Phase 1 (organization / units / roles / permissions) on REAL WordPress + MySQL.
 *   php org.php prep              (on 0.8.64.1) admin decisions that must survive: a plugin role deleted,
 *                                 a plugin cap removed, a custom legacy-role label, an existing treasury
 *                                 parent, memberships in after-sales / dissatisfaction / treasury
 *   php org.php snap NAME         full snapshot (users, roles, units, members, catalog, business, ledgers)
 *   php org.php verify A B        update A → B: exactly the expected additive changes, nothing else
 *   php org.php same A B          A and B identical (migration idempotency)
 *   php org.php functional        tests 1–9 through the real admin handlers + capability checks
 *   php org.php fresh             fresh install of the new build: final organization present
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
$out_dir = __DIR__;
$unit = static fn($key) => $wpdb->get_row($wpdb->prepare("SELECT * FROM {$P}service_units WHERE unit_key=%s", $key), ARRAY_A);
$NEW_ROLES = [
    'bcrm_dissatisfaction_supervisor' => ['read','bcrm_work_cases','bcrm_complaints','view_after_sales','view_dissatisfaction','manage_dissatisfaction','assign_dissatisfaction','process_dissatisfaction','view_after_sales_reports'],
    'bcrm_after_sales_agent' => ['read','bcrm_work_cases','bcrm_complaints','view_after_sales','view_dissatisfaction','process_dissatisfaction'],
    'bcrm_it_supervisor' => ['read','bcrm_work_cases','view_it_inquiries','respond_it_inquiries'],
    'bcrm_it_agent' => ['read','bcrm_work_cases','view_it_inquiries','respond_it_inquiries'],
];
$caps_of = static function ($role_def) { $c = array_keys(array_filter((array)($role_def['capabilities'] ?? []))); sort($c); return $c; };
$sorted = static function (array $a) { sort($a); return $a; };

switch ($step) {
case 'prep':
    // A role the plugin created earlier and an admin deleted; a cap the plugin granted and an admin removed;
    // a custom label on a legacy role; treasury still under after-sales (0.8.63 layout); real memberships.
    remove_role('bcrm_loan_agent');
    get_role('bcrm_complaint_supervisor')->remove_cap('assign_dissatisfaction');
    $roles = wp_roles();
    $roles->roles['bcrm_complaint_agent']['name'] = 'برچسب سفارشی مدیر';
    update_option($roles->role_key, $roles->roles);
    $as = $unit('after_sales');
    $wpdb->update("{$P}service_units", ['parent_unit_id' => (int)$as['id']], ['unit_key' => 'treasury']);
    $sup = get_user_by('login', 'b_supervisor'); $ag = get_user_by('login', 'b_agent'); $ca = get_user_by('login', 'b_complaint_agent');
    foreach ([['after_sales', $sup->ID, 'supervisor'], ['dissatisfaction', $ca->ID, 'agent'], ['treasury', $ag->ID, 'agent']] as [$k, $uid, $r]) {
        $wpdb->insert("{$P}service_unit_members", ['unit_id' => (int)$unit($k)['id'], 'user_id' => $uid, 'member_role' => $r, 'created_at' => $now]);
    }
    echo "prep done; loan_agent role " . (get_role('bcrm_loan_agent') ? 'present' : 'deleted') . "; db error: '" . $wpdb->last_error . "'\n";
    exit;

case 'snap':
    $snap = ['users' => [], 'roles' => get_option($wpdb->prefix . 'user_roles')];
    foreach (get_users(['orderby' => 'ID']) as $u) {
        $snap['users'][$u->ID] = [$u->user_login, $u->user_pass, $u->user_email, $u->display_name, $u->roles, $u->caps, get_user_meta($u->ID, 'bcrm_disabled', true)];
    }
    foreach (['service_units', 'service_unit_members', 'service_catalog', 'customers', 'invoices', 'phones', 'assignments', 'interactions', 'complaints', 'complaint_inquiries', 'catalog_items', 'refunds', 'refund_registers', 'deficiency_cases', 'case_actions', 'financial_cases', 'service_cases', 'customer_tags'] as $t) {
        $snap['t'][$t] = $wpdb->get_results("SELECT * FROM {$P}$t ORDER BY id", ARRAY_A);
    }
    foreach (['biawin_crm_service_seed_ledger', 'biawin_crm_unit_seed_ledger', 'biawin_crm_catalog_seed_ledger', 'biawin_crm_caps_ledger', 'biawin_crm_reconciliation_tolerance', 'biawin_crm_credit_multiplier'] as $o) $snap['o'][$o] = get_option($o);
    $snap['meta'] = ['plugin' => BIAWIN_CRM_VERSION, 'db' => get_option('biawin_crm_db_version'), 'marker' => get_option('biawin_crm_caps_marker')];
    file_put_contents("$out_dir/org-{$argv[2]}.json", json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "snapshot {$argv[2]} (plugin {$snap['meta']['plugin']}, db {$snap['meta']['db']}, caps marker {$snap['meta']['marker']})\n";
    exit;

case 'verify':
case 'same':
    $a = json_decode(file_get_contents("$out_dir/org-{$argv[2]}.json"), true);
    $b = json_decode(file_get_contents("$out_dir/org-{$argv[3]}.json"), true);
    ok($a['users'] === $b['users'], 'TEST 10 existing users preserved: ID, login, password hash, email, name, roles, per-user caps, disabled flag');
    foreach (['service_catalog', 'customers', 'invoices', 'phones', 'assignments', 'interactions', 'complaints', 'complaint_inquiries', 'catalog_items', 'refunds', 'refund_registers', 'deficiency_cases', 'case_actions', 'financial_cases', 'service_cases', 'customer_tags'] as $t) {
        ok($a['t'][$t] === $b['t'][$t], "data untouched: $t (" . count((array)$b['t'][$t]) . ' rows)');
    }
    ok($a['t']['service_unit_members'] === $b['t']['service_unit_members'], 'unit memberships untouched (none overwritten/duplicated/created)');
    ok($a['o']['biawin_crm_service_seed_ledger'] === $b['o']['biawin_crm_service_seed_ledger'] && $a['o']['biawin_crm_catalog_seed_ledger'] === $b['o']['biawin_crm_catalog_seed_ledger'], 'service/catalog seed ledgers untouched');
    ok($a['o']['biawin_crm_reconciliation_tolerance'] === $b['o']['biawin_crm_reconciliation_tolerance'] && $a['o']['biawin_crm_credit_multiplier'] === $b['o']['biawin_crm_credit_multiplier'], 'admin configuration (tolerance, credit) untouched');
    $ua = []; foreach ($a['t']['service_units'] as $r) $ua[$r['unit_key']] = $r;
    $ub = []; foreach ($b['t']['service_units'] as $r) $ub[$r['unit_key']] = $r;
    $changed = []; foreach ($ua as $k => $r) if (($ub[$k] ?? null) !== $r) $changed[] = $k;
    ok(!$changed, 'existing units byte-identical (name, parent, type, supervisor, active, inquiry, order, timestamps)' . ($changed ? ' — ' . implode(',', $changed) : ''));
    if ($step === 'same') {
        ok(array_keys($ua) === array_keys($ub), 'no unit added on re-run (no duplicates)');
        ok($a['roles'] === $b['roles'], 'roles + capabilities identical on re-run');
        ok($a['o']['biawin_crm_caps_ledger'] === $b['o']['biawin_crm_caps_ledger'] && $a['o']['biawin_crm_unit_seed_ledger'] === $b['o']['biawin_crm_unit_seed_ledger'], 'ledgers identical on re-run');
        break;
    }
    $added_units = array_values(array_diff(array_keys($ub), array_keys($ua)));
    ok($added_units === ['it_engineering'], 'TEST 4 exactly one unit added: it_engineering');
    $it = $ub['it_engineering'] ?? [];
    ok(($it['unit_name'] ?? '') === 'مهندسین IT' && ($it['unit_type'] ?? '') === 'it' && $it['parent_unit_id'] === null && (int)$it['is_active'] === 1 && (int)$it['can_receive_inquiry'] === 1 && (int)$it['requires_portal_response'] === 1 && $it['supervisor_user_id'] === null, 'TEST 4 IT unit: top-level, active, inquiry-enabled, portal response, no supervisor assigned automatically');
    ok((int)$ua['treasury']['parent_unit_id'] === (int)$ua['after_sales']['id'] && $ua['treasury'] === $ub['treasury'], 'TEST 7 existing treasury unit NOT moved (admin layout kept)');
    // roles
    $ra = $a['roles']; $rb = $b['roles'];
    $removed = []; $added = [];
    foreach ($ra as $role => $def) {
        if (!isset($rb[$role])) { $removed[] = "$role(role)"; continue; }
        foreach ((array)$def['capabilities'] as $cap => $g) if (!array_key_exists($cap, (array)$rb[$role]['capabilities']) || $rb[$role]['capabilities'][$cap] !== $g) $removed[] = "$role:$cap";
        foreach ((array)$rb[$role]['capabilities'] as $cap => $g) if (!array_key_exists($cap, (array)$def['capabilities'])) $added[] = "$role:$cap";
    }
    ok(!$removed, 'TEST 11/13 no existing role or capability removed/changed' . ($removed ? ' — ' . implode(', ', $removed) : ''));
    sort($added);
    $expected = ['administrator:respond_it_inquiries', 'administrator:view_it_inquiries', 'bcrm_after_sales_manager:view_it_inquiries'];
    if (isset($ra['bcrm_portal_admin'])) { $expected[] = 'bcrm_portal_admin:respond_it_inquiries'; $expected[] = 'bcrm_portal_admin:view_it_inquiries'; }
    sort($expected);
    ok($added === $expected, 'TEST 9 existing roles gain ONLY the IT inquiry caps on admin/portal admin/after-sales manager: ' . implode(', ', $added));
    ok(!isset($rb['bcrm_loan_agent']), 'TEST 12 role deleted by an admin (bcrm_loan_agent) is NOT recreated');
    ok(empty($rb['bcrm_complaint_supervisor']['capabilities']['assign_dissatisfaction']), 'TEST 13 cap removed by an admin (complaint supervisor: assign_dissatisfaction) is NOT re-granted');
    ok(($rb['bcrm_supervisor'] ?? null) === ($ra['bcrm_supervisor'] ?? null), 'TEST 13 general supervisor unchanged (no silent reduction, no addition)');
    $created = array_values(array_diff(array_keys($rb), array_keys($ra))); sort($created);
    ok($created === $sorted(array_keys($NEW_ROLES)), 'exactly the 4 final roles created: ' . implode(', ', $created));
    foreach ($NEW_ROLES as $role => $caps) ok($caps_of($rb[$role] ?? []) === $sorted($caps), "TEST 9 $role caps = " . implode(',', $sorted($caps)));
    ok(($rb['bcrm_complaint_supervisor']['name'] ?? '') === 'سرپرست نارضایتی‌ها (قدیمی) CRM', 'legacy complaint supervisor kept, default label marked «(قدیمی)»');
    ok(($rb['bcrm_complaint_agent']['name'] ?? '') === 'برچسب سفارشی مدیر', 'legacy complaint agent kept, admin custom label untouched');
    ok($b['meta']['plugin'] === '0.8.65.0' && $b['meta']['db'] === '0.8.65.0' && (int)$b['meta']['marker'] === 6, 'plugin 0.8.65.0, db_version 0.8.65.0, caps marker 6');
    break;

case 'functional':
    add_filter('wp_redirect', function ($loc) { throw new RuntimeException('REDIRECT ' . $loc); });
    add_filter('wp_die_handler', function () { return function ($msg) { throw new RuntimeException('DIE ' . (is_string($msg) ? $msg : '')); }; });
    $post = function (string $action, string $nonce, array $fields) {
        $_POST = $fields + ['action' => $action];
        $_REQUEST = $_POST + ['_wpnonce' => wp_create_nonce($nonce)];
        $_POST['_wpnonce'] = $_REQUEST['_wpnonce'];
        try { do_action('admin_post_' . $action); } catch (RuntimeException $e) { return $e->getMessage(); }
        return '';
    };
    $mkuser = function (string $login, string $role) use ($post) {
        $r = $post('bcrm_save_portal_user', 'bcrm_save_portal_user', ['display_name' => $login, 'username' => $login, 'password' => 'Pw-' . bin2hex(random_bytes(6)), 'email' => "$login@example.test", 'role' => $role]);
        $u = get_user_by('login', $login);
        ok($u && in_array($role, (array)$u->roles, true) && strpos($r, 'REDIRECT') === 0, "user $login created as $role through «کاربران پرتال»");
        return $u;
    };
    $members = static fn(int $id) => $wpdb->get_results($wpdb->prepare("SELECT user_id, member_role FROM {$P}service_unit_members WHERE unit_id=%d ORDER BY member_role, user_id", $id), ARRAY_A);
    $pair = static fn(array $rows) => array_map(static fn($r) => $r['member_role'] . ':' . $r['user_id'], $rows);

    echo "--- TEST 5/6 IT unit: supervisor + agents (existing «واحدها و اعضا» handler)\n";
    $its = $mkuser('it_sup', 'bcrm_it_supervisor'); $ita1 = $mkuser('it_ag1', 'bcrm_it_agent'); $ita2 = $mkuser('it_ag2', 'bcrm_it_agent');
    $it = $unit('it_engineering');
    $r = $post('bcrm_save_service_unit', 'bcrm_save_service_unit', ['unit_form_v2' => '1', 'unit_key' => 'it_engineering', 'unit_name' => $it['unit_name'], 'unit_type' => 'it', 'parent_unit_id' => '0', 'is_active' => '1', 'can_receive_inquiry' => '1', 'requires_portal_response' => '1', 'display_order' => (string)$it['display_order'], 'supervisor_user_id' => (string)$its->ID, 'agent_user_ids' => [(string)$ita1->ID, (string)$ita2->ID]]);
    $it2 = $unit('it_engineering');
    ok(strpos($r, 'REDIRECT') === 0 && (int)$it2['supervisor_user_id'] === $its->ID && $it2['unit_type'] === 'it', 'TEST 1/5 IT unit configured; supervisor = IT supervisor');
    ok($pair($members((int)$it2['id'])) === ['agent:' . $ita1->ID, 'agent:' . $ita2->ID, 'supervisor:' . $its->ID], 'TEST 2/3/6 membership: 1 supervisor + 2 agents, no duplicates');
    $resp = Biawin_CRM_After_Sales_Config::get_inquiry_responders((int)$it2['id']); sort($resp);
    $exp = [$ita1->ID, $ita2->ID]; sort($exp);
    ok(Biawin_CRM_After_Sales_Config::unit_accepts_inquiries((object)$it2) && $resp === $exp, 'TEST 4 IT unit is an inquiry provider; responders (mode members) = IT agents');
    ok(in_array('it_engineering', array_map(static fn($u) => $u->unit_key, Biawin_CRM_After_Sales_Config::inquiry_units(true)), true), 'TEST 4 IT unit listed among operational inquiry units');

    echo "--- TEST 8 After-Sales: manager → dissatisfaction supervisor → after-sales agents\n";
    $mgr = $mkuser('as_mgr', 'bcrm_after_sales_manager'); $dsup = $mkuser('dis_sup', 'bcrm_dissatisfaction_supervisor');
    $asa1 = $mkuser('as_ag1', 'bcrm_after_sales_agent'); $asa2 = $mkuser('as_ag2', 'bcrm_after_sales_agent');
    $as = $unit('after_sales'); $dis = $unit('dissatisfaction');
    $before_as = $members((int)$as['id']);
    $post('bcrm_save_service_unit', 'bcrm_save_service_unit', ['unit_form_v2' => '1', 'unit_key' => 'after_sales', 'unit_name' => $as['unit_name'], 'unit_type' => 'after_sales', 'parent_unit_id' => '0', 'is_active' => '1', 'display_order' => (string)$as['display_order'], 'supervisor_user_id' => (string)$mgr->ID]);
    $post('bcrm_save_service_unit', 'bcrm_save_service_unit', ['unit_form_v2' => '1', 'unit_key' => 'dissatisfaction', 'unit_name' => $dis['unit_name'], 'unit_type' => 'dissatisfaction', 'parent_unit_id' => (string)$as['id'], 'is_active' => '1', 'display_order' => (string)$dis['display_order'], 'supervisor_user_id' => (string)$dsup->ID, 'agent_user_ids' => [(string)$asa1->ID, (string)$asa2->ID]]);
    ok((int)$unit('after_sales')['supervisor_user_id'] === $mgr->ID && (int)$unit('dissatisfaction')['supervisor_user_id'] === $dsup->ID && (int)$unit('dissatisfaction')['parent_unit_id'] === (int)$as['id'], 'TEST 8 after_sales head = manager; dissatisfaction (child) head = dissatisfaction supervisor');
    ok($pair($members((int)$dis['id'])) === ['agent:' . $asa1->ID, 'agent:' . $asa2->ID, 'supervisor:' . $dsup->ID], 'TEST 8 dissatisfaction members = 2 after-sales agents + supervisor');
    echo "  (note: saving a unit in «واحدها و اعضا» replaces that unit's member list by design — existing handler; before: " . count($before_as) . " member row(s) on after_sales)\n";

    echo "--- TEST 7 Treasury: supervisor + agents\n";
    $tsup = $mkuser('tr_sup', 'bcrm_treasury_supervisor'); $tag = $mkuser('tr_ag', 'bcrm_treasury_agent');
    $tr = $unit('treasury');
    $post('bcrm_save_service_unit', 'bcrm_save_service_unit', ['unit_form_v2' => '1', 'unit_key' => 'treasury', 'unit_name' => $tr['unit_name'], 'unit_type' => 'treasury', 'parent_unit_id' => (string)(int)$tr['parent_unit_id'], 'is_active' => (string)(int)$tr['is_active'], 'can_receive_inquiry' => (string)(int)$tr['can_receive_inquiry'], 'requires_portal_response' => (string)(int)$tr['requires_portal_response'], 'display_order' => (string)$tr['display_order'], 'supervisor_user_id' => (string)$tsup->ID, 'agent_user_ids' => [(string)$tag->ID]]);
    ok((int)$unit('treasury')['supervisor_user_id'] === $tsup->ID && $pair($members((int)$tr['id'])) === ['agent:' . $tag->ID, 'supervisor:' . $tsup->ID], 'TEST 7 treasury: supervisor + agent');

    echo "--- TEST 9 capabilities (least privilege, real user_can)\n";
    $can = static fn($u, $c) => user_can($u, $c);
    ok($can($its, 'respond_it_inquiries') && $can($ita1, 'view_it_inquiries') && $can($ita1, 'respond_it_inquiries'), 'IT supervisor/agent can view + answer IT inquiries');
    $deny = ['bcrm_complaints', 'bcrm_manage_cases', 'view_dissatisfaction', 'view_finance_inquiries', 'view_treasury', 'bcrm_refunds', 'bcrm_import', 'manage_inquiry_units'];
    ok(!array_filter($deny, static fn($c) => $can($ita1, $c) || $can($its, $c)), 'IT users: no complaint, finance, treasury, refund, import or admin capability');
    ok($can($mgr, 'view_it_inquiries') && !$can($mgr, 'respond_it_inquiries') && $can($mgr, 'manage_dissatisfaction_outcomes') && !$can($mgr, 'bcrm_manage_cases'), 'after-sales manager: views IT inquiries, manages settings, no general admin');
    echo "--- IT isolation: after-sales manager has no IT management\n";
    wp_set_current_user($mgr->ID);
    $r = $post('bcrm_save_service_unit', 'bcrm_save_service_unit', ['unit_form_v2' => '1', 'unit_key' => 'it_engineering', 'unit_name' => 'hijack', 'unit_type' => 'it', 'supervisor_user_id' => (string)$mgr->ID]);
    $r2 = $post('bcrm_cfg_units_save', 'bcrm_cfg_units_save', ['units' => [(int)$it2['id'] => ['present' => '1', 'inquiry_enabled' => '0', 'requires_portal_response' => '0', 'inquiry_responders' => 'supervisor']]]);
    $r3 = $post('bcrm_save_portal_user', 'bcrm_save_portal_user', ['display_name' => 'x', 'username' => 'mgr_made_it', 'password' => 'Pw-12345678', 'role' => 'bcrm_it_agent']);
    wp_set_current_user($admin->ID);
    $it3 = $unit('it_engineering');
    ok(strpos($r, 'DIE') === 0 && strpos($r2, 'DIE') === 0 && strpos($r3, 'DIE') === 0, 'manager is refused: IT unit/member edit, IT inquiry-unit config, IT user creation');
    ok($it3 === $it2 && $pair($members((int)$it2['id'])) === ['agent:' . $ita1->ID, 'agent:' . $ita2->ID, 'supervisor:' . $its->ID] && !get_user_by('login', 'mgr_made_it'), 'IT unit, members and users unchanged after the refused attempts');
    ok(!$can($mgr, 'manage_inquiry_units') && !$can($mgr, 'bcrm_manage_cases') && !$can($mgr, 'respond_it_inquiries'), 'manager caps: no IT unit/member/assignment/admin capability');
    ok($it3['parent_unit_id'] === null && !in_array((int)$it3['id'], array_map('intval', $wpdb->get_col("SELECT parent_unit_id FROM {$P}service_units WHERE parent_unit_id IS NOT NULL")), true), 'IT unit independent: top-level, nothing nested under it');
    ok(!array_intersect([$its->ID, $ita1->ID, $ita2->ID], array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$P}service_unit_members WHERE unit_id<>%d", (int)$it2['id'])))), 'IT users belong to no other unit');
    ok($can($dsup, 'assign_dissatisfaction') && $can($dsup, 'bcrm_complaints') && !$can($dsup, 'manage_dissatisfaction_outcomes') && !$can($dsup, 'bcrm_manage_cases'), 'dissatisfaction supervisor: assign/manage dissatisfaction, no settings, no general admin');
    ok($can($asa1, 'process_dissatisfaction') && !$can($asa1, 'assign_dissatisfaction') && !$can($asa1, 'manage_dissatisfaction'), 'after-sales agent: process only');
    ok(Biawin_CRM_Auth::is_dissatisfaction_supervisor((array)$dsup->roles) && Biawin_CRM_Auth::is_dissatisfaction_supervisor(['bcrm_complaint_supervisor']) && !Biawin_CRM_Auth::is_dissatisfaction_supervisor((array)$asa1->roles) && !Biawin_CRM_Auth::is_dissatisfaction_supervisor((array)$mgr->roles), 'supervisor visibility: final + legacy supervisor only');
    // Real visibility on the existing complaint (open, owner NULL from history): supervisor and agent both reach it as before.
    $cid = (int)$wpdb->get_var("SELECT id FROM {$P}complaints WHERE status='open' ORDER BY id LIMIT 1");
    if ($cid) {
        // can_manage_complaint() checks bcrm_manage_cases on the CURRENT user, so act as each user (real request context).
        $as_user = static function ($u, $cid) { wp_set_current_user($u->ID); $r = Biawin_CRM_Workflow_Service::can_manage_complaint($cid, $u->ID); wp_set_current_user(get_user_by('login', 'qa_admin')->ID); return $r; };
        $csup = get_user_by('login', 'b_reroled'); // existing user with the legacy complaint agent role
        $legacy_sup = $mkuser('legacy_sup', 'bcrm_complaint_supervisor');
        $wpdb->update("{$P}complaints", ['owner_user_id' => $asa2->ID], ['id' => $cid]);
        ok($as_user($dsup, $cid) && $as_user($legacy_sup, $cid), 'final AND legacy dissatisfaction supervisor manage a complaint owned by another agent (identical rule)');
        ok(!$as_user($asa1, $cid) && $as_user($asa2, $cid), 'after-sales agent: own/unowned complaints only (= legacy agent rule)');
        ok(!$as_user($csup, $cid), 'legacy complaint agent: unchanged rule (not owner → no access)');
        ok(!$as_user($its, $cid) && !$as_user($ita1, $cid), 'IT supervisor/agent cannot manage complaints');
        $wpdb->update("{$P}complaints", ['owner_user_id' => null], ['id' => $cid]);
    }
    foreach (['it_sup', 'as_ag1', 'dis_sup'] as $login) {
        $u = get_user_by('login', $login);
        ok(user_can($u, 'bcrm_work_cases'), "$login can sign in to the portal (bcrm_work_cases)");
    }
    echo "--- delete/recreate protection for the NEW roles\n";
    remove_role('bcrm_it_agent');
    Biawin_CRM_Activator::sync_roles_and_caps();
    ok(!get_role('bcrm_it_agent'), 'TEST 12 a final role deleted by an admin is not recreated by the next sync');
    get_role('bcrm_it_supervisor')->remove_cap('respond_it_inquiries');
    Biawin_CRM_Activator::sync_roles_and_caps();
    ok(!get_role('bcrm_it_supervisor')->has_cap('respond_it_inquiries'), 'TEST 13 a cap removed from a final role by an admin is not re-granted');
    break;

case 'fresh':
    $roles = get_option($wpdb->prefix . 'user_roles');
    foreach ($NEW_ROLES as $role => $caps) ok(isset($roles[$role]) && $caps_of($roles[$role]) === $sorted($caps), "fresh: $role with expected caps");
    ok(isset($roles['bcrm_complaint_supervisor'], $roles['bcrm_complaint_agent']), 'fresh: legacy complaint roles present (compatibility)');
    $sup = $caps_of($roles['bcrm_supervisor']);
    ok(!array_diff(['view_dissatisfaction', 'manage_dissatisfaction', 'assign_dissatisfaction', 'process_dissatisfaction', 'view_after_sales', 'view_after_sales_reports', 'bcrm_complaints', 'bcrm_manage_cases'], $sup), 'fresh: general supervisor behaviour preserved (reduction deferred to Phase 3)');
    $keys = $wpdb->get_col("SELECT unit_key FROM {$P}service_units ORDER BY unit_key");
    foreach (['after_sales', 'dissatisfaction', 'services', 'finance_approval', 'loan', 'treasury', 'it_engineering'] as $k) ok(in_array($k, $keys, true), "fresh: unit $k");
    ok($unit('treasury')['parent_unit_id'] === null && $unit('it_engineering')['parent_unit_id'] === null && (int)$unit('dissatisfaction')['parent_unit_id'] === (int)$unit('after_sales')['id'], 'fresh: treasury + IT top-level; dissatisfaction under after-sales');
    ok(count($keys) === count(array_unique($keys)), 'fresh: no duplicate units');
    ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$P}service_unit_members") === 0 && count(get_users()) === 1, 'fresh: no users or memberships created by the plugin');
    break;

default:
    exit("unknown step\n");
}
echo "TOTAL: $pass passed, $fail failed\n";
