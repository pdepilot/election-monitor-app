<?php
declare(strict_types=1);

/**
 * @return array{db_host: string, db_port: int, db_name: string, db_user: string, db_pass: string, private_dir: string, sheet_key_file: string, app_url: string, mail_transport: string, mail_from: string, mail_from_name: string, mail_capture_dir: string, smtp_host: string, smtp_port: int, smtp_encryption: string, smtp_username: string, smtp_password: string}
 */
function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $fileConfig = [];
    $envFile = __DIR__ . '/env.php';
    if (is_file($envFile)) {
        $loaded = require $envFile;
        if (is_array($loaded)) {
            $fileConfig = $loaded;
        }
    }

    $fromEnv = static function (string $key): ?string {
        $value = getenv($key);
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    };

    $fromFile = static function (string $key, string $default) use ($fileConfig): string {
        if (!array_key_exists($key, $fileConfig) || !is_scalar($fileConfig[$key])) {
            return $default;
        }

        return (string) $fileConfig[$key];
    };

    // DB_USER and DB_PASS must be set explicitly (environment or config/env.php).
    // There is no silent fallback to MySQL root with an empty password.
    // An empty password is allowed only when DB_PASS or env.php db_pass is set explicitly
    // (typical local XAMPP). Prefer a dedicated least-privilege user for any shared host.
    $dbUserEnv = getenv('DB_USER');
    if (is_string($dbUserEnv) && $dbUserEnv !== '') {
        $dbUser = $dbUserEnv;
    } elseif (array_key_exists('db_user', $fileConfig) && is_scalar($fileConfig['db_user']) && (string) $fileConfig['db_user'] !== '') {
        $dbUser = (string) $fileConfig['db_user'];
    } else {
        $dbUser = null;
    }

    $dbPassEnv = getenv('DB_PASS');
    if ($dbPassEnv !== false) {
        $dbPass = (string) $dbPassEnv;
    } elseif (array_key_exists('db_pass', $fileConfig) && is_scalar($fileConfig['db_pass'])) {
        $dbPass = (string) $fileConfig['db_pass'];
    } else {
        $dbPass = null;
    }

    if ($dbUser === null || $dbPass === null) {
        error_log('Database credentials are not configured. Set DB_USER and DB_PASS, or db_user and db_pass in config/env.php.');
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Database credentials are not configured. Copy config/env.example.php to config/env.php and set db_user and db_pass.\n");
            exit(1);
        }
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo 'The service is temporarily unavailable. Please try again later.';
        exit;
    }

    $config = [
        'db_host' => $fromEnv('DB_HOST') ?? $fromFile('db_host', '127.0.0.1'),
        'db_port' => (int) ($fromEnv('DB_PORT') ?? $fromFile('db_port', '3306')),
        'db_name' => $fromEnv('DB_NAME') ?? $fromFile('db_name', 'election_monitor_app'),
        'db_user' => $dbUser,
        'db_pass' => $dbPass,
    ];
    $privateDir = $fromEnv('EM_PRIVATE_DIR') ?? $fromFile('private_dir', dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'election-monitor-private');
    $config['private_dir'] = rtrim($privateDir, '\\/');
    $config['sheet_key_file'] = $fromEnv('EM_SHEET_KEY_FILE')
        ?? $fromFile('sheet_key_file', $config['private_dir'] . DIRECTORY_SEPARATOR . 'keys' . DIRECTORY_SEPARATOR . 'sheet-hmac.json');

    $config['app_url'] = rtrim($fromEnv('APP_URL') ?? $fromFile('app_url', ''), '/');
    $config['mail_transport'] = strtolower($fromEnv('MAIL_TRANSPORT') ?? $fromFile('mail_transport', 'disabled'));
    $config['mail_from'] = $fromEnv('MAIL_FROM') ?? $fromFile('mail_from', '');
    $config['mail_from_name'] = $fromEnv('MAIL_FROM_NAME') ?? $fromFile('mail_from_name', 'Ohaji/Egbema Election Monitoring');
    $config['mail_capture_dir'] = $fromEnv('MAIL_CAPTURE_DIR') ?? $fromFile('mail_capture_dir', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'mail-capture');
    $config['smtp_host'] = $fromEnv('SMTP_HOST') ?? $fromFile('smtp_host', '');
    $config['smtp_port'] = (int) ($fromEnv('SMTP_PORT') ?? $fromFile('smtp_port', '587'));
    $config['smtp_encryption'] = strtolower($fromEnv('SMTP_ENCRYPTION') ?? $fromFile('smtp_encryption', 'tls'));
    $config['smtp_username'] = $fromEnv('SMTP_USERNAME') ?? $fromFile('smtp_username', '');
    $config['smtp_password'] = $fromEnv('SMTP_PASSWORD') ?? $fromFile('smtp_password', '');
    $config['trusted_proxies'] = $fromEnv('TRUSTED_PROXIES') ?? $fromFile('trusted_proxies', '');
    $forceHttps = $fromEnv('FORCE_HTTPS');
    if ($forceHttps !== null) {
        $config['force_https'] = in_array(strtolower($forceHttps), ['1', 'true', 'yes', 'on'], true);
    } else {
        $rawForce = $fileConfig['force_https'] ?? false;
        $config['force_https'] = is_bool($rawForce)
            ? $rawForce
            : in_array(strtolower((string) $rawForce), ['1', 'true', 'yes', 'on'], true);
    }
    $enableHsts = $fromEnv('ENABLE_HSTS');
    if ($enableHsts !== null) {
        $config['enable_hsts'] = in_array(strtolower($enableHsts), ['1', 'true', 'yes', 'on'], true);
    } else {
        $rawHsts = $fileConfig['enable_hsts'] ?? false;
        $config['enable_hsts'] = is_bool($rawHsts)
            ? $rawHsts
            : in_array(strtolower((string) $rawHsts), ['1', 'true', 'yes', 'on'], true);
    }

    return $config;
}

