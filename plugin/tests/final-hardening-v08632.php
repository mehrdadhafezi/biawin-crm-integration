<?php
/**
 * v0.8.63.2 — Phase 1 final hardening: static guards + File_Safety runtime checks.
 * The disabled-function case is REAL: the script re-runs itself under
 * `php -d disable_functions=disk_free_space,disk_total_space` (the production condition).
 * On Linux, /dev/full gives a real ENOSPC for the checked-write test.
 * Usage: php tests/final-hardening-v08632.php
 */
$root = str_replace('\\', '/', dirname(__DIR__));
$pass = 0; $fail = 0;
function ok($c, $label) { global $pass, $fail; echo ($c ? 'PASS  ' : 'FAIL  ') . $label . "\n"; $c ? $pass++ : $fail++; }

if (($argv[1] ?? '') === '--runtime') {
    define('ABSPATH', __DIR__ . '/');
    require $root . '/includes/class-biawin-crm-file-safety.php';
    $FS = 'Biawin_CRM_File_Safety';
    $disabled = stripos((string)ini_get('disable_functions'), 'disk_free_space') !== false;
    echo '--- runtime, disk_free_space ' . ($disabled ? 'DISABLED' : 'available') . ' (PHP ' . PHP_VERSION . ")\n";
    ok($FS::function_available('disk_free_space') === !$disabled, 'function_available() reflects disable_functions');
    $free = $FS::disk_free_bytes(sys_get_temp_dir());
    ok($disabled ? $free === null : (is_float($free) && $free > 0), 'disk_free_bytes(): ' . ($disabled ? 'NULL (unknown) without fatal' : 'real number'));
    $d = $FS::make_temp_dir('unit test!');
    ok($d !== '' && is_dir($d) && strpos(basename($d), 'bcrm-tmp-unittest-') === 0, 'make_temp_dir(): private prefixed dir, purpose sanitised');
    $fh = fopen("$d/a.txt", 'wb');
    ok($FS::write($fh, 'abc') === true, 'write(): full write → true');
    fclose($fh);
    ok($FS::write($fh, 'abc') === false, 'write(): closed handle → false');
    if (is_writable('/dev/full')) {
        $full = fopen('/dev/full', 'wb');
        ok($FS::write($full, str_repeat('x', 65536)) === false, 'write(): real ENOSPC (/dev/full) → false');
    }
    $z = $FS::build_zip($d, 'x.xlsx', ['[Content_Types].xml' => '<x/>', 'xl/worksheets/sheet1.xml' => '<w/>']);
    ok($z !== '' && is_file($z) && filesize($z) > 0, 'build_zip(): package written');
    ok($FS::build_zip('', 'x.xlsx', ['a' => 'b']) === '', 'build_zip(): no temp dir → "" (no TypeError)');
    $outside = sys_get_temp_dir() . '/not-ours-' . getmypid() . '.txt';
    file_put_contents($outside, 'keep');
    $FS::remove($outside);
    ok(is_file($outside), 'remove(): refuses paths without the bcrm-tmp- prefix');
    @unlink($outside);
    $FS::remove($d);
    ok(!file_exists($d), 'remove(): temp dir and contents gone');
    $old = sys_get_temp_dir() . '/bcrm-tmp-stale-' . getmypid();
    @mkdir($old); touch("$old/f"); touch($old, time() - 3 * 3600);
    $fresh = sys_get_temp_dir() . '/bcrm-tmp-fresh-' . getmypid();
    @mkdir($fresh);
    $FS::sweep_stale(7200);
    ok(!file_exists($old) && is_dir($fresh), 'sweep_stale(): removes only stale bcrm-tmp-* leftovers');
    $FS::remove($fresh);
    $d2 = $FS::make_temp_dir('shutdown');
    file_put_contents(sys_get_temp_dir() . '/bcrm-shutdown-probe-' . getmypid(), $d2);
    exit($fail ? 1 : 0); // shutdown cleanup must remove $d2
}

$read = function ($rel) use ($root) { return (string)file_get_contents($root . '/' . $rel); };
$strip = function ($src) { return preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $src); }; // ignore comments
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (substr($p, -4) === '.php' && strpos($p, '/tests/') === false) $files[$p] = (string)file_get_contents($p);
}
$all = implode("\n", $files);
$main = $read('biawin-crm-integration.php');
$admin = $read('admin/class-biawin-crm-admin.php');
$act = $read('includes/class-biawin-crm-activator.php');

