<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/csrf.php';

const AUTH_ROLES = [
    'super_admin' => 'Super Administrator',
    'admin' => 'Administrator',
    'supervisor' => 'Supervisor',
    'collation_officer' => 'Collation Officer',
    'polling_agent' => 'Polling Agent',
    'viewer' => 'Viewer',
];

const AUTH_DASHBOARDS = [
    'super_admin' => 'admin/dashboard.php',
    'admin' => 'admin/dashboard.php',
    'supervisor' => 'supervisor/dashboard.php',
    'collation_officer' => 'agents/dashboard.php',
    'polling_agent' => 'agent/dashboard.php',
    'viewer' => 'viewer/dashboard.php',
];

const AUTH_REMEMBER_COOKIE = 'em_remember';
const AUTH_REMEMBER_DAYS = 30;
const AUTH_AGENT_REMEMBER_DAYS = 1;
/** Per-identifier ceiling inside the attempt window (must be above AUTH_HARD_LOCK_AFTER so permanent lock remains reachable). */
const AUTH_MAX_IDENTIFIER_ATTEMPTS = 20;
const AUTH_MAX_IP_ATTEMPTS = 40;
const AUTH_ATTEMPT_WINDOW_MINUTES = 15;
/** Temporary cool-down after this many failed passwords (clears automatically). */
const AUTH_TEMP_LOCK_AFTER = 5;
const AUTH_TEMP_LOCK_MINUTES = 15;
/** Permanent lock requiring an administrator unlock (never applied to the sole active super_admin). */
const AUTH_HARD_LOCK_AFTER = 15;
/** @deprecated Use AUTH_HARD_LOCK_AFTER; kept for locked-accounts page copy compatibility. */
const AUTH_LOCK_AFTER_FAILURES = AUTH_HARD_LOCK_AFTER;
/** Client-facing messages stay generic so lock state does not confirm that an account exists. */
const AUTH_GENERIC_FAILURE = 'Invalid username or password.';
const AUTH_THROTTLED_MESSAGE = 'Too many sign-in attempts. Please wait 15 minutes and try again.';
/** @deprecated Prefer AUTH_THROTTLED_MESSAGE; kept for older tests/pages. */
const AUTH_LOCKED_MESSAGE = AUTH_THROTTLED_MESSAGE;
const AUTH_TEMP_LOCKED_MESSAGE = AUTH_THROTTLED_MESSAGE;
const AUTH_DUMMY_HASH = '$2y$10$uk4a92e/djZ5sUKiU/yRuuLAl4MDGH4GHk.fPgrUMS.Whbc0ZsxnG';

const AUTH_PASSWORD_CHANGE_PAGES = ['portal/change-password.php', 'portal/logout.php', 'portal/login.php', 'portal/setup-password.php'];
/** Single-use password-setup links expire after this many hours. */
const AUTH_SETUP_TOKEN_HOURS = 48;
const AUTH_SETUP_PURPOSE = 'account_setup';
const AUTH_MAX_SETUP_ATTEMPTS = 8;
const AUTH_ADMIN_IDLE_SECONDS = 600;
const AUTH_AGENT_IDLE_SECONDS = 1800;
const AUTH_IDLE_SECONDS = [
    'super_admin' => AUTH_ADMIN_IDLE_SECONDS,
    'admin' => AUTH_ADMIN_IDLE_SECONDS,
    'polling_agent' => AUTH_AGENT_IDLE_SECONDS,
];

function auth_boot(): void
{
    app_start_session();
    if (auth_user() === null) {
        auth_restore_remember();
    }
    auth_enforce_idle();
    auth_require_password_change();
}

function auth_idle_seconds_for(string $role): ?int
{
    return AUTH_IDLE_SECONDS[$role] ?? null;
}

function auth_is_idle_role(string $role): bool
{
    return auth_idle_seconds_for($role) !== null;
}

/**
 * Signed-in roles with an idle policy are signed out after their idle window without a request.
 * Remember-me tokens for that account are revoked so the session cannot silently return.
 */
function auth_enforce_idle(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $user = auth_user();
    if ($user === null) {
        return;
    }
    $limit = auth_idle_seconds_for($user['role']);
    if ($limit === null) {
        return;
    }

    $now = time();
    $last = $_SESSION['last_activity'] ?? null;
    if (is_int($last) && $last > 0 && ($now - $last) >= $limit) {
        auth_end_idle_session((int) $user['id'], $limit);
        return;
    }
    $_SESSION['last_activity'] = $now;
}

