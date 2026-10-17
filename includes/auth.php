<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

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
const AUTH_MAX_IDENTIFIER_ATTEMPTS = 8;
const AUTH_MAX_IP_ATTEMPTS = 30;
const AUTH_ATTEMPT_WINDOW_MINUTES = 15;
const AUTH_LOCK_AFTER_FAILURES = 2;
const AUTH_LOCKED_MESSAGE = 'This account has been locked after repeated failed sign-in attempts. Contact an administrator to unlock it.';
const AUTH_DUMMY_HASH = '$2y$10$uk4a92e/djZ5sUKiU/yRuuLAl4MDGH4GHk.fPgrUMS.Whbc0ZsxnG';

const AUTH_PASSWORD_CHANGE_PAGES = ['portal/change-password.php', 'portal/logout.php', 'portal/login.php'];
const AUTH_ADMIN_IDLE_SECONDS = 600;
const AUTH_IDLE_ROLES = ['super_admin', 'admin'];

function auth_boot(): void
{
    app_start_session();
    if (auth_user() === null) {
        auth_restore_remember();
    }
    auth_enforce_admin_idle();
    auth_require_password_change();
}

function auth_is_idle_role(string $role): bool
{
    return in_array($role, AUTH_IDLE_ROLES, true);
}

/**
 * Administrators and super administrators are signed out after AUTH_ADMIN_IDLE_SECONDS
 * without a request. Remember-me tokens for that account are revoked so the session
 * cannot silently return.
 */
function auth_enforce_admin_idle(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $user = auth_user();
    if ($user === null || !auth_is_idle_role($user['role'])) {
        return;
    }

    $now = time();
    $last = $_SESSION['last_activity'] ?? null;
    if (is_int($last) && $last > 0 && ($now - $last) >= AUTH_ADMIN_IDLE_SECONDS) {
        auth_end_idle_session((int) $user['id']);
        return;
    }
    $_SESSION['last_activity'] = $now;
}

function auth_end_idle_session(int $userId): void
{
    try {
        $revoke = db()->prepare('DELETE FROM remember_tokens WHERE user_id = :user_id');
        $revoke->execute(['user_id' => $userId]);
    } catch (Throwable $e) {
        error_log('Idle logout remember cleanup failed: ' . $e->getMessage());
    }
    auth_logout();

    $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $root = realpath(dirname(__DIR__));
    $relative = $script !== false && $root !== false
        ? ltrim(str_replace('\\', '/', substr($script, strlen($root))), '/')
        : '';
    if (str_starts_with(basename($relative), 'api-') || $relative === 'results/live-feed.php') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'error' => 'auth_required', 'message' => 'Your session ended after 10 minutes of inactivity. Sign in again.']);
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
 * Counts a failed password for an existing account and locks it at the limit.
 * Returns the account's failure count after this attempt.
 */
function auth_register_account_failure(int $userId, string $username): int
{
    $limit = (int) AUTH_LOCK_AFTER_FAILURES;
    $increment = db()->prepare(
        'UPDATE users SET failed_login_count = LEAST(failed_login_count + 1, 255) WHERE id = :id'
    );
    $increment->execute(['id' => $userId]);

    $lock = db()->prepare(
        'UPDATE users SET locked_at = NOW()
         WHERE id = :id AND locked_at IS NULL AND failed_login_count >= ' . $limit
    );
    $lock->execute(['id' => $userId]);
    if ($lock->rowCount() === 1) {
        $revoke = db()->prepare('DELETE FROM remember_tokens WHERE user_id = :user_id');
        $revoke->execute(['user_id' => $userId]);
        try {
            auth_audit(null, 'lock_account', $userId, 'Account ' . $username . ' locked after ' . $limit . ' failed sign-in attempts.');
        } catch (PDOException $e) {
            error_log('Lock audit failed: ' . $e->getMessage());
        }
    }

    $count = db()->prepare('SELECT failed_login_count FROM users WHERE id = :id');
    $count->execute(['id' => $userId]);

    return (int) $count->fetchColumn();
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
            'error' => 'Too many sign-in attempts. Please wait 15 minutes and try again.',
        ];
    }

    if ($normalized === null || $password === '' || strlen($password) > 128) {
        auth_record_failure($bucket, $ip);
        return [
            'ok' => false,
            'error' => 'Enter a valid username or email and your password.',
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
        auth_record_failure($bucket, $ip);
        return ['ok' => false, 'error' => AUTH_LOCKED_MESSAGE];
    }

    if (!is_array($row) || !$passwordMatches || !$accountActive) {
        auth_record_failure($bucket, $ip);
        $failures = is_array($row)
            ? auth_register_account_failure((int) $row['id'], (string) $row['username'])
            : auth_attempt_count('identifier', $bucket);
        if ($failures >= AUTH_LOCK_AFTER_FAILURES) {
            return ['ok' => false, 'error' => AUTH_LOCKED_MESSAGE];
        }
        $left = AUTH_LOCK_AFTER_FAILURES - $failures;

        return [
            'ok' => false,
            'error' => 'Invalid username or password. ' . $left . ($left === 1 ? ' attempt' : ' attempts') . ' left before this account is locked.',
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
    auth_clear_user_cache();

    if ($remember) {
        auth_issue_remember($userId);
    }
}

function auth_issue_remember(int $userId): void
{
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $days = (int) AUTH_REMEMBER_DAYS;

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

    auth_clear_remember_cookie();
    auth_clear_user_cache();
    $_SESSION = [];
    app_expire_cookie(session_name());

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}
