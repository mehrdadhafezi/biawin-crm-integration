<?php
/**
 * v0.8.64.0 — Phase 2 (admin configuration foundation) tests on REAL WordPress + MySQL.
 * Each step is a separate PHP process (= a separate request). Used with simb.php (customizer).
 *   php p2.php history          (on the OLD version) historical complaints/tags/activities + admin unit flags
 *   php p2.php snap NAME         snapshot users/roles/catalog/units/members/complaints/business tables
 *   php p2.php diff A B          preservation diff (update must not change existing state)
 *   php p2.php same A B          strict identity (re-run migration must change nothing at all)
 *   php p2.php functional        tests 1–12 + permissions through the real admin handlers / services
 */
$step = $argv[1] ?? '';
$out_dir = __DIR__ . '/sim-out';
if (!is_dir($out_dir)) mkdir($out_dir);
$_SERVER['HTTP_HOST'] = 'bcrm-upgrade.test'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SERVER_NAME'] = 'bcrm-upgrade.test';
$pass = 0; $fail = 0;
function ok($c, $label) { global $pass, $fail; echo ($c ? 'PASS  ' : 'FAIL  ') . $label . "\n"; $c ? $pass++ : $fail++; }
function done() { global $pass, $fail; echo "TOTAL: $pass passed, $fail failed\n"; exit($fail ? 1 : 0); }

// Original (pre-Phase-2) columns: new columns must not alter what existed.
const COMPLAINT_COLS = 'id,customer_id,interaction_id,subject,related_unit,description,priority,needs_manager_call,due_at,owner_user_id,resolution,resolution_outcome,status,created_at,closed_at';
const UNIT_COLS = 'id,unit_key,unit_name,supervisor_user_id,is_active,parent_unit_id,unit_type,can_receive_inquiry,requires_portal_response,display_order,created_at,updated_at';
const BUSINESS = ['customers','phones','invoices','refund_registers','refunds','financial_cases','service_cases','deficiency_cases','case_actions','assignments','interactions','activities','customer_tags','attachments'];

if ($step === 'diff' || $step === 'same') {
    $a = json_decode(file_get_contents("$out_dir/{$argv[2]}.json"), true);
    $b = json_decode(file_get_contents("$out_dir/{$argv[3]}.json"), true);
    if ($step === 'same') {
        foreach ($a as $k => $v) if ($k !== 'db_version') ok($v === $b[$k], "identical after re-run: $k");
        done();
    }
    ok(array_keys($a['users']) === array_keys($b['users']), '13 users: same set (' . count($a['users']) . '), none created/restored/removed');
    ok($a['users'] === $b['users'], '13 users: ID, password hash, email, name, roles/caps, disabled flag unchanged');
    $added = []; $changed = [];
    foreach ((array)$a['roles'] as $role => $def) {
        if (!isset($b['roles'][$role])) { $changed[] = "$role removed"; continue; }
        if ($def['name'] !== $b['roles'][$role]['name']) $changed[] = "$role label";
        foreach ((array)$def['capabilities'] as $cap => $g) if (!array_key_exists($cap, (array)$b['roles'][$role]['capabilities']) || $b['roles'][$role]['capabilities'][$cap] !== $g) $changed[] = "$role:$cap";
        foreach ((array)$b['roles'][$role]['capabilities'] as $cap => $g) if (!array_key_exists($cap, (array)$def['capabilities'])) $added[] = "$role:$cap";
    }
    foreach ((array)$b['roles'] as $role => $def) if (!isset($a['roles'][$role])) $changed[] = "$role created";
    sort($added);
    ok(!$changed, '14 roles: no role/label/capability removed, changed or created' . ($changed ? ' — ' . implode(', ', $changed) : ''));
    $expected = array_values(array_filter(['administrator:manage_inquiry_units', isset($a['roles']['bcrm_portal_admin']) ? 'bcrm_portal_admin:manage_inquiry_units' : null]));
    sort($expected);
    ok($added === $expected, '8 only additive change: manage_inquiry_units → administrator/portal admin (' . implode(', ', $added) . ')');
    ok($a['service_catalog'] === $b['service_catalog'], '15 service catalog byte-identical (' . count($a['service_catalog']) . ' rows)');
    ok($a['units'] === $b['units'], '16 units: every pre-existing column of every unit identical (' . count($a['units']) . ' units)');
    ok(array_unique(array_column($b['units_new'], 'inquiry_responders')) === ['members'], '16 new unit column defaults to "members" (no behaviour change)');
    ok($a['members'] === $b['members'], '16 unit memberships identical');
    ok($a['complaints'] === $b['complaints'], '17 complaints: every original column of every row identical (' . count($a['complaints']) . ')');
    ok(!array_filter(array_column($b['complaints_new'], 'subject_id')), '17/S10 historical complaints keep subject_id NULL (no reinterpretation of free text)');
    ok($a['business'] === $b['business'], '17 customer/business tables: counts + CHECKSUM identical');
    $keys = array_column(array_filter($b['catalog'], static fn($r) => $r['catalog'] === 'complaint_outcome'), 'item_key');
    sort($keys);
    ok($keys === ['refund', 'satisfied', 'service_request'], 'outcomes registered = exactly the 3 keys already used by historical data/code');
    ok(!array_filter($b['catalog'], static fn($r) => $r['catalog'] === 'complaint_subject'), 'no complaint subject invented by the migration (business list is admin data)');
    echo "db_version {$a['db_version']} → {$b['db_version']}\n";
    done();
}