/** @deprecated Use auth_enforce_idle(). */
function auth_enforce_admin_idle(): void
{
    auth_enforce_idle();
}

function auth_end_idle_session(int $userId, int $idleSeconds = AUTH_ADMIN_IDLE_SECONDS): void
{
    try {
        $revoke = db()->prepare('DELETE FROM remember_tokens WHERE user_id = :user_id');
        $revoke->execute(['user_id' => $userId]);
    } catch (Throwable $e) {
        error_log('Idle logout remember cleanup failed: ' . $e->getMessage());
    }
    auth_logout();

    $minutes = max(1, (int) round($idleSeconds / 60));
    $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $root = realpath(dirname(__DIR__));
    $relative = $script !== false && $root !== false
        ? ltrim(str_replace('\\', '/', substr($script, strlen($root))), '/')
        : '';
    if (str_starts_with(basename($relative), 'api-') || $relative === 'results/live-feed.php') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'ok' => false,
            'error' => 'auth_required',
            'message' => 'Your session ended after ' . $minutes . ' minutes of inactivity. Sign in again to send queued results.',
        ]);
        exit;
    }
    header('Location: ' . url('portal/login.php') . '?idle=1', true, 302);
    exit;
}

function auth_password_flags_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            db()->query('SELECT must_change_password, password_changed_at FROM users LIMIT 0');
            $ready = true;
        } catch (PDOException $e) {
            $ready = false;
        }
    }

    return $ready;
}

/**
 * An account whose starting password was set by an administrator may only change its password
 * or sign out. JSON endpoints answer 401 so queued offline results stay on the device.
 */
function auth_require_password_change(): void
{
    $user = auth_user();
    if (PHP_SAPI === 'cli' || $user === null || !$user['must_change_password']) {
        return;
    }
    $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $root = realpath(dirname(__DIR__));
    $relative = $script !== false && $root !== false
        ? ltrim(str_replace('\\', '/', substr($script, strlen($root))), '/')
        : '';
    if (in_array($relative, AUTH_PASSWORD_CHANGE_PAGES, true)) {
        return;
    }
    if (str_starts_with(basename($relative), 'api-') || $relative === 'results/live-feed.php') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'error' => 'auth_required', 'message' => 'Change your password before continuing. Sign in again to do so.']);
        exit;
    }
    redirect_to('portal/change-password.php');
}

/**
 * @return array{loaded: bool, user: ?array}
 */
function &auth_user_state(): array
{
    static $state = ['loaded' => false, 'user' => null];

    return $state;
}

function auth_clear_user_cache(): void
{
    $state = &auth_user_state();
    $state['loaded'] = false;
    $state['user'] = null;
}

/**
 * @return array{id: int, username: string, email: string, full_name: string, role: string, last_login_at: ?string, must_change_password: bool}|null
 */
function auth_user(): ?array
{
    $state = &auth_user_state();
    if ($state['loaded']) {
        return $state['user'];
    }

    $state['loaded'] = true;
    $state['user'] = null;

    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId) && !(is_string($userId) && ctype_digit($userId))) {
        return null;
    }

    $userId = (int) $userId;
    if ($userId < 1) {
        unset($_SESSION['user_id']);
        return null;
    }

    $stmt = db()->prepare(
        'SELECT id, username, email, full_name, role, is_active, last_login_at'
        . (auth_password_flags_ready() ? ', must_change_password' : ', 0 AS must_change_password') . '
         FROM users
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();

    if (
        $row === false
        || (int) $row['is_active'] !== 1
        || !isset(AUTH_ROLES[(string) $row['role']])
    ) {
        unset($_SESSION['user_id']);
        return null;
    }

    $state['user'] = [
        'id' => (int) $row['id'],
        'username' => (string) $row['username'],
        'email' => (string) $row['email'],
        'full_name' => (string) $row['full_name'],
        'role' => (string) $row['role'],
        'last_login_at' => $row['last_login_at'] !== null ? (string) $row['last_login_at'] : null,
        'must_change_password' => (int) $row['must_change_password'] === 1,
    ];

    return $state['user'];
}

function auth_dashboard_for(string $role): string
{
    return AUTH_DASHBOARDS[$role] ?? 'portal/login.php';
}

function auth_normalize_login(string $login): ?string
{
    $login = trim($login);
    if ($login === '' || strlen($login) > 255) {
        return null;
    }

    $login = strtolower($login);
    if (str_contains($login, '@')) {
        return filter_var($login, FILTER_VALIDATE_EMAIL) ? $login : null;
    }

    if (!preg_match('/^[a-z0-9._-]{3,64}$/', $login)) {
        return null;
    }

    return $login;
}

