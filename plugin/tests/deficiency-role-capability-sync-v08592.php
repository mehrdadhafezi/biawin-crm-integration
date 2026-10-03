<?php
$pass = 0;
$fail = 0;

function d592_assert_true($condition, string $name): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}

function d592_method_body(string $source, string $method, string $next_marker): string {
    $start = strpos($source, 'function ' . $method);
    if ($start === false) return '';
    $end = strpos($source, $next_marker, $start + 1);
    return $end === false ? substr($source, $start) : substr($source, $start, $end - $start);
}

$root = dirname(__DIR__);
$main = file_get_contents($root . '/biawin-crm-integration.php');
$activator = file_get_contents($root . '/includes/class-biawin-crm-activator.php');
$admin = file_get_contents($root . '/admin/class-biawin-crm-admin.php');
$frontend = file_get_contents($root . '/includes/class-biawin-crm-frontend.php');
$workflow = file_get_contents($root . '/includes/class-biawin-crm-deficiency-workflow.php');
$auth = file_get_contents($root . '/includes/class-biawin-crm-auth.php');

$maybe_upgrade = d592_method_body($activator, 'maybe_upgrade', 'public static function ensure_roles_and_caps');
$ensure_roles = d592_method_body($activator, 'role_definitions', 'private static function relabel_legacy_roles'); // v0.8.63.0: definitions + ledger sync
$activate = d592_method_body($activator, 'activate', 'private static function repair_duplicate_active_assignments');
$save_user = d592_method_body($admin, 'handle_save_portal_user', 'public static function handle_toggle_portal_user');
$portal_views = d592_method_body($frontend, 'portal_views', 'private static function can_view');

d592_assert_true(strpos($main, "Version: 0.8.59.7") !== false && strpos($main, "define('BIAWIN_CRM_VERSION', '0.8.59.7')") !== false, 'version bumped to 0.8.59.7');
d592_assert_true(strpos($activator, 'public static function ensure_roles_and_caps') !== false, 'dedicated role/cap sync method exists');
d592_assert_true(strpos($maybe_upgrade, 'self::ensure_roles_and_caps();') !== false, 'code-only upgrade syncs roles before returning');
d592_assert_true(strpos($maybe_upgrade, "['0.8.58.0', '0.8.59.0', '0.8.59.1', '0.8.59.2', '0.8.59.3', '0.8.59.4', '0.8.59.5', '0.8.59.6']") !== false && strpos($maybe_upgrade, "BIAWIN_CRM_VERSION === '0.8.59.7'") !== false, 'v0.8.58.0/v0.8.59.0/v0.8.59.1 to v0.8.59.7 is code-only with cap repair');
d592_assert_true(strpos($activate, 'self::ensure_roles_and_caps();') !== false, 'full activation uses same role/cap sync source');
d592_assert_true(strpos($save_user, 'Biawin_CRM_Activator::ensure_roles_and_caps();') !== false, 'portal user creation repairs role caps before insert');
d592_assert_true(strpos($save_user, "wp_insert_user(['user_login'=>\$username,'user_pass'=>\$password,'user_email'=>\$email,'display_name'=>\$display,'role'=>\$role]") !== false, 'portal user receives selected role on insert');
d592_assert_true(strpos($save_user, '$user->set_role($role)') !== false, 'portal user role is enforced after insert');

d592_assert_true(strpos($ensure_roles, "'bcrm_deficiency_agent'") !== false, 'deficiency agent role is managed');
d592_assert_true(strpos($ensure_roles, "'bcrm_deficiency_supervisor'") !== false, 'deficiency supervisor role is managed');
foreach (['read','bcrm_work_cases','view_deficiency_cases','process_deficiency_case','send_case_to_finance'] as $cap) {
    d592_assert_true(strpos($ensure_roles, "'$cap'") !== false, "deficiency agent cap $cap is present");
}
foreach (['manage_deficiency_cases','assign_deficiency_cases','complete_deficiency_case','archive_deficiency_case','view_deficiency_reports'] as $cap) {
    d592_assert_true(strpos($ensure_roles, "'$cap'") !== false, "deficiency supervisor cap $cap is present");
}

d592_assert_true(strpos($ensure_roles, 'array_fill_keys') !== false && strpos($ensure_roles, 'add_role') !== false && strpos($ensure_roles, 'add_cap') !== false, 'sync creates missing roles and repairs existing role caps');
d592_assert_true(strpos($portal_views, "'deficiency' => ['label'=>'رفع ناقصی','cap'=>'view_deficiency_cases'") !== false, 'portal deficiency menu remains controlled by view_deficiency_cases');
d592_assert_true(strpos($auth, "'bcrm_deficiency_agent' => 'کارشناس رفع ناقصی'") !== false && strpos($auth, 'bcrm_work_cases') !== false, 'deficiency agent remains a login-capable portal role');
d592_assert_true(strpos($workflow, "current_user_can('process_deficiency_case') && (int)\$case->deficiency_agent_id === \$user_id") !== false, 'deficiency agent can process only assigned cases');
d592_assert_true(strpos($workflow, "\$roles = \$finance ? ['bcrm_financial_agent'] : ['bcrm_deficiency_agent'];") !== false, 'assignment panel still targets deficiency-agent role');
d592_assert_true(strpos($workflow, "\$agent->has_cap('process_deficiency_case')") !== false, 'assignment rejects users lacking process_deficiency_case');
d592_assert_true(strpos($activator, 'backfill_deficiency_cases') !== false && strpos($ensure_roles, 'backfill_deficiency_cases') === false, 'cap sync does not run deficiency backfill');
d592_assert_true(strpos($ensure_roles, 'INSERT ') === false && strpos($ensure_roles, 'UPDATE ') === false && strpos($ensure_roles, 'DELETE ') === false && strpos($ensure_roles, 'dbDelta') === false, 'cap sync does not mutate domain tables');

echo "TOTAL_PASS=$pass\n";
echo "TOTAL_FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