require __DIR__ . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;
$P = $wpdb->prefix . 'bcrm_';
$admin = get_user_by('login', 'qa_admin');
wp_set_current_user($admin->ID);
$now = current_time('mysql');

if ($step === 'history') {
    $cid = (int)$wpdb->get_var("SELECT id FROM {$P}customers ORDER BY id LIMIT 1");
    $cid2 = (int)$wpdb->get_var("SELECT id FROM {$P}customers ORDER BY id LIMIT 1 OFFSET 1");
    $rows = [
        [$cid, 'تاخیر در تحویل', 'open', null, null],
        [$cid, 'کیفیت کالا', 'closed', 'refund', 'عودت انجام شد'],
        [$cid2, 'برخورد فروشنده', 'closed', 'satisfied', 'راضی شد'],
        [$cid2, 'شکایت مشتری', 'closed', 'service_request', 'تبدیل شد'],
    ];
    foreach ($rows as [$c, $subject, $status, $outcome, $res]) {
        $wpdb->insert("{$P}complaints", ['customer_id' => $c, 'subject' => $subject, 'related_unit' => 'فروش', 'description' => 'شرح ' . $subject, 'priority' => 'normal', 'status' => $status, 'resolution_outcome' => $outcome, 'resolution' => $res, 'created_at' => $now, 'closed_at' => $status === 'closed' ? $now : null]);
        $id = (int)$wpdb->insert_id;
        $wpdb->insert("{$P}activities", ['customer_id' => $c, 'actor_user_id' => $admin->ID, 'activity_type' => 'complaint_created', 'object_type' => 'complaint', 'object_id' => $id, 'title' => 'شکایت جدید ثبت شد.', 'created_at' => $now]);
    }
    $wpdb->insert("{$P}customer_tags", ['customer_id' => $cid, 'tag' => 'refund_reported', 'created_by' => $admin->ID, 'created_at' => $now]);
    $wpdb->insert("{$P}customer_tags", ['customer_id' => $cid2, 'tag' => 'vip', 'created_by' => $admin->ID, 'created_at' => $now]);
    $wpdb->insert("{$P}assignments", ['customer_id' => $cid, 'agent_user_id' => (int)get_user_by('login', 'b_agent')->ID, 'status' => 'assigned', 'assigned_by' => $admin->ID, 'assigned_at' => $now]);
    // Admin decisions on unit inquiry flags made in Phase 1 — must survive the update.
    $wpdb->update("{$P}service_units", ['can_receive_inquiry' => 0], ['unit_key' => 'services']);
    $wpdb->update("{$P}service_units", ['requires_portal_response' => 0, 'display_order' => 7], ['unit_key' => 'finance_approval']);
    echo "history seeded; db error: '" . $wpdb->last_error . "'\n";
    exit;
}