function auth_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return '0.0.0.0';
    }

    return $ip;
}

function auth_attempt_count(string $column, string $value): int
{
    if (!in_array($column, ['identifier', 'ip_address'], true)) {
        throw new InvalidArgumentException('Invalid attempt column.');
    }

    $window = (int) AUTH_ATTEMPT_WINDOW_MINUTES;
    $sql = 'SELECT COUNT(*) AS total
            FROM login_attempts
            WHERE ' . $column . ' = :value
              AND attempted_at > (NOW() - INTERVAL ' . $window . ' MINUTE)';
    $stmt = db()->prepare($sql);
    $stmt->execute(['value' => $value]);
    $row = $stmt->fetch();

    return (int) ($row['total'] ?? 0);
}

function auth_record_failure(string $identifier, string $ip): void
{
    $stmt = db()->prepare(
        'INSERT INTO login_attempts (identifier, ip_address, attempted_at)
         VALUES (:identifier, :ip, NOW())'
    );
    $stmt->execute([
        'identifier' => $identifier,
        'ip' => $ip,
    ]);

    $cleanup = db()->prepare(
        'DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)'
    );
    $cleanup->execute();
}

function auth_clear_failures(string $identifier): void
{
    $stmt = db()->prepare('DELETE FROM login_attempts WHERE identifier = :identifier');
    $stmt->execute(['identifier' => $identifier]);
}

function auth_audit(?int $actorId, string $action, int $userId, string $summary): void
{
    $stmt = db()->prepare(
        'INSERT INTO audit_log (actor_user_id, action, entity_type, entity_id, summary)
         VALUES (:actor, :action, :entity, :entity_id, :summary)'
    );
    $stmt->bindValue(':actor', $actorId !== null && $actorId > 0 ? $actorId : null, $actorId !== null && $actorId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindValue(':action', $action, PDO::PARAM_STR);
    $stmt->bindValue(':entity', 'user', PDO::PARAM_STR);
    $stmt->bindValue(':entity_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':summary', substr(str_replace(["\r", "\n"], ' ', $summary), 0, 255), PDO::PARAM_STR);
    $stmt->execute();
}

/**
 * True when this user is the only active super_admin (must not be permanently locked out by guessing).
 */
function auth_is_sole_active_super_admin(int $userId): bool
{
    $stmt = db()->prepare(
        "SELECT role, is_active FROM users WHERE id = :id LIMIT 1"
    );
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();
    if (!is_array($row) || (string) $row['role'] !== 'super_admin' || (int) $row['is_active'] !== 1) {
        return false;
    }
    $others = db()->query(
        "SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND is_active = 1 AND id <> " . (int) $userId
    )->fetchColumn();

    return (int) $others === 0;
}

/**
 * Counts a failed password. Applies a temporary cool-down, then a permanent lock at a high
 * threshold. The sole active super_admin never receives a permanent lock.
 * Returns the account's failure count after this attempt.
 */
function auth_register_account_failure(int $userId, string $username): int
{
    $increment = db()->prepare(
        'UPDATE users SET failed_login_count = LEAST(failed_login_count + 1, 255) WHERE id = :id'
    );
    $increment->execute(['id' => $userId]);

    $countStmt = db()->prepare('SELECT failed_login_count, role FROM users WHERE id = :id');
    $countStmt->execute(['id' => $userId]);
    $info = $countStmt->fetch();
    $failures = (int) ($info['failed_login_count'] ?? 0);
    $hard = (int) AUTH_HARD_LOCK_AFTER;
    $temp = (int) AUTH_TEMP_LOCK_AFTER;
    $soleSuper = auth_is_sole_active_super_admin($userId);

    if ($failures >= $hard && !$soleSuper) {
        // Upgrade any temporary cool-down to a permanent lock (locked_at may already be set).
        $before = db()->prepare('SELECT locked_at, failed_login_count FROM users WHERE id = :id LIMIT 1');
        $before->execute(['id' => $userId]);
        $prior = $before->fetch();
        $lock = db()->prepare('UPDATE users SET locked_at = NOW() WHERE id = :id');
        $lock->execute(['id' => $userId]);
        $wasAlreadyHard = is_array($prior)
            && $prior['locked_at'] !== null
            && (int) $prior['failed_login_count'] >= $hard;
        if (!$wasAlreadyHard) {
            $revoke = db()->prepare('DELETE FROM remember_tokens WHERE user_id = :user_id');
            $revoke->execute(['user_id' => $userId]);
            try {
                auth_audit(null, 'lock_account', $userId, 'Account ' . $username . ' permanently locked after ' . $hard . ' failed sign-in attempts.');
            } catch (PDOException $e) {
                error_log('Lock audit failed: ' . $e->getMessage());
            }
        }
    } elseif ($failures >= $temp) {
        $lock = db()->prepare(
            'UPDATE users SET locked_at = NOW() WHERE id = :id AND (locked_at IS NULL OR locked_at < (NOW() - INTERVAL ' . (int) AUTH_TEMP_LOCK_MINUTES . ' MINUTE))'
        );
        $lock->execute(['id' => $userId]);
        if ($lock->rowCount() === 1) {
            $revoke = db()->prepare('DELETE FROM remember_tokens WHERE user_id = :user_id');
            $revoke->execute(['user_id' => $userId]);
            try {
                auth_audit(null, 'temp_lock_account', $userId, 'Account ' . $username . ' temporarily restricted after ' . $failures . ' failed sign-in attempts.');
            } catch (PDOException $e) {
                error_log('Temp lock audit failed: ' . $e->getMessage());
            }
        }
    }

    return $failures;
}

/**
 * @return array{kind: 'none'|'temporary'|'permanent', minutes_left: int}
 */
function auth_lock_state(array $row): array
{
    if ($row['locked_at'] === null) {
        return ['kind' => 'none', 'minutes_left' => 0];
    }
    $failures = (int) ($row['failed_login_count'] ?? 0);
    $hard = (int) AUTH_HARD_LOCK_AFTER;
    $soleSuper = ((string) ($row['role'] ?? '') === 'super_admin') && auth_is_sole_active_super_admin((int) $row['id']);

    if ($failures >= $hard && !$soleSuper) {
        return ['kind' => 'permanent', 'minutes_left' => 0];
    }

    // Compare lock age in MySQL time so PHP/DB timezone skew cannot expire a cool-down early.
    $stmt = db()->prepare(
        'SELECT
            (locked_at < (NOW() - INTERVAL ' . (int) AUTH_TEMP_LOCK_MINUTES . ' MINUTE)) AS expired,
            GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(locked_at, INTERVAL ' . (int) AUTH_TEMP_LOCK_MINUTES . ' MINUTE))) AS seconds_left
         FROM users WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => (int) $row['id']]);
    $timing = $stmt->fetch();
    if (!is_array($timing)) {
        return ['kind' => 'permanent', 'minutes_left' => 0];
    }
    if ((int) $timing['expired'] === 1) {
        return ['kind' => 'none', 'minutes_left' => 0];
    }

    return ['kind' => 'temporary', 'minutes_left' => max(1, (int) ceil(((int) $timing['seconds_left']) / 60))];
}

