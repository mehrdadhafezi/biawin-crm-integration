<?php
/**
 * v0.8.66.0 — static guards for Master Phase 2 (Core Customer Journey Alignment).
 * Runtime behaviour: tests/update-simulation-v08660/run-p2journey.sh (journey.php on real WordPress).
 * Usage: php tests/journey-v08660.php
 */
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function F($c, $label) { global $pass, $fail; echo ($c ? 'PASS  ' : 'FAIL  ') . $label . "\n"; $c ? $pass++ : $fail++; }
$read = static fn($rel) => (string)file_get_contents($root . '/' . $rel);
$strip = static fn($src) => preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $src);
$fn = static function (string $src, string $name) { return preg_match('/function ' . preg_quote($name, '/') . '\(.*?\n    \}/s', $src, $m) ? $m[0] : ''; };
$wf = $read('includes/class-biawin-crm-workflow-service.php');
$def = $read('includes/class-biawin-crm-deficiency-workflow.php');
$front = $read('includes/class-biawin-crm-frontend.php');
$rest = $read('includes/class-biawin-crm-rest.php');
$adm = $read('admin/class-biawin-crm-admin.php');
$act = $read('includes/class-biawin-crm-activator.php');
$rep = $read('includes/class-biawin-crm-journey-reports.php');
$js = $read('assets/frontend.js');
$main = $read('biawin-crm-integration.php');
$all = '';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/includes')) as $f) if (substr($f, -4) === '.php') $all .= file_get_contents($f);
$all .= $adm . $main;

echo "--- release / database\n";
F(preg_match("/define\('BIAWIN_CRM_VERSION', '0\.8\.66\.\d+'\)/", $main) === 1, 'plugin version 0.8.66.x');
F(substr_count($act, 'CREATE TABLE') === 32 && strpos($act, "'0.8.66.0' =>") === false, 'no schema change, no migration (existing tables/columns/tags/engines only)');
F(strpos($main, "includes/class-biawin-crm-journey-reports.php") !== false, 'read-only journey reports loaded');

echo "--- 3: completeness through the single rule function (v0.8.66.1 — details in journey-correction-v08661)\n";
F(strpos($wf, "'reconciliation_tolerance' => max(500000, (float) get_option('biawin_crm_reconciliation_tolerance', 500000))") !== false, 'tolerance setting value kept (legacy rule input)');
F(strpos($fn($wf, 'sync_customer'), 'self::amounts_match($declared, $total) ? self::PROFILE_COMPLETE : self::PROFILE_INCOMPLETE') !== false, 'profile completeness via amounts_match()');
F(strpos($fn($wf, 'sync_customer'), 'ensure_financial_case($customer_id, $declared, $total)') !== false, 'D-03: legacy financial_cases behaviour unchanged');

echo "--- 9: three fixed routes, not a catalog\n";
F(strpos($fn($wf, 'route_labels'), "['complaint' => 'ناراضی', 'no_request' => 'بدون خدمات', 'service_request' => 'خدمات مازاد']") !== false, 'exactly ناراضی / بدون خدمات / خدمات مازاد (DB keys unchanged)');
F(strpos($fn($wf, 'set_customer_journey'), "\$allowed = ['no_request', 'complaint', 'service_request'];") !== false, 'no fourth route accepted');

