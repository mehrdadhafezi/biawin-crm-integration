<?php
/**
 * v0.8.63.0 — static guards for Phase 1 (S4/L2 + after-sales foundation).
 * The behavioural proof is the real-WordPress run in tests/update-simulation-v08630/.
 */
$pass = 0; $fail = 0;
function F($ok, $msg) { global $pass, $fail; if ($ok) { $pass++; echo "PASS $msg\n"; } else { $fail++; echo "FAIL $msg\n"; } }
$root = dirname(__DIR__);
$act = file_get_contents($root . '/includes/class-biawin-crm-activator.php');
$admin = file_get_contents($root . '/admin/class-biawin-crm-admin.php');

// TEST H — no hard-coded/default password anywhere in shipped code or docs
$hits = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    if (strpos($rel, 'tests/') === 0 || !preg_match('/\.(php|js|md|txt|css)$/', $rel)) continue;
    if (strpos(file_get_contents($f->getPathname()), '12345678') !== false) $hits[] = $rel;
}
F(!$hits, 'TEST H: default password literal appears nowhere in shipped files' . ($hits ? ' — ' . implode(', ', $hits) : ''));
F(!preg_match('/wp_create_user|wp_insert_user|wp_set_password|wp_update_user|->set_role\(/', $act), 'activator creates/updates NO users, passwords or user roles');
F(strpos($act, 'seed_default_operations_staff') === false && strpos($act, 'seed_default_specialist_staff') === false, 'default-staff seeders removed');
F(!preg_match("/update_user_meta\([^)]*'bcrm_disabled'/", $act), 'activator never re-enables disabled users');
// L2
$seed = substr($act, strpos($act, 'private static function seed_default_services'), 2500);
F(strpos($seed, '$wpdb->update(') === false && stripos($seed, 'UPDATE ') === false && stripos($seed, 'DELETE') === false && stripos($seed, 'TRUNCATE') === false, 'L2: service seeder never UPDATEs/DELETEs/TRUNCATEs catalog or units');
F(strpos($seed, 'SERVICE_SEED_LEDGER_OPTION') !== false, 'L2: deleted seeded services are not re-inserted (ledger)');
// S4 role/cap sync
F(strpos($act, 'CAPS_LEDGER_OPTION') !== false && strpos($act, 'array_key_exists($cap, (array)$role->capabilities)') !== false, 'caps granted only if never provisioned and not present (ledger)');
F(!preg_match('/->remove_cap\(|remove_role\(/', $act), 'activator never removes capabilities or roles');
$ensure = substr($act, strpos($act, 'public static function ensure_roles_and_caps'), 600);
F(strpos($ensure, 'CAPS_MARKER_VERSION') !== false && strpos($ensure, 'return;') !== false, 'role sync is skipped on normal page loads (marker guard)');
// migration
F(strpos($act, "'0.8.63.0' => 'migrate_0863_0'") !== false, 'versioned migration 0.8.63.0 registered');
F(strpos($act, 'throw new RuntimeException("service_units.$column') !== false, 'migration throws on schema failure (version not advanced → retry)');
foreach (['parent_unit_id', 'unit_type', 'can_receive_inquiry', 'requires_portal_response', 'display_order'] as $c) F(substr_count($act, "'$c'") + substr_count($act, " $c ") >= 1, "service_units.$c defined");
// caps
foreach (['view_after_sales','manage_after_sales','view_dissatisfaction','manage_dissatisfaction','assign_dissatisfaction','process_dissatisfaction','view_after_sales_reports','manage_after_sales_settings','manage_dissatisfaction_subjects','manage_dissatisfaction_outcomes','view_finance_inquiries','respond_finance_inquiries','view_loan_inquiries','respond_loan_inquiries','view_service_inquiries','respond_service_inquiries','view_treasury','manage_treasury','manage_treasury_capacity','approve_refund_schedule','process_refund_payment'] as $cap) F(strpos($act, "'$cap' =>") !== false, "capability $cap defined");
// admin handler security
$h = substr($admin, strpos($admin, 'public static function handle_save_service_unit'), 4000);
F(strpos($h, "current_user_can('bcrm_manage_cases')") !== false && strpos($h, "check_admin_referer('bcrm_save_service_unit')") !== false, 'unit handler: capability + nonce');
F(strpos($h, "'cycle'") !== false && strpos($h, 'unit_member_roles') !== false, 'unit handler: parent-cycle guard + only CRM users as members');
F(strpos($admin, "FROM \$members_table m LEFT JOIN") !== false, 'units page loads members in one query (no N+1)');
F(strpos(file_get_contents($root . '/includes/class-biawin-crm-activator.php'), "invoice_code VARCHAR(80) NOT NULL DEFAULT '',") !== false, 'refund_registers CREATE is valid on fresh installs');
echo "TOTAL_PASS=$pass\nTOTAL_FAIL=$fail\n";
exit($fail ? 1 : 0);