function auth_clear_expired_temp_lock(int $userId): void
{
    $clear = db()->prepare(
        'UPDATE users SET locked_at = NULL
         WHERE id = :id AND locked_at IS NOT NULL AND failed_login_count < ' . (int) AUTH_HARD_LOCK_AFTER . '
           AND locked_at < (NOW() - INTERVAL ' . (int) AUTH_TEMP_LOCK_MINUTES . ' MINUTE)'
    );
    $clear->execute(['id' => $userId]);
}

/**
 * Clears the lock and failure counters for an account.
 * Returns false when the account was not locked.
 */
function auth_unlock_account(int $userId, ?int $actorId): bool
{
    $find = db()->prepare('SELECT username, email FROM users WHERE id = :id AND locked_at IS NOT NULL LIMIT 1');
    $find->execute(['id' => $userId]);
    $row = $find->fetch();
    if (!is_array($row)) {
        return false;
    }

    $unlock = db()->prepare(
        'UPDATE users SET locked_at = NULL, failed_login_count = 0 WHERE id = :id AND locked_at IS NOT NULL'
    );
    $unlock->execute(['id' => $userId]);
    if ($unlock->rowCount() !== 1) {
        return false;
    }
    auth_clear_failures(strtolower((string) $row['username']));
    auth_clear_failures(strtolower((string) $row['email']));
    auth_audit($actorId, 'unlock_account', $userId, 'Account ' . $row['username'] . ' unlocked.');

    return true;
}

/**
 * @return array{ok: true, role: string}|array{ok: false, error: string}
 */