if ($step === 'snap') {
    $has = static fn($t) => (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t;
    $snap = ['users' => [], 'roles' => get_option($wpdb->prefix . 'user_roles')];
    foreach ($wpdb->get_results("SELECT ID,user_login,user_pass,user_email,display_name FROM {$wpdb->users} ORDER BY ID", ARRAY_A) as $u) {
        $u['capabilities'] = get_user_meta((int)$u['ID'], $wpdb->prefix . 'capabilities', true);
        $u['bcrm_disabled'] = get_user_meta((int)$u['ID'], 'bcrm_disabled', true);
        $snap['users'][$u['user_login']] = $u;
    }
    $snap['service_catalog'] = $wpdb->get_results("SELECT * FROM {$P}service_catalog ORDER BY id", ARRAY_A);
    $snap['units'] = $wpdb->get_results('SELECT ' . UNIT_COLS . " FROM {$P}service_units ORDER BY id", ARRAY_A);
    $snap['units_new'] = $wpdb->get_var("SHOW COLUMNS FROM {$P}service_units LIKE 'inquiry_responders'") ? $wpdb->get_results("SELECT id, inquiry_responders FROM {$P}service_units ORDER BY id", ARRAY_A) : [];
    $snap['members'] = $wpdb->get_results("SELECT * FROM {$P}service_unit_members ORDER BY id", ARRAY_A);
    $snap['complaints'] = $wpdb->get_results('SELECT ' . COMPLAINT_COLS . " FROM {$P}complaints ORDER BY id", ARRAY_A);
    $snap['complaints_new'] = $wpdb->get_var("SHOW COLUMNS FROM {$P}complaints LIKE 'subject_id'") ? $wpdb->get_results("SELECT id, subject_id FROM {$P}complaints ORDER BY id", ARRAY_A) : [];
    $snap['business'] = [];
    foreach (BUSINESS as $t) $snap['business'][$t] = ['count' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$P}$t"), 'checksum' => (string)$wpdb->get_var("CHECKSUM TABLE {$P}$t", 1)];
    $snap['catalog'] = $has("{$P}catalog_items") ? $wpdb->get_results("SELECT * FROM {$P}catalog_items ORDER BY id", ARRAY_A) : [];
    $snap['inquiries'] = $has("{$P}complaint_inquiries") ? $wpdb->get_results("SELECT * FROM {$P}complaint_inquiries ORDER BY id", ARRAY_A) : [];
    $snap['ledgers'] = ['caps' => get_option('biawin_crm_caps_ledger'), 'catalog' => get_option('biawin_crm_catalog_seed_ledger'), 'units' => get_option('biawin_crm_unit_seed_ledger'), 'services' => get_option('biawin_crm_service_seed_ledger')];
    $snap['db_version'] = get_option('biawin_crm_db_version');
    file_put_contents("$out_dir/{$argv[2]}.json", json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "snapshot {$argv[2]} (plugin " . BIAWIN_CRM_VERSION . ", db " . $snap['db_version'] . ")\n";
    exit;
}

if ($step === 'functional') {
    $C = 'Biawin_CRM_After_Sales_Config';
    add_filter('wp_redirect', function ($loc) { throw new RuntimeException('REDIRECT ' . $loc); });
    add_filter('wp_die_handler', function () { return function ($msg) { throw new RuntimeException('DIE ' . (is_string($msg) ? $msg : '')); }; });
    $post = function (string $action, string $nonce, array $fields) {
        $_POST = $fields + ['action' => $action];
        $_REQUEST = $_POST + ['_wpnonce' => wp_create_nonce($nonce)];
        $_POST['_wpnonce'] = $_REQUEST['_wpnonce'];
        try { do_action('admin_post_' . $action); } catch (RuntimeException $e) { $out = $e->getMessage(); }
        $notice = get_transient('bcrm_cfg_notice_' . get_current_user_id());
        delete_transient('bcrm_cfg_notice_' . get_current_user_id());
        return ['out' => $out ?? '', 'notice' => $notice];
    };
    $subject_rows = static fn() => $C::items($C::CATALOG_SUBJECT);
    $by_label = static function ($catalog, $label) use ($C) { foreach ($C::items($catalog) as $i) if ($i->label === $label) return $i; return null; };

    echo "--- subjects (admin handlers)\n";
    $r = $post('bcrm_cfg_catalog_save', 'bcrm_cfg_catalog_save', ['tab' => 'subjects', 'label' => 'تاخیر در ارسال', 'item_key' => 'delay', 'description' => 'ارسال دیرتر از موعد', 'is_active' => '1']);
    $s1 = $by_label($C::CATALOG_SUBJECT, 'تاخیر در ارسال');
    ok($s1 && $s1->item_key === 'delay' && (int)$s1->is_active === 1 && strpos($r['out'], 'REDIRECT') === 0, '1 admin creates a complaint subject (key "delay", active)');
    $post('bcrm_cfg_catalog_save', 'bcrm_cfg_catalog_save', ['tab' => 'subjects', 'label' => 'کیفیت محصول', 'is_active' => '1']);
    $post('bcrm_cfg_catalog_save', 'bcrm_cfg_catalog_save', ['tab' => 'subjects', 'label' => 'رفتار کارشناس', 'item_key' => 'staff_behaviour', 'is_active' => '1']);
    $s2 = $by_label($C::CATALOG_SUBJECT, 'کیفیت محصول');
    ok($s2 && strpos($s2->item_key, 'subject_') === 0, '1 key is optional (generated, stable): ' . ($s2->item_key ?? '-'));
    $dup = $post('bcrm_cfg_catalog_save', 'bcrm_cfg_catalog_save', ['tab' => 'subjects', 'label' => 'دیگری', 'item_key' => 'delay']);
    ok(empty($dup['notice']['ok']) && count($subject_rows()) === 3, '1 duplicate key rejected (keys are unique and permanent)');

    $r = $post('bcrm_cfg_catalog_save', 'bcrm_cfg_catalog_save', ['tab' => 'subjects', 'id' => $s1->id, 'label' => 'تاخیر در ارسال کالا', 'item_key' => 'hacked', 'description' => 'ویرایش', 'is_active' => '1', 'sort_order' => (string)$s1->sort_order]);
    $s1b = $C::item((int)$s1->id);
    ok($s1b->label === 'تاخیر در ارسال کالا' && $s1b->item_key === 'delay' && $s1b->description === 'ویرایش', '2 admin edits a subject (label/description); key stays "delay"');

    // Register a complaint through the REAL create path with the dropdown value, before deactivation.
    $cust = (int)$wpdb->get_var("SELECT id FROM {$P}customers ORDER BY id LIMIT 1");
    // Pre-existing business rule: a complaint routes the customer journey, which needs a declared amount.
    $wpdb->update("{$P}customers", ['declared_paid' => 5000000], ['id' => $cust]);
    $free = Biawin_CRM_Workflow_Service::create_complaint($cust, ['subject' => 'متن آزاد', 'description' => 'x']);
    ok(empty($free['ok']) && ($free['message'] ?? '') === 'موضوع نارضایتی را از فهرست انتخاب کنید.', '2/S2 with active subjects configured, free-text subject is refused (must pick from the list)');
    $made = Biawin_CRM_Workflow_Service::create_complaint($cust, ['subject_id' => (string)$s1->id, 'subject' => 'ignored', 'description' => 'از منو']);
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$P}complaints WHERE id=%d", (int)($made['id'] ?? 0)));
    ok(!empty($made['ok']) && (int)$row->subject_id === (int)$s1->id && $row->subject === 'تاخیر در ارسال کالا', '2/S2 complaint stores subject_id + label snapshot');

    $r = $post('bcrm_cfg_catalog_toggle', 'bcrm_cfg_catalog_toggle', ['tab' => 'subjects', 'id' => $s1->id, 'activate' => '']);
    ok((int)$C::item((int)$s1->id)->is_active === 0, '3 admin deactivates a subject');
    $ref = new ReflectionMethod('Biawin_CRM_Frontend', 'complaint_subject_field'); $ref->setAccessible(true); $html = $ref->invoke(null);
    ok(strpos($html, 'name="subject_id"') !== false && strpos($html, 'تاخیر در ارسال کالا') === false && strpos($html, 'کیفیت محصول') !== false, '3 deactivated subject disappears from the agent dropdown; active ones remain');
    $again = Biawin_CRM_Workflow_Service::create_complaint($cust, ['subject_id' => (string)$s1->id, 'description' => 'y']);
    ok(empty($again['ok']) && ($again['message'] ?? '') === 'موضوع نارضایتی انتخاب‌شده معتبر یا فعال نیست.', '3 deactivated subject cannot be used for NEW complaints');
    $row2 = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$P}complaints WHERE id=%d", (int)$made['id']));
    ok($row2 && (int)$row2->subject_id === (int)$s1->id && $row2->subject === 'تاخیر در ارسال کالا', '4 historical complaint still references the subject and shows its original label');
    $post('bcrm_cfg_catalog_save', 'bcrm_cfg_catalog_save', ['tab' => 'subjects', 'id' => $s1->id, 'label' => 'نام جدید بعدی', 'sort_order' => (string)$s1->sort_order]);
    $row3 = $wpdb->get_row($wpdb->prepare("SELECT subject FROM {$P}complaints WHERE id=%d", (int)$made['id']));
    ok($row3->subject === 'تاخیر در ارسال کالا' && $C::label_for($C::CATALOG_SUBJECT, 'delay', $row3->subject) === 'نام جدید بعدی', '4 renaming later does not rewrite the record snapshot; catalog lookup still resolves');
    ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$P}catalog_items WHERE id=" . (int)$s1->id) === 1, '4 deactivation never deletes the catalog row');
    $legacy_open = $wpdb->get_row("SELECT * FROM {$P}complaints WHERE subject='تاخیر در تحویل'");
    ok($legacy_open && $legacy_open->subject_id === null, '4 pre-Phase-2 free-text complaint unchanged (subject text kept, no subject_id)');

    $before = array_map(static fn($i) => (int)$i->id, $subject_rows());
    $third = $before[2];
    $post('bcrm_cfg_catalog_move', 'bcrm_cfg_catalog_move', ['tab' => 'subjects', 'id' => $third, 'direction' => 'up']);
    $after = array_map(static fn($i) => (int)$i->id, $subject_rows());
    ok($after === [$before[0], $third, $before[1]], '5 admin reorders subjects (move up)');
    $post('bcrm_cfg_catalog_move', 'bcrm_cfg_catalog_move', ['tab' => 'subjects', 'id' => $after[0], 'direction' => 'down']);
    $after2 = array_map(static fn($i) => (int)$i->id, $subject_rows());
    ok($after2 === [$third, $before[0], $before[1]] && array_map(static fn($i) => (int)$i->sort_order, $subject_rows()) === [10, 20, 30], '5 move down + order re-sequenced 10/20/30');
    $found = $C::items($C::CATALOG_SUBJECT, false, 'رفتار');
    ok(count($found) === 1 && $found[0]->item_key === 'staff_behaviour', '7(UI) search by label works');

    echo "--- outcomes\n";
    $r = $post('bcrm_cfg_catalog_save', 'bcrm_cfg_catalog_save', ['tab' => 'outcomes', 'label' => 'تهاتر', 'item_key' => 'offset', 'is_active' => '1']);
    $o = $C::item_by_key($C::CATALOG_OUTCOME, 'offset');
    $meta = $o ? json_decode((string)$o->meta_json, true) : null;
    ok($o && (int)$o->is_active === 1 && (int)$o->is_system === 0 && is_array($meta) && array_key_exists('actions', $meta), '6 admin creates an outcome (stable key, label, active, sort, meta.actions reserved)');
    $ref2 = new ReflectionMethod('Biawin_CRM_Frontend', 'complaint_outcome_select'); $ref2->setAccessible(true);
    ok(strpos($ref2->invoke(null), 'value="offset"') === false, '6 an outcome without an implemented action is not offered by the current resolve form (actions come in a later phase)');
    $refund = $C::item_by_key($C::CATALOG_OUTCOME, 'refund');
    $post('bcrm_cfg_catalog_save', 'bcrm_cfg_catalog_save', ['tab' => 'outcomes', 'id' => $refund->id, 'label' => 'عودت وجه', 'is_active' => '1', 'sort_order' => (string)$refund->sort_order]);
    ok(strpos($ref2->invoke(null), '>عودت وجه<') !== false, '6 renamed system outcome label shown in the existing resolve form');
    $post('bcrm_cfg_catalog_toggle', 'bcrm_cfg_catalog_toggle', ['tab' => 'outcomes', 'id' => $refund->id, 'activate' => '']);
    ok((int)$C::item((int)$refund->id)->is_active === 0 && strpos($ref2->invoke(null), 'value="refund"') === false, '7 admin deactivates an outcome; it leaves the resolve form');
    $open = (int)$wpdb->get_var("SELECT id FROM {$P}complaints WHERE status='open' ORDER BY id LIMIT 1");
    $res = Biawin_CRM_Workflow_Service::resolve_complaint($open, 'refund', 'x');
    ok(empty($res['ok']) && $wpdb->get_var("SELECT status FROM {$P}complaints WHERE id=$open") === 'open', '7 deactivated outcome is refused server-side; complaint untouched');
    ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$P}complaints WHERE resolution_outcome='refund'") >= 1 && $C::label_for($C::CATALOG_OUTCOME, 'refund') === 'عودت وجه', '7 historical "refund" decisions stay resolvable to a label');
    $post('bcrm_cfg_catalog_toggle', 'bcrm_cfg_catalog_toggle', ['tab' => 'outcomes', 'id' => $refund->id, 'activate' => '1']);

    echo "--- inquiry units (existing service_units)\n";
    $unit = static fn($key) => $wpdb->get_row($wpdb->prepare("SELECT * FROM {$P}service_units WHERE unit_key=%s", $key));
    $loan = $unit('loan'); $fin = $unit('finance_approval'); $svc = $unit('services'); $tre = $unit('treasury'); $dis = $unit('dissatisfaction');
    $units_post = static function (array $rows) use ($post) {
        $payload = [];
        foreach ($rows as $id => $f) $payload[$id] = ['present' => '1'] + $f;
        return $post('bcrm_cfg_units_save', 'bcrm_cfg_units_save', ['units' => $payload]);
    };
    $r = $units_post([(int)$tre->id => ['inquiry_enabled' => '1', 'requires_portal_response' => '1', 'inquiry_responders' => 'supervisor', 'display_order' => '5', 'is_active' => '0']]);
    $tre2 = $unit('treasury');
    ok((int)$tre2->can_receive_inquiry === 1 && (int)$tre2->requires_portal_response === 1 && $tre2->inquiry_responders === 'supervisor' && (int)$tre2->display_order === (int)$tre->display_order && (int)$tre2->is_active === (int)$tre->is_active, '8 inquiry-capable unit configured (portal, supervisor responders); posted is_active/display_order ignored (v0.8.64.1)');
    ok($tre2->unit_name === $tre->unit_name && (string)$tre2->parent_unit_id === (string)$tre->parent_unit_id && (string)$tre2->supervisor_user_id === (string)$tre->supervisor_user_id, '8 config save leaves name/parent/supervisor untouched');
    $ids = array_map(static fn($u) => $u->unit_key, $C::inquiry_units());
    ok(!in_array('dissatisfaction', $ids, true) && !in_array('services', $ids, true), '9 non-inquiry units excluded (dissatisfaction, and «خدمات» switched off by admin earlier)');
    $members_before = $wpdb->get_results("SELECT * FROM {$P}service_unit_members ORDER BY id", ARRAY_A);
    $snap1 = $wpdb->get_results("SELECT * FROM {$P}service_units ORDER BY id", ARRAY_A);
    $again = $units_post([(int)$tre->id => ['inquiry_enabled' => '1', 'requires_portal_response' => '1', 'inquiry_responders' => 'supervisor', 'display_order' => '5', 'is_active' => '0']]);
    ok($snap1 === $wpdb->get_results("SELECT * FROM {$P}service_units ORDER BY id", ARRAY_A) && strpos((string)($again['notice']['message'] ?? ''), 'تغییری') !== false, '10 saving the same inquiry config again changes nothing (not even updated_at)');
    ok($members_before === $wpdb->get_results("SELECT * FROM {$P}service_unit_members ORDER BY id", ARRAY_A), '10 inquiry config never rewrites unit memberships');
    $units_post([(int)$tre->id => ['inquiry_enabled' => '1', 'requires_portal_response' => '1', 'inquiry_responders' => 'bogus']]);
    ok($unit('treasury')->inquiry_responders === 'supervisor', '8 invalid responder scope rejected');
    $sup = get_user_by('login', 'b_supervisor'); $ag = get_user_by('login', 'b_agent');
    $wpdb->insert("{$P}service_unit_members", ['unit_id' => (int)$tre->id, 'user_id' => $sup->ID, 'member_role' => 'supervisor', 'created_at' => $now]);
    $wpdb->insert("{$P}service_unit_members", ['unit_id' => (int)$tre->id, 'user_id' => $ag->ID, 'member_role' => 'agent', 'created_at' => $now]);
    ok($C::get_inquiry_responders((int)$tre->id) === [(int)$sup->ID], '8 responders = supervisor only (mode "supervisor")');
    $units_post([(int)$tre->id => ['inquiry_enabled' => '1', 'requires_portal_response' => '1', 'inquiry_responders' => 'members']]);
    ok($C::get_inquiry_responders((int)$tre->id) === [(int)$ag->ID], '8 responders = member agents only (mode "members", v0.8.64.1)');
    $units_post([(int)$tre->id => ['inquiry_enabled' => '1', 'requires_portal_response' => '1', 'inquiry_responders' => 'both']]);
    $resp = $C::get_inquiry_responders((int)$tre->id); sort($resp);
    $want = [(int)$sup->ID, (int)$ag->ID]; sort($want);
    ok($resp === $want, '8 responders = supervisor + agents (mode "both")');

    echo "--- inquiries data model (multi-unit, portal vs manual)\n";
    $cid = (int)$made['id'];
    $a1 = $C::request_portal_inquiry($cid, (int)$tre->id, 'وضعیت پرداخت؟');
    $a2 = $C::request_portal_inquiry($cid, (int)$loan->id, 'وام فعال؟');
    $a3 = $C::record_manual_inquiry($cid, (int)$fin->id, 'تأیید شد تلفنی', 'با آقای X صحبت شد', '2026-10-01 10:30:00');
    $list = $C::complaint_inquiries($cid);
    ok(!empty($a1['ok']) && !empty($a2['ok']) && !empty($a3['ok']) && count($list) === 3, '11 one complaint → 3 inquiries to 3 units at the same time');
    $pending = array_values(array_filter($list, static fn($i) => $i->status === 'pending'));
    ok(count($pending) === 2 && count(array_unique(array_column($list, 'unit_id'))) === 3, '11 two portal inquiries pending simultaneously');
    $man = array_values(array_filter($list, static fn($i) => $i->mode === 'manual'))[0] ?? null;
    ok($man && $man->status === 'recorded' && $man->result_text === 'تأیید شد تلفنی' && $man->notes === 'با آقای X صحبت شد' && $man->contacted_at === '2026-10-01 10:30:00' && (int)$man->responded_by === (int)$admin->ID && $man->unit_name_snapshot === $fin->unit_name, '12 manual inquiry: unit, result, notes, date/time, actor, source=manual');
    ok(count(array_filter($list, static fn($i) => $i->mode === 'portal')) === 2 && $pending[0]->result_text === null && (int)$pending[0]->requested_by === (int)$admin->ID, '12 portal inquiries distinguishable (mode=portal, status=pending, no result yet)');
    $bad1 = $C::request_portal_inquiry($cid, (int)$dis->id, 'x');
    $bad2 = $C::request_portal_inquiry($cid, (int)$fin->id, 'x'); // finance requires_portal_response was switched off by admin
    $bad3 = $C::record_manual_inquiry($cid, (int)$svc->id, 'x');
    ok(empty($bad1['ok']) && empty($bad2['ok']) && empty($bad3['ok']) && count($C::complaint_inquiries($cid)) === 3, '9 non-inquiry / portal-disabled units are refused');

    echo "--- permissions\n";
    foreach (['administrator' => [1, 1, 1], 'bcrm_portal_admin' => [1, 1, 1], 'bcrm_after_sales_manager' => [1, 1, 0], 'bcrm_agent' => [0, 0, 0], 'bcrm_complaint_agent' => [0, 0, 0], 'bcrm_complaint_supervisor' => [0, 0, 0], 'bcrm_supervisor' => [0, 0, 0]] as $role => [$s, $o, $u]) {
        $r = get_role($role);
        if (!$r) { echo "  (role $role absent on this site)\n"; continue; }
        ok((int)$r->has_cap('manage_dissatisfaction_subjects') === $s && (int)$r->has_cap('manage_dissatisfaction_outcomes') === $o && (int)$r->has_cap('manage_inquiry_units') === $u, "caps for $role = subjects:$s outcomes:$o units:$u");
    }
    wp_set_current_user($ag->ID);
    $denied = $post('bcrm_cfg_catalog_save', 'bcrm_cfg_catalog_save', ['tab' => 'subjects', 'label' => 'نفوذ']);
    $denied2 = $post('bcrm_cfg_units_save', 'bcrm_cfg_units_save', ['units' => [(int)$dis->id => ['present' => '1', 'inquiry_enabled' => '1', 'requires_portal_response' => '0', 'inquiry_responders' => 'members']]]);
    ok(strpos($denied['out'], 'DIE') === 0 && strpos($denied2['out'], 'DIE') === 0 && !$by_label($C::CATALOG_SUBJECT, 'نفوذ') && (int)$unit('dissatisfaction')->can_receive_inquiry === 0, 'normal agent is refused by every config handler; nothing written');
    wp_set_current_user($admin->ID);
    $bad_nonce = (function () use ($post) { $_POST = ['action' => 'x', 'tab' => 'subjects', 'label' => 'بدون nonce']; $_REQUEST = $_POST + ['_wpnonce' => 'invalid']; try { do_action('admin_post_bcrm_cfg_catalog_save'); } catch (RuntimeException $e) { return $e->getMessage(); } return ''; })();
    ok(strpos($bad_nonce, 'DIE') === 0, 'invalid nonce refused');
    ob_start(); Biawin_CRM_After_Sales_Config_Admin::page(); $page = ob_get_clean();
    ok(strpos($page, 'موضوعات نارضایتی') !== false && strpos($page, 'واحدهای قابل استعلام') !== false && strpos($page, 'name="q"') !== false, 'admin page renders (tabs + search)');
    $_GET = ['tab' => 'units']; ob_start(); Biawin_CRM_After_Sales_Config_Admin::page(); $page = ob_get_clean();
    ok(strpos($page, 'units[' . (int)$tre->id . '][inquiry_responders]') !== false && strpos($page, 'مستثنی') !== false, 'inquiry-units tab renders every unit with status (excluded units marked)');
    done();
}