/**
 * True when $path resolves under $root (both normalized). Used to keep private storage and keys off the web tree.
 */
function app_path_is_under(string $path, string $root): bool
{
    $rootReal = realpath($root);
    if ($rootReal === false) {
        return false;
    }
    $pathReal = realpath($path);
    if ($pathReal === false) {
        $parent = realpath(dirname($path));
        if ($parent === false) {
            return false;
        }
        $pathReal = $parent . DIRECTORY_SEPARATOR . basename($path);
    }
    $rootNorm = strtolower(rtrim(str_replace('\\', '/', $rootReal), '/') . '/');
    $pathNorm = strtolower(str_replace('\\', '/', $pathReal));

    return str_starts_with($pathNorm . '/', $rootNorm) || str_starts_with($pathNorm, rtrim($rootNorm, '/'));
}

/**
 * Refuse private_dir / sheet_key_file locations under the document root or the application directory.
 *
 * @return list<string> human-readable problems (empty when safe)
 */
function app_storage_path_problems(): array
{
    static $problems = null;
    if ($problems !== null) {
        return $problems;
    }
    $problems = [];
    $config = app_config();
    $appRoot = dirname(__DIR__);
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $targets = [
        'private_dir' => (string) $config['private_dir'],
        'sheet_key_file' => (string) $config['sheet_key_file'],
    ];
    foreach ($targets as $label => $path) {
        if ($path === '') {
            continue;
        }
        if (is_string($docRoot) && $docRoot !== '' && app_path_is_under($path, $docRoot)) {
            $problems[] = $label . ' must not be under the web document root.';
        }
        if (app_path_is_under($path, $appRoot)) {
            $problems[] = $label . ' must not be under the application directory.';
        }
    }

    return $problems;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = app_config();

    try {
        $host = $config['db_host'];
        $name = $config['db_name'];
        $port = $config['db_port'];
        if (
            !preg_match('/^[A-Za-z0-9._-]+$/', $host)
            || !preg_match('/^[A-Za-z0-9_]+$/', $name)
            || $port < 1
            || $port > 65535
        ) {
            throw new RuntimeException('Invalid database configuration.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);
        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Database connection failed. Check environment variables or config/env.php.\n");
            exit(1);
        }
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo 'The service is temporarily unavailable. Please try again later.';
        exit;
    }

    return $pdo;
}