function auth_attempt(string $login, string $password, bool $remember): array
{
    $ip = auth_client_ip();
    $normalized = auth_normalize_login($login);
    $bucket = $normalized ?? ('invalid:' . hash('sha256', trim($login)));

    if (
        auth_attempt_count('ip_address', $ip) >= AUTH_MAX_IP_ATTEMPTS
        || auth_attempt_count('identifier', $bucket) >= AUTH_MAX_IDENTIFIER_ATTEMPTS
    ) {
        return [
            'ok' => false,
            'error' => AUTH_THROTTLED_MESSAGE,
        ];
    }

    if ($normalized === null || $password === '' || strlen($password) > 128) {
        auth_record_failure($bucket, $ip);
        return [
            'ok' => false,
            'error' => AUTH_GENERIC_FAILURE,
        ];
    }

    $stmt = db()->prepare(
        'SELECT id, username, password_hash, role, is_active, failed_login_count, locked_at
         FROM users
         WHERE username = :username OR email = :email
         LIMIT 1'
    );
    $stmt->execute([
        'username' => $normalized,
        'email' => $normalized,
    ]);
    $row = $stmt->fetch();

    $hash = is_array($row) ? (string) $row['password_hash'] : AUTH_DUMMY_HASH;
    $passwordMatches = password_verify($password, $hash);
    $accountActive = is_array($row)
        && (int) $row['is_active'] === 1
        && isset(AUTH_ROLES[(string) $row['role']]);

    if (is_array($row) && $row['locked_at'] !== null) {
        $lockState = auth_lock_state($row);
        if ($lockState['kind'] === 'none') {
            auth_clear_expired_temp_lock((int) $row['id']);
            $row['locked_at'] = null;
        } elseif ($lockState['kind'] === 'permanent') {
            auth_record_failure($bucket, $ip);

            return ['ok' => false, 'error' => AUTH_THROTTLED_MESSAGE];
        } elseif ($lockState['kind'] === 'temporary') {
            // Still count failures so a persistent attacker can escalate to a permanent lock.
            auth_record_failure($bucket, $ip);
            if (!$passwordMatches) {
                auth_register_account_failure((int) $row['id'], (string) $row['username']);
            }

            return ['ok' => false, 'error' => AUTH_THROTTLED_MESSAGE];
        }
    }

    if (!is_array($row) || !$passwordMatches || !$accountActive) {
        auth_record_failure($bucket, $ip);
        if (is_array($row)) {
            auth_register_account_failure((int) $row['id'], (string) $row['username']);
        }

        return [
            'ok' => false,
            'error' => AUTH_GENERIC_FAILURE,
        ];
    }

    $userId = (int) $row['id'];
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $rehash = db()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $rehash->execute([
            'hash' => password_hash($password, PASSWORD_DEFAULT),
            'id' => $userId,
        ]);
    }

    $touch = db()->prepare('UPDATE users SET last_login_at = NOW(), failed_login_count = 0 WHERE id = :id');
    $touch->execute(['id' => $userId]);
    auth_clear_failures($bucket);
    auth_establish($userId, $remember);

    return [
        'ok' => true,
        'role' => (string) $row['role'],
    ];
}

/**
 * The signed-in account holder replaces their password. Other remembered devices are signed out.
 *
 * @return array{ok: true}|array{ok: false, field: string, error: string}
 */