echo "--- static\n";
$bad = [];
foreach ($files as $p => $src) {
    if (basename($p) !== 'class-biawin-crm-file-safety.php' && preg_match('/(?<![\w\'"])disk_(free|total)_space\s*\(/', $strip($src))) $bad[] = basename($p);
}
ok(!$bad, 'disk_free_space()/disk_total_space() called only inside File_Safety (guarded)' . ($bad ? ' — ' . implode(', ', $bad) : ''));
$exports = $admin . $read('includes/class-biawin-crm-frontend.php') . $read('includes/class-biawin-crm-deficiency-workflow.php');
ok(!preg_match('/tempnam\s*\(/', $strip($exports)), 'export paths use no tempnam()/wp_tempnam() (a false return caused a ZipArchive TypeError)');
ok(strpos($main, 'class-biawin-crm-file-safety.php') !== false, 'File_Safety loaded by the main plugin file');
preg_match('/Version:\s*([\d.]+)/', $main, $hv); preg_match("/BIAWIN_CRM_VERSION',\s*'([\d.]+)'/", $main, $cv); preg_match('/Stable tag:\s*([\d.]+)/', $read('readme.txt'), $rv);
ok(($hv[1] ?? '') !== '' && $hv[1] === ($cv[1] ?? '') && $hv[1] === ($rv[1] ?? '') && version_compare($hv[1], '0.8.63.2', '>='), 'version consistent in header, constant and readme (>= 0.8.63.2)');
ok(strpos($read('CHANGELOG.md'), '## 0.8.63.2') !== false && strpos($read('readme.txt'), '= 0.8.63.2 =') !== false, 'CHANGELOG + readme changelog have 0.8.63.2');
ok(!preg_match('/wp_create_user\s*\(|wp_insert_user\s*\(|wp_set_password\s*\(|reset_password\s*\(/', $strip($act . $main)) && strpos($all, '12345678') === false, 'updater (activator + bootstrap) never creates users or sets passwords; no default password anywhere');
ok(strpos($act, "'bcrm_upgrade_' . md5(") !== false && strpos($act, 'GET_LOCK') !== false && strpos($act, 'RELEASE_LOCK') !== false, 'migration lock: atomic GET_LOCK scoped to db+prefix, released in finally');
ok(strpos($admin, "stream_customer_report_csv('low_disk'") !== false && strpos($admin, "stream_customer_report_csv('xlsx_write_failed'") !== false && strpos($admin, "stream_customer_report_csv('temp_unavailable'") !== false, 'customer export: low-disk / temp-unavailable / write-failure fall back to CSV');
ok(strpos($admin, 'BCRM_BACKUP_KEEP') !== false && strpos($admin, 'BCRM_BACKUP_DIR') !== false && strpos($admin, 'prune_backups') !== false, 'backups: retention + configurable location (off-uploads)');
ok(strpos($admin, 'Require all denied') !== false && strpos($admin, 'web.config') !== false, 'backup directory is web-denied (Apache 2.2/2.4 + IIS)');
ok(strpos($all, 'WP_PLUGIN_DIR') === false, 'no code writes copies into wp-content/plugins (WP_PLUGIN_DIR unused)');
ok(strpos($read('includes/class-biawin-crm-import-service.php'), 'sweep_stale_import_files') !== false, 'import temp/staging files are swept');

echo "--- runtime (subprocesses)\n";
$self = escapeshellarg(__FILE__); $php = escapeshellarg(PHP_BINARY);
foreach (['' => 'available', '-d disable_functions=disk_free_space,disk_total_space' => 'DISABLED'] as $flags => $label) {
    $out = []; $code = 0;
    exec("$php -d display_errors=1 -d error_reporting=-1 $flags $self --runtime 2>&1", $out, $code);
    $text = preg_replace('/^.*PHP Startup: Unable to load dynamic library.*$/m', '', implode("\n", $out)); // local Windows ext noise
    echo '  ' . implode("\n  ", array_filter(explode("\n", $text), 'strlen')) . "\n";
    ok($code === 0 && !preg_match('/(Fatal error|Warning|Notice|Deprecated):/', $text), "runtime with disk_free_space $label: exit 0, no PHP fatal/warning/notice");
    foreach (glob(sys_get_temp_dir() . '/bcrm-shutdown-probe-*') ?: [] as $pf) {
        $dir = trim((string)file_get_contents($pf));
        ok($dir !== '' && !file_exists($dir), "shutdown cleanup removed the registered temp dir ($label)");
        unlink($pf);
    }
}
echo "\nTOTAL: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