echo "--- 4/8: every route-selecting entry point is gated\n";
$sel = $fn($wf, 'select_customer_route');
F(strpos($sel, 'assert_route_selectable') !== false && strpos($sel, 'self::create_complaint(') !== false, 'select_customer_route: gate + ناراضی hands off to the existing complaint creation');
F(strpos($fn($wf, 'select_services'), 'assert_route_selectable') !== false, 'select_services (خدمات مازاد) gated');
F(strpos($fn($wf, 'create_complaint'), "if ((string)\$customer->journey_state !== 'complaint') {") !== false && strpos($fn($wf, 'create_complaint'), 'assert_route_selectable') !== false, 'create_complaint from the profile = selecting ناراضی (gated unless already on that route)');
F(strpos($fn($front, 'handle_journey'), 'select_customer_route') !== false && strpos($fn($front, 'ajax_set_journey'), 'select_customer_route') !== false && strpos($fn($rest, 'set_journey'), 'select_customer_route') !== false, 'form, AJAX and REST route selection use the gated entry point');
F(strpos($fn($front, 'ajax_select_services'), 'set_customer_journey') === false, 'ajax_select_services no longer writes the route before services exist (R-07)');
preg_match_all('/set_customer_journey\(/', $strip($all), $m);
$callers = [];
foreach (['select_customer_route', 'create_complaint', 'resolve_complaint'] as $f) $callers[$f] = substr_count($fn($wf, $f), 'set_customer_journey(');
F(count($m[0]) - 1 === array_sum($callers) && $callers['select_customer_route'] === 1 && $callers['create_complaint'] === 1 && $callers['resolve_complaint'] === 2, 'set_customer_journey only called by the gated route path, complaint creation and the legacy outcome handler');
$gate = $fn($wf, 'journey_gate');
F(strpos($gate, "self::PROFILE_PENDING") !== false && strpos($gate, "'incomplete'") !== false && strpos($gate, "'awaiting_reallocation'") !== false && strpos($gate, "'new_contact_required'") !== false, 'gate states: pending · incomplete · awaiting_reallocation · new_contact_required · open');
F(strpos($gate, 'is_manager') === false && strpos($gate, "\$gate['can_select'] = \$active && (int)\$active->agent_user_id === \$user_id;") !== false, 'only the assigned contact agent selects the route (D-07, v0.8.66.1)');

echo "--- 5/6/7: deficiency completion → same customer → tag → supervisor pool → new contact\n";
F(substr_count($def, 'self::after_completion(') === 4, 'all four completion paths hand off (tolerance auto, corrected amount, finance auto-complete, final decision)');
$on = $fn($wf, 'on_deficiency_completed');
F(strpos($on, "tag_customer(\$customer_id, 'COMPLETED_AFTER_DEFICIENCY'") !== false, 'existing tag COMPLETED_AFTER_DEFICIENCY re-used (no duplicate tag)');
F(strpos($on, "SET status='returned', completed_at=%s WHERE customer_id=%d AND status='assigned'") !== false && strpos($on, "'assignment_returned'") !== false, 'previous contact assignment ended with the existing «returned» status + same audit/activity as «برگشت تخصیص»');
F(!preg_match("/status='completed'|'status'\s*=>\s*'completed'/", $on) && !preg_match("/assignments[^;]*status='completed'/", $strip($all)), 'no new assignment status (D-05 «completed» documented, not implemented)');
F(!preg_match('/->insert\([^)]*customers|wp_insert_user|INSERT INTO[^;]*customers/', $on) && !preg_match('/set_customer_journey|select_customer_route/', $on), 'completion never creates a customer and never selects a route');
F(strpos($on, 'if ($done) return;') !== false && strpos($on, "if (\$profile !== self::PROFILE_COMPLETE) return;") !== false, 'idempotent per case; only when the profile is actually complete');
F(strpos($fn($rest, 'create_interaction'), 'interaction_must_be_new') !== false, 'new contact after reallocation is stored as a NEW interaction');
F(strpos($fn($def, 'send_to_finance'), "current_user_can('send_case_to_finance')") !== false, 'Finance Verification remains an optional step of the existing deficiency workflow');

echo "--- 11/12/14: بدون خدمات archive · خدمات مازاد boundary · no external API\n";
F(strpos($sel, "'no_service_archived'") !== false && strpos($sel, "'complaint_created' => false, 'biawin_request' => false") !== false, 'بدون خدمات: archive activity, no complaint, no Biawin');
F(strpos($front, "\$filter === 'no_request'") !== false && strpos($front, "'no_request'=>'بایگانی بدون خدمات'") !== false, 'searchable no-service archive in the customer list');
$b = $fn($wf, 'record_additional_services_boundary');
F(strpos($b, "'biawin_status' => 'NOT_SENT'") !== false && strpos($b, "do_action('bcrm_additional_services_boundary'") !== false, 'boundary = internal activity + action hook only (NOT_SENT)');
F(!preg_match('/wp_remote_|curl_init|WP_Http|wp_safe_remote|fsockopen|file_get_contents\(\s*[\'"]https?:/', $strip($all)), 'no outbound HTTP anywhere in the plugin');
F(substr_count($strip($all), 'record_additional_services_boundary(') === 2 && strpos($fn($wf, 'select_services'), 'record_additional_services_boundary') !== false, 'boundary reachable only from select_services (خدمات مازاد), never from complaint/refund/offset');
F(strpos($fn($wf, 'resolve_complaint'), 'record_additional_services_boundary') === false && strpos($fn($wf, 'create_refund'), 'record_additional_services_boundary') === false, 'ناراضی / Complaint / عودت / تهاتر never reach the boundary');

