<?php
// Compares update-simulation snapshots. Usage: php verify.php
$d = __DIR__ . '/sim-out';
$L = fn($n) => json_decode(file_get_contents("$d/$n.json"), true);
$before = $L('before'); $a1 = $L('after1'); $a2 = $L('after2'); $a3 = is_file("$d/after3.json") ? $L('after3') : null; $post = is_file("$d/post_applied.json") ? $L('post_applied') : null;
$pass = 0; $fail = 0;
function T($ok, $msg) { global $pass, $fail; if ($ok) { $pass++; echo "PASS  $msg\n"; } else { $fail++; echo "FAIL  $msg\n"; } }
$new_caps = ['view_after_sales','manage_after_sales','view_dissatisfaction','manage_dissatisfaction','assign_dissatisfaction','process_dissatisfaction','view_after_sales_reports','manage_after_sales_settings','manage_dissatisfaction_subjects','manage_dissatisfaction_outcomes','view_finance_inquiries','respond_finance_inquiries','view_loan_inquiries','respond_loan_inquiries','view_service_inquiries','respond_service_inquiries','view_treasury','manage_treasury','manage_treasury_capacity','approve_refund_schedule','process_refund_payment'];
$new_roles = ['bcrm_after_sales_manager','bcrm_loan_supervisor','bcrm_loan_agent','bcrm_treasury_supervisor','bcrm_treasury_agent'];

echo "=== A/B/H: users (old production → update) ===\n";
T(array_keys($before['users']) === array_keys($a1['users']), 'same set of users after update (' . count($a1['users']) . '), nobody created or restored');
$same = true; $diff = [];
foreach ($before['users'] as $login => $u) {
    $v = $a1['users'][$login] ?? null;
    foreach (['ID','user_pass','user_email','display_name','capabilities','bcrm_disabled','nickname'] as $k) if (!$v || $v[$k] !== $u[$k]) { $same = false; $diff[] = "$login.$k"; }
}
T($same, 'every user: same ID, password hash, email, display name, roles/caps, disabled flag, nickname' . ($diff ? ' — ' . implode(',', array_slice($diff, 0, 5)) : ''));
T(!isset($a1['users']['09125172258']) && !isset($a1['users']['svc_furniture_sup']), 'TEST B: users deleted on the old version stay deleted');
T(($a1['users']['09926042925']['capabilities'] ?? null) === ['bcrm_complaint_agent' => true], 'manually re-roled seeded user keeps the admin-chosen role');
T((string)($a1['users']['09197958525']['bcrm_disabled'] ?? '') === '1', 'admin-disabled seeded user stays disabled (was force re-enabled before)');
T(($a1['users']['deficiency_supervisor']['display_name'] ?? '') === 'مهسا — سرپرست ناقصی', 'admin profile edit (display name) preserved');
T(($a1['users']['09125906908']['user_pass'] ?? '') === ($before['users']['09125906908']['user_pass'] ?? 'x'), 'changed password hash preserved');
T(!empty($a1['users']['09302515844']['capabilities']['custom_user_cap']), 'per-user custom capability preserved');

echo "=== C/G: roles & capabilities ===\n";
T(!empty($a1['roles']['bcrm_agent']['capabilities']['custom_role_cap']), 'TEST C: custom capability on an existing role preserved');
T(!empty($a1['roles']['administrator']['capabilities']['admin_custom_cap']), 'TEST G: administrator custom capability preserved');
$lost = [];
foreach ($before['roles'] as $r => $def) foreach ($def['capabilities'] as $c => $g) if (($a1['roles'][$r]['capabilities'][$c] ?? null) !== $g) $lost[] = "$r:$c";
T(!$lost, 'no pre-existing role capability removed or changed (' . count($before['roles']) . ' roles)' . ($lost ? ' — ' . implode(',', array_slice($lost, 0, 5)) : ''));
T(!array_diff($new_roles, array_keys($a1['roles'])), 'new after-sales roles created: ' . implode(', ', $new_roles));
T(!array_diff($new_caps, array_keys(array_filter($a1['roles']['administrator']['capabilities']))), 'administrator received all 21 after-sales capabilities additively');
$exp = ['bcrm_complaint_agent' => ['view_after_sales','view_dissatisfaction','process_dissatisfaction'], 'bcrm_financial_agent' => ['view_finance_inquiries','respond_finance_inquiries'], 'bcrm_service_agent' => ['view_service_inquiries','respond_service_inquiries'], 'bcrm_treasury_supervisor' => ['manage_treasury_capacity','process_refund_payment'], 'bcrm_after_sales_manager' => ['approve_refund_schedule','manage_dissatisfaction_subjects']];
$ok = true; foreach ($exp as $r => $cs) foreach ($cs as $c) if (empty($a1['roles'][$r]['capabilities'][$c])) $ok = false;
T($ok, 'role→capability mapping applied (dissatisfaction, finance, services, treasury, after-sales manager)');
T(empty($a1['roles']['bcrm_agent']['capabilities']['manage_dissatisfaction']) && empty($a1['roles']['bcrm_complaint_agent']['capabilities']['approve_refund_schedule']), 'ordinary agents did NOT receive management/approval capabilities');
T(($a1['roles']['bcrm_complaint_supervisor']['name'] ?? '') === 'سرپرست نارضایتی‌ها CRM' && ($a1['roles']['bcrm_complaint_agent']['name'] ?? '') === 'کارشناس نارضایتی‌ها CRM', 'complaint roles relabelled to نارضایتی (same role keys → existing users keep access)');

