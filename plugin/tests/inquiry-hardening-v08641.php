<?php
/**
 * v0.8.64.1 — static guards for Phase 2.1 (inquiry architecture hardening).
 * Runtime behaviour: tests/update-simulation-v08640/run-p21.sh (p21.php, 31 checks on real WordPress).
 * Usage: php tests/inquiry-hardening-v08641.php
 */
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function F($c, $label) { global $pass, $fail; echo ($c ? 'PASS  ' : 'FAIL  ') . $label . "\n"; $c ? $pass++ : $fail++; }
$read = static fn($rel) => (string)file_get_contents($root . '/' . $rel);
$strip = static fn($src) => preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $src);
$cfg = $read('includes/class-biawin-crm-after-sales-config.php');
$adm = $read('includes/class-biawin-crm-after-sales-config-admin.php');
$act = $read('includes/class-biawin-crm-activator.php');
$fn = static function (string $src, string $name) { return preg_match('/function ' . preg_quote($name, '/') . '\(.*?\n    \}/s', $src, $m) ? $m[0] : ''; };

echo "--- schema (reuse, no duplicates, no pointless migration)\n";
F(!preg_match('/inquiry_enabled\s+TINYINT|ADD COLUMN `?inquiry_enabled|\'inquiry_enabled\'\s*=>\s*[\'"]TINYINT/', $act), 'no duplicate inquiry_enabled column: existing can_receive_inquiry IS "inquiry enabled"');
F(strpos($act, "'0.8.64.1' =>") === false, 'no migration added for 0.8.64.1 (no schema change needed)');
F(strpos($act, "ensure_column(\"{\$p}service_units\", 'inquiry_responders', \"VARCHAR(20) NOT NULL DEFAULT 'members'\")") !== false, 'inquiry_responders column unchanged (VARCHAR(20), default members — holds "both")');
$modes = $fn($cfg, 'responder_scopes');
F(strpos($modes, "'members' =>") !== false && strpos($modes, "'supervisor' =>") !== false && strpos($modes, "'both' =>") !== false && substr_count($modes, '=>') === 3, 'responder modes: exactly members | supervisor | both');

echo "--- rule #1: unit active ≠ inquiry enabled\n";
$save = $fn($cfg, 'save_unit_inquiry_config');
F($save !== '' && !preg_match("/'is_active'|->is_active|display_order|unit_name|parent_unit_id|supervisor_user_id|updated_at|service_unit_members/", $save), 'save_unit_inquiry_config never references unit active/name/parent/supervisor/order/updated_at/members');
F(strpos($save, "\$fields = ['can_receive_inquiry' => \$enabled, 'requires_portal_response' => \$portal, 'inquiry_responders' => \$mode]") !== false, 'UPDATE scoped to the three inquiry columns only');
F(strpos($save, 'bool_input') !== false && strpos($save, "return ['ok' => false, 'message' => 'مقدار تنظیمات استعلام نامعتبر است.']") !== false, 'boolean fields validated server-side');
F(strpos($save, 'responder_scopes()[$mode]') !== false && strpos($save, 'پاسخ‌دهنده نامعتبر است') !== false, 'responder mode validated server-side');
F(strpos($save, "'واحد پیدا نشد.'") !== false, 'unit existence validated');
$handler = $fn($adm, 'handle_units_save');
F(strpos($handler, 'inquiry_columns()') !== false && strpos($handler, '$inquiry[$field] = $row[$field] ?? null') !== false, 'handler forwards ONLY whitelisted inquiry fields (posted is_active/name/… ignored)');
$ui = $fn($adm, 'render_units');
F(!preg_match('/\[is_active\]|\[display_order\]|\[unit_name\]|\[parent_unit_id\]|\[supervisor_user_id\]|agent_user_ids/', $ui), 'inquiry tab renders no input for active/order/name/parent/supervisor/members');
F(strpos($ui, 'وضعیت واحد') !== false && strpos($ui, 'تنظیمات استعلام') !== false && strpos($ui, 'استعلام‌پذیر') !== false && strpos($ui, 'پاسخ از طریق پرتال لازم است') !== false && strpos($ui, 'پاسخ‌دهندگان') !== false, 'UI separates «وضعیت واحد» (read-only) from «تنظیمات استعلام»');
F(strpos($ui, 'type="radio" name="') !== false && strpos($ui, '<select name="\' . $n . \'[inquiry_responders]') === false, 'responder mode is a single-select radio group');

echo "--- eligibility + resolver centralized\n";
$acc = $fn($cfg, 'unit_accepts_inquiries');
F(strpos($acc, "is_active") !== false && strpos($acc, 'can_receive_inquiry') !== false && strpos($acc, '&&') !== false, 'eligibility = unit active AND inquiry enabled (one function)');
$ins = $fn($cfg, 'insert_inquiry');
F(strpos($ins, 'self::unit_accepts_inquiries($unit)') !== false && !preg_match('/\(int\)\$unit->is_active \|\|/', $ins), 'inquiry creation uses the central eligibility rule');
$res = $fn($cfg, 'get_inquiry_responders');
F($res !== '' && strpos($cfg, 'function unit_responders(') === false, 'single resolver get_inquiry_responders() (old unit_responders removed, no duplicate)');
F(!preg_match('/wp_insert_user|wp_create_user|add_role|remove_role|add_cap|remove_cap|->insert\(|->update\(|->delete\(|update_user_meta|set_role/', $res), 'resolver is read-only (no users, memberships or roles written)');
F(strpos($res, 'normalize_responders') !== false && strpos($res, "bcrm_disabled") !== false, 'resolver normalizes invalid stored modes and skips disabled users');
$all = '';
foreach (glob($root . '/{includes,admin}/*.php', GLOB_BRACE) as $f) if (basename($f) !== 'class-biawin-crm-after-sales-config.php') $all .= $strip((string)file_get_contents($f));
F(!preg_match("/service_unit_members[^;]*member_role\s*IN|inquiry_responders\s*===?\s*'/", $all), 'no other file re-implements responder resolution');

echo "--- capabilities\n";
F(strpos($adm, "self::guard('units', 'bcrm_cfg_units_save')") !== false && strpos($act, "'manage_inquiry_units' =>") !== false && substr_count($act, "'manage_inquiry_units'") === 1, 'reuses manage_inquiry_units (no new capability)');
echo "TOTAL_FAIL=$fail (passed $pass)\n";
exit($fail ? 1 : 0);