echo "--- 10: complaint semantics unchanged in Phase 2 (documented legacy)\n";
F(strpos($fn($wf, 'complaint_outcome_handlers'), "['satisfied', 'refund', 'service_request']") !== false && strpos($fn($wf, 'create_refund'), "'journey_state' => 'refund'") !== false, 'outcome handlers and legacy journey_state=refund unchanged (Phase 3/5)');

echo "--- 15/16: UI\n";
$panel = $fn($front, 'render_journey_panel');
F(strpos($panel, "if (\$locked) {") !== false && strpos($panel, 'return;') !== false && strpos($panel, 'data-bcrm-select-journey') > strpos($panel, 'return;'), 'locked route panel renders no selectable route');
F(strpos($fn($front, 'render_journey_status'), 'منتظر تخصیص') !== false && strpos($fn($front, 'render_journey_status'), 'نیازمند تماس جدید') !== false && strpos($fn($front, 'render_journey_status'), 'تکمیل‌شده پس از رفع ناقصی') !== false, 'status strip: completeness · mismatch · deficiency · assignment/contact · route + explicit indicators');
F(strpos($fn($front, 'render_service_panel'), '$blocked = empty($gate[\'can_select\'])') !== false && strpos($fn($front, 'render_complaint_panel'), '$can_create') !== false, 'services and complaint creation locked with the route');
F(strpos($front, 'باید در کارتابل تأیید مالی تکمیل شود') === false && strpos($front, 'مغایرت مالی مانع تعیین مسیر نیست') === false && strpos($front, 'انتخاب خدمات حتی در صورت مغایرت مالی مجاز است') === false, 'contradicting texts removed (finance mandatory / mismatch does not block route)');
F(strpos($js, "qsa(\"[data-bcrm-select-journey]\", journey).forEach(function (el) { el.disabled = false; });") === false && strpos($js, 'applyRouteGate(s.route_gate)') !== false, 'JS never unlocks the route; it follows the server gate');
F(strpos($js, 'if(journey==="complaint") {') !== false, 'ناراضی card opens the complaint form (hand-off), no bare route write');
F(strpos($fn($front, 'render_complaint_panel'), "(current_user_can('bcrm_complaints') || current_user_can('bcrm_complaints') || current_user_can('bcrm_manage_cases'))") === false && strpos($front, "if (\$c->status === 'open' && (current_user_can('bcrm_complaints') || current_user_can('bcrm_manage_cases')))") !== false, 'complaint resolution stays with after-sales/complaint roles (general agent has no complaint workflow)');

echo "--- 6/13: supervisor queue + reports\n";
F(strpos($fn($adm, 'render_reallocation_card'), 'name="action" value="bcrm_assign_customers"') !== false && strpos($fn($adm, 'render_reallocation_card'), 'awaiting_reallocation_rows') !== false, 'reallocation queue posts to the EXISTING assignment handler (no second engine)');
F(!preg_match('/->insert\(|->update\(|->delete\(|->query\(|INSERT |UPDATE |DELETE |update_option|add_option/', $strip($rep)), 'journey reports are read-only');
F(strpos($rep, "'biawin_data' => 'none'") !== false && strpos($rep, 'via_complaint_outcome') !== false && strpos($rep, 'duplicate_active_service_cases') !== false, 'reconciliation report: route, services, origin, duplicates, Biawin data');
F(strpos($rep, 'new_deficiency') !== false && strpos($rep, 'complete_with_open_deficiency_case') !== false, 'D-01 dry-run: populations, changes, new deficiency cases, open-case ambiguity');

echo "TOTAL_FAIL=$fail (passed $pass)\n";
exit($fail ? 1 : 0);
