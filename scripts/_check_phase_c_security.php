<?php
declare(strict_types=1);

// Phase C targeted checks: path guards, trusted-proxy HTTPS, generic auth messages, offline logout asset.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/sheet_integrity.php';

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

echo "-- path guards\n";
$problems = app_storage_path_problems();
check($problems === [], 'configured private_dir and sheet_key_file are outside app and document root');
$appRoot = dirname(__DIR__);
check(app_path_is_under($appRoot . DIRECTORY_SEPARATOR . 'storage', $appRoot), 'app_path_is_under detects paths under the application directory');
check(!app_path_is_under(app_config()['private_dir'], $appRoot), 'default private_dir is not under the application directory');

echo "-- trusted proxy HTTPS detection\n";
$remote = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['HTTPS'] = 'off';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
check(app_trusted_proxy_ips() === [] || !in_array('203.0.113.10', app_trusted_proxy_ips(), true), 'no accidental trust of the test IP');
check(app_is_https() === false, 'X-Forwarded-Proto alone does not mark the request as HTTPS');
if ($remote !== null) {
    $_SERVER['REMOTE_ADDR'] = $remote;
} else {
    unset($_SERVER['REMOTE_ADDR']);
}
unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTPS']);

echo "-- generic auth messages\n";
$src = (string) file_get_contents(dirname(__DIR__) . '/includes/auth.php');
check(str_contains($src, 'AUTH_GENERIC_FAILURE') && str_contains($src, 'AUTH_THROTTLED_MESSAGE'), 'auth uses generic failure and throttle constants');
check(!str_contains($src, 'attempts left before this account is locked'), 'attempts-left enumeration message is gone');

echo "-- offline logout helper\n";
$logoutJs = dirname(__DIR__) . '/assets/js/offline-logout.js';
$shell = (string) file_get_contents(dirname(__DIR__) . '/includes/dashboard_view.php');
check(is_file($logoutJs) && str_contains((string) file_get_contents($logoutJs), 'deleteMeta'), 'offline-logout.js clears unit/agent meta only');
check(str_contains($shell, 'offline-logout.js') && str_contains($shell, 'data-user-id'), 'agent shell loads offline-logout and exposes user id');

echo "-- CSP script-src no longer allows jsDelivr scripts\n";
$boot = (string) file_get_contents(dirname(__DIR__) . '/includes/bootstrap.php');
check(preg_match("/script-src 'nonce-[^']*';/", $boot) === 1 || str_contains($boot, "script-src 'nonce-{\$nonce}'"), 'script-src is nonce-only without cdn.jsdelivr.net');

echo ($failures === 0 ? "\nAll Phase C security checks passed.\n" : "\n{$failures} Phase C security check(s) failed.\n");
exit($failures === 0 ? 0 : 1);
