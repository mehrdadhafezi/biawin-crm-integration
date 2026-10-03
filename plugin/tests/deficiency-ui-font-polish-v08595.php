<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/biawin-crm-integration.php');
$activator = file_get_contents($root . '/includes/class-biawin-crm-activator.php');
$frontend = file_get_contents($root . '/includes/class-biawin-crm-frontend.php');
$admin = file_get_contents($root . '/admin/class-biawin-crm-admin.php');
$workflow = file_get_contents($root . '/includes/class-biawin-crm-deficiency-workflow.php');
$css = file_get_contents($root . '/assets/portal-fixes.css');
$js = file_get_contents($root . '/assets/portal-fixes.js');
$readme = file_get_contents($root . '/readme.txt');

$pass = 0;
$fail = 0;
function d595_assert_true($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: $message\n";
    } else {
        $fail++;
        echo "FAIL: $message\n";
    }
}
function d595_body($source, $start, $end) {
    $pos = strpos($source, $start);
    if ($pos === false) return '';
    $next = strpos($source, $end, $pos + strlen($start));
    return $next === false ? substr($source, $pos) : substr($source, $pos, $next - $pos);
}

$settings_page = d595_body($admin, 'public static function settings_page', 'public static function handle_settings');
$handle_settings = d595_body($admin, 'public static function handle_settings', 'public static function financial_page');
$render_shell = d595_body($frontend, 'private static function render_shell', 'private static function current_role_label');

d595_assert_true(strpos($main, "Version: 0.8.59.7") !== false && strpos($main, "define('BIAWIN_CRM_VERSION', '0.8.59.7')") !== false, 'version bumped to 0.8.59.7');
d595_assert_true(strpos($readme, 'Stable tag: 0.8.59.7') !== false, 'readme stable tag bumped');
d595_assert_true(strpos($activator, "'0.8.59.4'") !== false && strpos($activator, "BIAWIN_CRM_VERSION === '0.8.59.7'") !== false, '0.8.59.4 to 0.8.59.7 upgrade is code-only');

d595_assert_true(strpos($frontend, 'font_choices') !== false && strpos($frontend, 'Vazirmatn') !== false && strpos($frontend, 'IRANSans') !== false && strpos($frontend, 'Dana') !== false, 'portal exposes Persian-friendly font choices');
d595_assert_true(strpos($frontend, 'portal_font_stack') !== false && strpos($frontend, 'biawin_crm_portal_font') !== false, 'portal font stack reads saved setting');
d595_assert_true(strpos($frontend, 'wp_add_inline_style') !== false && strpos($frontend, '--bcrm-font-family') !== false, 'frontend applies selected font as CSS variable');
d595_assert_true(strpos($render_shell, 'data-bcrm-font-switch') !== false && strpos($render_shell, 'data-font-stack') !== false, 'portal header renders quick font switcher');
d595_assert_true(strpos($settings_page, 'portal_font') !== false && strpos($settings_page, 'فونت پیش‌فرض پرتال') !== false, 'CRM settings include global portal font selector');
d595_assert_true(strpos($handle_settings, 'update_option(\'biawin_crm_portal_font\'') !== false, 'CRM settings persist selected portal font');
d595_assert_true(strpos($admin, 'Biawin_CRM_Frontend::portal_font_stack') !== false, 'admin CRM screens reuse selected font stack');

d595_assert_true(strpos($js, 'data-bcrm-font-switch') !== false && strpos($js, 'localStorage.setItem("bcrm_font"') !== false, 'font switcher persists per-user browser preference');
d595_assert_true(strpos($js, '--bcrm-font-family') !== false, 'font switcher applies font without page reload');

d595_assert_true(strpos($workflow, 'data-bcrm-receipts') !== false && strpos($workflow, 'data-bcrm-add-receipt') !== false, 'receipt UI exposes repeatable receipt controls');
d595_assert_true(strpos($js, 'dataset.receiptsReady') !== false && strpos($js, 'data-bcrm-add-receipt') !== false && strpos($js, 'data-bcrm-remove-receipt') !== false, 'receipt add/remove handlers are wired without relying on workflowReady');
d595_assert_true(strpos(file_get_contents($root . '/assets/portal-fixes.js'), 'function initReceiptUploader') !== false && strpos(file_get_contents($root . '/assets/portal-fixes.js'), 'list.appendChild(r)') !== false, 'receipt buttons wired by the single uploader (portal-fixes.js, v0.8.62.27)');
d595_assert_true(strpos($css, '[data-bcrm-receipts]') !== false && strpos($css, 'grid-column:1/-1') !== false, 'receipt list spans full form width');
d595_assert_true(strpos($css, '.bcrm-receipt-row') !== false && strpos($css, 'grid-template-columns:minmax(180px,.55fr) minmax(260px,1fr) auto') !== false, 'receipt row uses stable amount/file/remove columns');
d595_assert_true(strpos($css, '[data-bcrm-add-receipt]') !== false && strpos($css, 'justify-self:start') !== false, 'add receipt button stays compact');
d595_assert_true(strpos($css, '@media(max-width:900px)') !== false && strpos($css, '.bcrm-receipt-row{grid-template-columns:1fr') !== false, 'receipt UI collapses cleanly on mobile');

echo "TOTAL_PASS=$pass\nTOTAL_FAIL=$fail\n";
exit($fail ? 1 : 0);