echo "=== D/E: service catalog ===\n";
$svc = fn($s, $code) => current(array_filter($s['rows']['service_catalog'], fn($r) => $r['service_code'] === $code)) ?: null;
T($a1['rows']['service_catalog'] === $before['rows']['service_catalog'], 'TEST D/E: service catalog byte-identical after update (' . count($a1['rows']['service_catalog']) . ' rows)');
T(($svc($a1, 'furniture-loan-score')['name'] ?? '') === 'امتیاز وام مبل (ویرایش مدیر)' && (float)$svc($a1, 'furniture-loan-score')['credit_cost'] === 123000000.0, 'edited default service keeps admin name/value');
T((int)($svc($a1, 'bedding-loan-score')['is_active'] ?? 1) === 0, 'admin-deactivated default service stays inactive');
T((int)($svc($a1, 'dentistry')['is_active'] ?? 0) === 1, 'admin-reactivated legacy service NOT force-deactivated');
T($svc($a1, 'custom-svc') !== null, 'custom service preserved');

echo "=== business data ===\n";
$schema_grown = ['complaints']; // v0.8.64.0: + nullable subject_id → compare original columns row by row instead
$t_before = array_diff_key($before['tables'], array_flip($schema_grown)); $t_after = array_diff_key($a1['tables'], array_flip($schema_grown));
T($t_after === $t_before && $a1['complaints_original'] === $before['complaints_original'] && $a1['tables']['complaints']['count'] === $before['tables']['complaints']['count'], 'customers, phones, invoices, complaints, finance, refunds, refund registers, assignments, deficiency, service cases, timeline, attachments, interactions: counts + CHECKSUM TABLE unchanged');

echo "=== units ===\n";
$units = []; foreach ($a1['rows']['service_units'] as $u) $units[$u['unit_key']] = $u;
$ok = true; foreach (['after_sales','dissatisfaction','services','finance_approval','loan','treasury'] as $k) if (!isset($units[$k])) $ok = false;
T($ok, 'after-sales units exist: after_sales + dissatisfaction/services/finance_approval/loan/treasury');
$root = $units['after_sales']['id'] ?? -1;
$ok = true; foreach (['dissatisfaction','services','finance_approval','loan','treasury'] as $k) if (($units[$k]['parent_unit_id'] ?? null) != $root) $ok = false;
T($ok && $units['after_sales']['parent_unit_id'] === null, 'children point to «خدمات پس از فروش»; parent is top level');
T(($units['furniture_loan']['unit_name'] ?? '') === 'واحد مبل ویژه' && (int)$units['clothing_loan']['is_active'] === 0, 'admin-renamed / admin-deactivated existing units untouched');
$bu = []; foreach ($before['rows']['service_units'] as $u) $bu[$u['unit_key']] = $u;
$ok = true; foreach ($bu as $k => $u) foreach (['unit_name','supervisor_user_id','is_active','created_at'] as $f) if (($units[$k][$f] ?? null) !== $u[$f]) $ok = false;
T($ok, 'no pre-existing unit field overwritten (name, supervisor, active, created_at)');
T(($units['furniture_loan']['parent_unit_id'] ?? null) == ($units['services']['id'] ?? -2) && $units['furniture_loan']['unit_type'] === 'service', 'existing service cartables typed "service" and grouped under «خدمات»');
T($a1['rows']['service_unit_members'] === $before['rows']['service_unit_members'], 'existing unit memberships unchanged');

echo "=== F: idempotency (forced second full migration + repair) ===\n";
$strip = function ($s) { unset($s['plugin_version']); return $s; };
T($strip($a1) == $strip($a2), 'second run: users, roles, services, units, members, business tables, ledgers — identical state');

if ($post && $a3) {
    echo "=== after-upgrade admin changes survive further updates ===\n";
    T(!isset($a3['users']['09033067185']), 'user deleted after upgrade stays deleted');
    T(!array_key_exists('bcrm_import', $a3['roles']['bcrm_supervisor']['capabilities']), 'capability removed from a role stays removed (old code re-granted it every page load)');
    T(!array_key_exists('view_treasury', $a3['roles']['bcrm_after_sales_manager']['capabilities']), 'removed after-sales capability stays removed');
    T(($a3['roles']['bcrm_agent']['capabilities']['explicitly_denied_x'] ?? null) === false, 'explicitly denied capability stays denied');
    T(!isset($a3['roles']['bcrm_loan_agent']), 'deleted plugin role is NOT recreated');
    T($svc($a3, 'life-insurance') === null, 'deleted seeded service is NOT recreated (L2)');
    $u3 = []; foreach ($a3['rows']['service_units'] as $u) $u3[$u['unit_key']] = $u;
    T(!isset($u3['loan']), 'deleted seeded unit is NOT recreated');
    T(isset($u3['motorcycle_loan']) && array_key_exists('parent_unit_id', $u3['motorcycle_loan']) && $u3['motorcycle_loan']['parent_unit_id'] === null, 'unit moved to top level by admin is not re-parented');
    T(($u3['dissatisfaction']['unit_name'] ?? '') === 'نارضایتی و شکایات (نام مدیر)' && (int)$u3['dissatisfaction']['can_receive_inquiry'] === 1, 'admin-renamed seeded unit keeps name and flags');
    T($strip($a3)['tables'] == $strip($post)['tables'] && $a3['rows'] == $post['rows'] && $a3['users'] == $post['users'] && $a3['roles'] == $post['roles'], 'another full update cycle changed nothing at all');
}
echo "\nTOTAL: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
