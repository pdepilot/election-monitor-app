<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

function app_bootstrap(): void
{
    $logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0750, true);
    }

    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);

    set_exception_handler(static function (Throwable $e): void {
        error_log('Unhandled ' . $e::class . ': ' . $e->getMessage());
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "An unexpected error occurred.\n");
            exit(1);
        }
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo 'The service is temporarily unavailable. Please try again later.';
        exit;
    });

    $logFile = $logDir . DIRECTORY_SEPARATOR . 'php-error.log';
    if (is_dir($logDir) && (is_writable($logDir) || (is_file($logFile) && is_writable($logFile)))) {
        ini_set('error_log', $logFile);
    }

    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }

    if (app_force_https() && !app_is_https()) {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        if (is_string($host) && $host !== '' && preg_match('/\A[a-z0-9.-]+(?::\d+)?\z/i', $host) === 1) {
            header('Location: https://' . $host . $uri, true, 302);
            exit;
        }
    }

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    $nonce = app_csp_nonce();
    // Third-party font/icon CDNs remain until assets are self-hosted; do not widen this list.
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "style-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://fonts.googleapis.com; "
        . "style-src-attr 'unsafe-inline'; "
        . "font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com data:; "
        . "script-src 'nonce-{$nonce}'; "
        . "worker-src 'self'; "
        . "img-src 'self' data:; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
    );
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
    if (app_is_https() && app_hsts_enabled()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function app_csp_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    return $nonce;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_base_path(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $docReal = is_string($docRoot) && $docRoot !== '' ? realpath($docRoot) : false;
    $appReal = realpath(dirname(__DIR__));

    if ($docReal === false || $appReal === false) {
        return $cached = '';
    }

    $docNorm = strtolower(rtrim(str_replace('\\', '/', $docReal), '/'));
    $appNorm = strtolower(rtrim(str_replace('\\', '/', $appReal), '/'));

    if ($appNorm === $docNorm) {
        return $cached = '';
    }

    if (!str_starts_with($appNorm, $docNorm . '/')) {
        return $cached = '';
    }

    return $cached = substr($appNorm, strlen($docNorm));
}

function app_cookie_path(): string
{
    $base = app_base_path();

    return $base === '' ? '/' : $base;
}

/**
 * Client IPs of reverse proxies that may set X-Forwarded-Proto / X-Forwarded-SSL.
 * Empty by default — forwarded headers are ignored unless configured.
 *
 * @return list<string>
 */
function app_trusted_proxy_ips(): array
{
    static $ips = null;
    if ($ips !== null) {
        return $ips;
    }
    $raw = '';
    if (function_exists('app_config')) {
        $raw = (string) (app_config()['trusted_proxies'] ?? '');
    }
    $env = getenv('TRUSTED_PROXIES');
    if (is_string($env) && $env !== '') {
        $raw = $env;
    }
    $ips = [];
    foreach (preg_split('/[\s,]+/', $raw) ?: [] as $part) {
        $part = trim($part);
        if ($part !== '' && filter_var($part, FILTER_VALIDATE_IP)) {
            $ips[] = $part;
        }
    }

    return $ips;
}

function app_request_from_trusted_proxy(): bool
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_string($remote) || filter_var($remote, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    return in_array($remote, app_trusted_proxy_ips(), true);
}

function app_force_https(): bool
{
    if (function_exists('app_config')) {
        $flag = app_config()['force_https'] ?? false;
        if (is_bool($flag)) {
            return $flag;
        }
        if (is_scalar($flag)) {
            return in_array(strtolower((string) $flag), ['1', 'true', 'yes', 'on'], true);
        }
    }
    $env = getenv('FORCE_HTTPS');

    return is_string($env) && in_array(strtolower($env), ['1', 'true', 'yes', 'on'], true);
}

function app_hsts_enabled(): bool
{
    if (function_exists('app_config')) {
        $flag = app_config()['enable_hsts'] ?? false;
        if (is_bool($flag)) {
            return $flag;
        }
        if (is_scalar($flag)) {
            return in_array(strtolower((string) $flag), ['1', 'true', 'yes', 'on'], true);
        }
    }
    $env = getenv('ENABLE_HSTS');

    return is_string($env) && in_array(strtolower($env), ['1', 'true', 'yes', 'on'], true);
}

function app_is_https(): bool
{
    $https = $_SERVER['HTTPS'] ?? '';
    if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
        return true;
    }

    // Honour forwarded TLS only when the immediate peer is an explicitly trusted proxy.
    if (!app_request_from_trusted_proxy()) {
        return false;
    }

    $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if (is_string($forwarded) && $forwarded !== '') {
        $scheme = strtolower(trim(explode(',', $forwarded)[0]));
        if ($scheme === 'https') {
            return true;
        }
    }

    $forwardedSsl = $_SERVER['HTTP_X_FORWARDED_SSL'] ?? '';

    return is_string($forwardedSsl) && strtolower($forwardedSsl) === 'on';
}

function url(string $path): string
{
    $path = ltrim(str_replace('\\', '/', $path), '/');
    $query = '';
    if (str_contains($path, '?')) {
        [$path, $query] = explode('?', $path, 2);
        $query = '?' . $query;
    }
    if (str_ends_with(strtolower($path), '.php')) {
        $path = substr($path, 0, -4);
    }
    if ($path === 'index') {
        $path = '';
    } elseif (str_ends_with($path, '/index')) {
        $path = substr($path, 0, -6);
    }
    $base = app_base_path();
    if ($path === '') {
        return ($base === '' ? '/' : $base . '/') . $query;
    }

    return ($base === '' ? '' : $base) . '/' . $path . $query;
}

function asset_url(string $path): string
{
    $full = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $path), '/');
    $version = is_file($full) ? (string) filemtime($full) : '1';

    return url($path) . '?v=' . rawurlencode($version);
}

function redirect_to(string $path): never
{
    // Allow a simple query string (e.g. portal/login.php?setup=1). Reject anything else.
    if (!preg_match('#^[a-z0-9_./-]+(?:\?[a-z0-9_&=.-]*)?$#i', $path)) {
        $path = 'portal/login.php';
    }

    header('Location: ' . url($path), true, 302);
    exit;
}

function app_set_cookie(string $name, string $value, int $expires): void
{
    setcookie($name, $value, [
        'expires' => $expires,
        'path' => app_cookie_path(),
        'domain' => '',
        'secure' => app_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[$name] = $value;
}

function app_expire_cookie(string $name): void
{
    setcookie($name, '', [
        'expires' => time() - 42000,
        'path' => app_cookie_path(),
        'domain' => '',
        'secure' => app_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[$name]);
}

function app_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = app_is_https();
    session_name('EM_PORTAL');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => app_cookie_path(),
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', $secure ? '1' : '0');
    session_start();
}

app_bootstrap();