function auth_change_password(int $userId, string $current, string $new, string $confirm): array
{
    $bucket = 'pwchange:' . $userId;
    $ip = auth_client_ip();
    if (auth_attempt_count('identifier', $bucket) >= AUTH_MAX_IDENTIFIER_ATTEMPTS) {
        return ['ok' => false, 'field' => 'form', 'error' => 'Too many incorrect attempts. Please wait 15 minutes and try again.'];
    }
    $find = db()->prepare('SELECT password_hash, username FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
    $find->execute(['id' => $userId]);
    $row = $find->fetch();
    if (!is_array($row) || strlen($current) > 128 || !password_verify($current, (string) $row['password_hash'])) {
        auth_record_failure($bucket, $ip);

        return ['ok' => false, 'field' => 'current_password', 'error' => 'Your current password is not correct.'];
    }
    if (strlen($new) < 12 || strlen($new) > 128) {
        return ['ok' => false, 'field' => 'new_password', 'error' => 'Password must be 12 to 128 characters.'];
    }
    if (!hash_equals($new, $confirm)) {
        return ['ok' => false, 'field' => 'new_password_confirm', 'error' => 'The passwords do not match.'];
    }
    if (password_verify($new, (string) $row['password_hash'])) {
        return ['ok' => false, 'field' => 'new_password', 'error' => 'Choose a password that is different from your current one.'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare(
            'UPDATE users SET password_hash = :hash'
            . (auth_password_flags_ready() ? ', must_change_password = 0, password_changed_at = NOW()' : '')
            . ' WHERE id = :id'
        );
        $update->execute(['hash' => password_hash($new, PASSWORD_DEFAULT), 'id' => $userId]);
        $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = :id')->execute(['id' => $userId]);
        auth_audit($userId, 'change_password', $userId, 'Password changed by ' . $row['username'] . '.');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    auth_clear_failures($bucket);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
        if (function_exists('csrf_rotate')) {
            csrf_rotate();
        }
    }
    auth_clear_remember_cookie();
    auth_clear_user_cache();

    return ['ok' => true];
}

function auth_establish(int $userId, bool $remember): void
{
    app_start_session();
    session_regenerate_id(true);
    $_SESSION = [
        'user_id' => $userId,
        'last_activity' => time(),
    ];
    if (function_exists('csrf_rotate')) {
        csrf_rotate();
    }
    auth_clear_user_cache();

    if ($remember) {
        auth_issue_remember($userId);
    }
}

function auth_remember_days_for(int $userId): int
{
    $stmt = db()->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $role = (string) $stmt->fetchColumn();

    return $role === 'polling_agent' ? (int) AUTH_AGENT_REMEMBER_DAYS : (int) AUTH_REMEMBER_DAYS;
}

function auth_issue_remember(int $userId): void
{
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $days = auth_remember_days_for($userId);

    $stmt = db()->prepare(
        'INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at)
         VALUES (:user_id, :selector, :token_hash, DATE_ADD(NOW(), INTERVAL ' . $days . ' DAY))'
    );
    $stmt->execute([
        'user_id' => $userId,
        'selector' => $selector,
        'token_hash' => hash('sha256', $validator),
    ]);

    app_set_cookie(
        AUTH_REMEMBER_COOKIE,
        $selector . '.' . $validator,
        time() + (86400 * $days)
    );
}

/**
 * @return array{selector: string, validator: string}|null
 */
function auth_parse_remember(?string $raw): ?array
{
    if ($raw === null || !str_contains($raw, '.')) {
        return null;
    }

    [$selector, $validator] = explode('.', $raw, 2);
    if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
        return null;
    }

    return [
        'selector' => $selector,
        'validator' => $validator,
    ];
}

function auth_clear_remember_cookie(): void
{
    if (isset($_COOKIE[AUTH_REMEMBER_COOKIE]) || PHP_SAPI !== 'cli') {
        app_expire_cookie(AUTH_REMEMBER_COOKIE);
    }
}

function auth_restore_remember(): void
{
    $raw = isset($_COOKIE[AUTH_REMEMBER_COOKIE]) ? (string) $_COOKIE[AUTH_REMEMBER_COOKIE] : null;
    $parsed = auth_parse_remember($raw);
    if ($parsed === null) {
        if ($raw !== null) {
            auth_clear_remember_cookie();
        }
        return;
    }

    $stmt = db()->prepare(
        'SELECT rt.user_id, rt.token_hash, u.is_active, u.role, u.locked_at,
                (rt.expires_at <= NOW()) AS is_expired
         FROM remember_tokens rt
         INNER JOIN users u ON u.id = rt.user_id
         WHERE rt.selector = :selector
         LIMIT 1'
    );
    $stmt->execute(['selector' => $parsed['selector']]);
    $row = $stmt->fetch();

    if ($row === false) {
        auth_clear_remember_cookie();
        return;
    }

    $userId = (int) $row['user_id'];
    if (!hash_equals((string) $row['token_hash'], hash('sha256', $parsed['validator']))) {
        $revoke = db()->prepare('DELETE FROM remember_tokens WHERE user_id = :user_id');
        $revoke->execute(['user_id' => $userId]);
        auth_clear_remember_cookie();
        return;
    }

    if (
        (int) $row['is_expired'] === 1
        || $row['locked_at'] !== null
        || (int) $row['is_active'] !== 1
        || !isset(AUTH_ROLES[(string) $row['role']])
    ) {
        $revoke = db()->prepare('DELETE FROM remember_tokens WHERE user_id = :user_id');
        $revoke->execute(['user_id' => $userId]);
        auth_clear_remember_cookie();
        return;
    }

    $delete = db()->prepare('DELETE FROM remember_tokens WHERE selector = :selector');
    $delete->execute(['selector' => $parsed['selector']]);
    auth_establish($userId, true);
}

function auth_logout(): void
{
    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    // Voluntary sign-out ends remember-me on every device for this account.
    if ($userId > 0) {
        try {
            db()->prepare('DELETE FROM remember_tokens WHERE user_id = :user_id')->execute(['user_id' => $userId]);
        } catch (Throwable $e) {
            error_log('Remember token cleanup failed: ' . $e->getMessage());
        }
    } else {
        $raw = isset($_COOKIE[AUTH_REMEMBER_COOKIE]) ? (string) $_COOKIE[AUTH_REMEMBER_COOKIE] : null;
        $parsed = auth_parse_remember($raw);
        if ($parsed !== null) {
            try {
                $stmt = db()->prepare('DELETE FROM remember_tokens WHERE selector = :selector');
                $stmt->execute(['selector' => $parsed['selector']]);
            } catch (Throwable $e) {
                error_log('Remember token cleanup failed: ' . $e->getMessage());
            }
        }
    }

    auth_clear_remember_cookie();
    auth_clear_user_cache();
    $_SESSION = [];
    app_expire_cookie(session_name());

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function auth_password_setup_schema_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            db()->query('SELECT selector, token_hash, purpose, expires_at, used_at FROM password_setup_tokens LIMIT 0');
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
    }

    return $ready;
}

/**
 * Issues a single-use, time-limited password-setup link for an account.
 * Stores only a hash of the secret. Previous unused tokens for the same purpose are invalidated.
 * Returns null when the schema is missing or the account is not eligible.
 */
function auth_issue_password_setup_link(int $userId, string $purpose = AUTH_SETUP_PURPOSE): ?string
{
    if (!auth_password_setup_schema_ready() || $purpose !== AUTH_SETUP_PURPOSE) {
        return null;
    }
    $find = db()->prepare(
        "SELECT id, role, is_active, password_changed_at FROM users WHERE id = :id LIMIT 1"
    );
    $find->execute(['id' => $userId]);
    $row = $find->fetch();
    if (
        !is_array($row)
        || (string) $row['role'] !== 'polling_agent'
        || (int) $row['is_active'] !== 1
        || $row['password_changed_at'] !== null
    ) {
        return null;
    }

    $base = null;
    if (function_exists('mailer_app_url')) {
        $base = mailer_app_url();
    }
    if ($base === null) {
        $candidate = rtrim((string) (app_config()['app_url'] ?? ''), '/');
        if ($candidate !== '' && (str_starts_with($candidate, 'https://') || preg_match('#\Ahttps?://(localhost|127\.0\.0\.1)(/|\z)#i', $candidate) === 1)) {
            $base = $candidate;
        }
    }
    if ($base === null) {
        return null;
    }

    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $hours = (int) AUTH_SETUP_TOKEN_HOURS;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE password_setup_tokens SET used_at = NOW()
             WHERE user_id = :user_id AND purpose = :purpose AND used_at IS NULL'
        )->execute(['user_id' => $userId, 'purpose' => $purpose]);
        $insert = $pdo->prepare(
            'INSERT INTO password_setup_tokens (user_id, purpose, selector, token_hash, expires_at)
             VALUES (:user_id, :purpose, :selector, :token_hash, DATE_ADD(NOW(), INTERVAL ' . $hours . ' HOUR))'
        );
        $insert->execute([
            'user_id' => $userId,
            'purpose' => $purpose,
            'selector' => $selector,
            'token_hash' => hash('sha256', $validator),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $base . '/portal/setup-password?t=' . rawurlencode($selector . '.' . $validator);
}

/**
 * @return array{ok: true, user_id: int, username: string, full_name: string}|array{ok: false, error: string}
 */
function auth_peek_password_setup_token(string $rawToken): array
{
    if (!auth_password_setup_schema_ready()) {
        return ['ok' => false, 'error' => 'Password setup is unavailable until the migration is applied.'];
    }
    $parsed = auth_parse_setup_token($rawToken);
    if ($parsed === null) {
        return ['ok' => false, 'error' => 'This setup link is invalid or has expired.'];
    }
    $ip = auth_client_ip();
    $bucket = 'pwsetup:' . $parsed['selector'];
    if (
        auth_attempt_count('ip_address', $ip) >= AUTH_MAX_IP_ATTEMPTS
        || auth_attempt_count('identifier', $bucket) >= AUTH_MAX_SETUP_ATTEMPTS
    ) {
        return ['ok' => false, 'error' => 'Too many attempts. Wait 15 minutes and try again, or ask an administrator to resend the setup email.'];
    }

    $stmt = db()->prepare(
        'SELECT t.user_id, t.token_hash, t.purpose, t.used_at, t.expires_at,
                u.username, u.full_name, u.role, u.is_active, u.password_changed_at, u.locked_at
         FROM password_setup_tokens t
         INNER JOIN users u ON u.id = t.user_id
         WHERE t.selector = :selector
         LIMIT 1'
    );
    $stmt->execute(['selector' => $parsed['selector']]);
    $row = $stmt->fetch();
    if (!is_array($row) || !hash_equals((string) $row['token_hash'], hash('sha256', $parsed['validator']))) {
        auth_record_failure($bucket, $ip);

        return ['ok' => false, 'error' => 'This setup link is invalid or has expired.'];
    }
    if (
        (string) $row['purpose'] !== AUTH_SETUP_PURPOSE
        || $row['used_at'] !== null
        || strtotime((string) $row['expires_at']) < time()
        || (string) $row['role'] !== 'polling_agent'
        || (int) $row['is_active'] !== 1
        || $row['password_changed_at'] !== null
        || $row['locked_at'] !== null
    ) {
        auth_record_failure($bucket, $ip);

        return ['ok' => false, 'error' => 'This setup link is invalid or has expired.'];
    }

    return [
        'ok' => true,
        'user_id' => (int) $row['user_id'],
        'username' => (string) $row['username'],
        'full_name' => (string) $row['full_name'],
    ];
}

/**
 * Completes password setup. Does not change role or polling-unit assignment.
 *
 * @return array{ok: true}|array{ok: false, field: string, error: string}
 */
function auth_complete_password_setup(string $rawToken, string $new, string $confirm): array
{
    $peek = auth_peek_password_setup_token($rawToken);
    if ($peek['ok'] !== true) {
        return ['ok' => false, 'field' => 'form', 'error' => $peek['error']];
    }
    if (strlen($new) < 12 || strlen($new) > 128) {
        return ['ok' => false, 'field' => 'new_password', 'error' => 'Password must be 12 to 128 characters.'];
    }
    if (!hash_equals($new, $confirm)) {
        return ['ok' => false, 'field' => 'new_password_confirm', 'error' => 'The passwords do not match.'];
    }

    $parsed = auth_parse_setup_token($rawToken);
    if ($parsed === null) {
        return ['ok' => false, 'field' => 'form', 'error' => 'This setup link is invalid or has expired.'];
    }

    $userId = $peek['user_id'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare(
            'UPDATE password_setup_tokens SET used_at = NOW()
             WHERE selector = :selector AND used_at IS NULL AND expires_at > NOW() AND purpose = :purpose'
        );
        $claim->execute(['selector' => $parsed['selector'], 'purpose' => AUTH_SETUP_PURPOSE]);
        if ($claim->rowCount() !== 1) {
            $pdo->rollBack();

            return ['ok' => false, 'field' => 'form', 'error' => 'This setup link is invalid or has expired.'];
        }
        $update = $pdo->prepare(
            'UPDATE users SET password_hash = :hash, must_change_password = 0, password_changed_at = NOW(), failed_login_count = 0
             WHERE id = :id AND role = \'polling_agent\' AND is_active = 1 AND password_changed_at IS NULL'
        );
        $update->execute(['hash' => password_hash($new, PASSWORD_DEFAULT), 'id' => $userId]);
        if ($update->rowCount() !== 1) {
            $pdo->rollBack();

            return ['ok' => false, 'field' => 'form', 'error' => 'This setup link is invalid or has expired.'];
        }
        $pdo->prepare(
            'UPDATE password_setup_tokens SET used_at = COALESCE(used_at, NOW())
             WHERE user_id = :user_id AND purpose = :purpose AND used_at IS NULL'
        )->execute(['user_id' => $userId, 'purpose' => AUTH_SETUP_PURPOSE]);
        $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = :id')->execute(['id' => $userId]);
        auth_audit($userId, 'password_setup', $userId, 'Password set via single-use setup link for ' . $peek['username'] . '.');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    auth_clear_failures('pwsetup:' . $parsed['selector']);

    return ['ok' => true];
}

/**
 * @return array{selector: string, validator: string}|null
 */
function auth_parse_setup_token(?string $raw): ?array
{
    if ($raw === null || !str_contains($raw, '.')) {
        return null;
    }
    [$selector, $validator] = explode('.', $raw, 2);
    if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
        return null;
    }

    return ['selector' => $selector, 'validator' => $validator];
}
