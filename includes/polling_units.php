<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/dashboard_view.php';

const PU_MANAGERS = ['super_admin', 'admin'];
const PU_CLASSIFICATIONS = ['EXISTING PU', 'NEW PU'];
/** @deprecated Use PU_ALLOWED_LGAS; kept for older call sites that expect the Ohaji default. */
const PU_LGA = 'Ohaji/Egbema';
const PU_ALLOWED_LGAS = ['Ohaji/Egbema', 'Oguta', 'Oru West'];
const PU_CONSTITUENCY_LABEL = 'Ohaji/Egbema, Oguta and Oru West Federal Constituency';
const PU_PAGE_SIZE = 20;
const PU_IMPORT_MAX_BYTES = 2097152;
const PU_IMPORT_MAX_ROWS = 1000;
const PU_REGISTER_NOTE = 'Counts on this page come from the polling units saved in the database. This register has not been checked against the current INEC register.';

/**
 * Expected unit counts per ward (validation targets, not an INEC verification).
 * Held back until codes/names are complete and verified:
 * - EGWE/EGBUOMA (16-16-10)
 * - NEMPI/ELEM rows labelled 16-23-09-021 and 16-23-09-022 (wrong LGA/ward prefix)
 */
const PU_WARD_TARGETS = [
    // Ohaji/Egbema
    'ASSA/OBILE' => 16,
    'AWARA/IKWEREDE' => 20,
    'EGBEMA A' => 27,
    'EGBEMA B' => 12,
    'EGBEMA C' => 12,
    'EGBEMA D' => 8,
    'EGBEMA E' => 11,
    'EKWUATO' => 22,
    'OBITTI/MGBISHI' => 18,
    'OHOBA' => 27,
    'UMUAGWO' => 24,
    'UMUAPU' => 13,
    // Oguta
    'AWA' => 22,
    'IZOMBE' => 20,
    'MBALA/UBA' => 12,
    'NDEULOUKWU/UMUOWERE' => 7,
    'OBUDI/ARO' => 16,
    'OGUTA A' => 21,
    'OGUTA B' => 13,
    'ORU' => 25,
    'OSSEMOTOR/ENUIGBO' => 9,
    'UWAORIE' => 19,
    // Oru West (NEMPI/ELEM = 19 verified 16-24-01 codes; claimed 22)
    'NEMPI/ELEM' => 19,
    'OTULU' => 13,
    'AJI' => 7,
    'IBIASO EGBE' => 12,
    'AMAFUO' => 5,
    'OHAKPU' => 7,
    'OZARA' => 9,
    'UBULU' => 24,
    'MGBIDI I' => 21,
    'MGBIDI II' => 30,
];

/** @var array<string, string> ward canonical name => LGA display name */
const PU_WARD_LGA = [
    'ASSA/OBILE' => 'Ohaji/Egbema',
    'AWARA/IKWEREDE' => 'Ohaji/Egbema',
    'EGBEMA A' => 'Ohaji/Egbema',
    'EGBEMA B' => 'Ohaji/Egbema',
    'EGBEMA C' => 'Ohaji/Egbema',
    'EGBEMA D' => 'Ohaji/Egbema',
    'EGBEMA E' => 'Ohaji/Egbema',
    'EKWUATO' => 'Ohaji/Egbema',
    'OBITTI/MGBISHI' => 'Ohaji/Egbema',
    'OHOBA' => 'Ohaji/Egbema',
    'UMUAGWO' => 'Ohaji/Egbema',
    'UMUAPU' => 'Ohaji/Egbema',
    'AWA' => 'Oguta',
    'IZOMBE' => 'Oguta',
    'MBALA/UBA' => 'Oguta',
    'NDEULOUKWU/UMUOWERE' => 'Oguta',
    'OBUDI/ARO' => 'Oguta',
    'OGUTA A' => 'Oguta',
    'OGUTA B' => 'Oguta',
    'ORU' => 'Oguta',
    'OSSEMOTOR/ENUIGBO' => 'Oguta',
    'UWAORIE' => 'Oguta',
    'NEMPI/ELEM' => 'Oru West',
    'OTULU' => 'Oru West',
    'AJI' => 'Oru West',
    'IBIASO EGBE' => 'Oru West',
    'AMAFUO' => 'Oru West',
    'OHAKPU' => 'Oru West',
    'OZARA' => 'Oru West',
    'UBULU' => 'Oru West',
    'MGBIDI I' => 'Oru West',
    'MGBIDI II' => 'Oru West',
];

/**
 * @return array{id: int, username: string, email: string, full_name: string, role: string, last_login_at?: ?string}
 */
function pu_require_manager(): array
{
    $user = auth_user();
    if ($user === null) {
        redirect_to('portal/login.php');
    }
    if (!in_array($user['role'], PU_MANAGERS, true)) {
        render_forbidden($user);
        exit;
    }

    return $user;
}

function pu_schema_ready(): bool
{
    try {
        $pdo = db();
        $pdo->query('SELECT 1 FROM wards LIMIT 1');
        $pdo->query('SELECT 1 FROM polling_units LIMIT 1');
        $pdo->query('SELECT 1 FROM audit_log LIMIT 1');

        return true;
    } catch (Throwable $exception) {
        error_log($exception->getMessage());

        return false;
    }
}

function pu_redirect(string $path): never
{
    if (!preg_match('#\Aadmin/polling-units?\.php(?:\?[a-z0-9_=&%+.-]*)?\z#', $path)) {
        $path = 'admin/polling-units.php';
    }

    header('Location: ' . url($path), true, 302);
    exit;
}

function pu_flash(string $kind, string $message): void
{
    if (!in_array($kind, ['success', 'error', 'warn'], true)) {
        $kind = 'success';
    }
    $_SESSION['pu_flash'] = ['kind' => $kind, 'message' => $message];
}

/**
 * @return array{kind: string, message: string}|null
 */
function pu_take_flash(): ?array
{
    $flash = $_SESSION['pu_flash'] ?? null;
    unset($_SESSION['pu_flash']);
    if (!is_array($flash)) {
        return null;
    }
    $kind = $flash['kind'] ?? '';
    $message = $flash['message'] ?? '';
    if (!is_string($kind) || !is_string($message) || $message === '') {
        return null;
    }
    if (!in_array($kind, ['success', 'error', 'warn'], true)) {
        return null;
    }

    return ['kind' => $kind, 'message' => $message];
}

function pu_canonical_ward(string $name): ?string
{
    $key = strtoupper(trim($name));
    $key = preg_replace('/\s+/', ' ', $key) ?? '';
    $key = str_replace([' / ', '/ ', ' /'], '/', $key);
    $key = preg_replace("/['’]/u", '', $key) ?? $key;
    $key = preg_replace('/\s+WARD\z/', '', $key) ?? $key;

    return array_key_exists($key, PU_WARD_TARGETS) ? $key : null;
}

function pu_canonical_lga(string $name): ?string
{
    $key = strtolower(trim($name));
    $key = preg_replace('/\s+/', ' ', $key) ?? '';
    $key = str_replace([' / ', '/ ', ' /'], '/', $key);
    foreach (PU_ALLOWED_LGAS as $allowed) {
        if ($key === strtolower($allowed)) {
            return $allowed;
        }
    }

    return null;
}

function pu_lga_for_ward_key(string $wardKey): ?string
{
    return PU_WARD_LGA[$wardKey] ?? null;
}

function pu_lga_for_ward_id(int $wardId): ?string
{
    if ($wardId < 1) {
        return null;
    }
    foreach (pu_wards() as $ward) {
        if ($ward['id'] === $wardId) {
            $key = pu_canonical_ward($ward['name']);

            return $key !== null ? pu_lga_for_ward_key($key) : null;
        }
    }

    return null;
}

function pu_canonical_classification(string $value): ?string
{
    $key = strtoupper(trim($value));
    $key = preg_replace('/\s+/', ' ', $key) ?? '';

    return in_array($key, PU_CLASSIFICATIONS, true) ? $key : null;
}

function pu_normalize_code(string $code): ?string
{
    $code = trim($code);
    if ($code === '' || strlen($code) > 32) {
        return null;
    }
    if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9.\/_-]*\z/', $code)) {
        return null;
    }

    return $code;
}

function pu_normalize_name(string $name): ?string
{
    $name = trim($name);
    $length = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
    if ($name === '' || $length > 150) {
        return null;
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $name) || preg_match('/\A[=+@]/', $name)) {
        return null;
    }

    return $name;
}

/**
 * @return list<array{id: int, name: string, expected_units: int}>
 */
/**
 * @return list<array{id: int, name: string, units: int}>
 */
function pu_ward_counts(): array
{
    $rows = db()->query(
        'SELECT w.id, w.name, COUNT(pu.id) AS units
         FROM wards w
         LEFT JOIN polling_units pu ON pu.ward_id = w.id
         GROUP BY w.id, w.name
         ORDER BY w.name'
    )->fetchAll();
    $wards = [];
    foreach ($rows as $row) {
        $wards[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'units' => (int) $row['units'],
        ];
    }

    return $wards;
}

function pu_wards(): array
{
    $rows = db()->query('SELECT id, name, expected_units FROM wards ORDER BY name')->fetchAll();
    $wards = [];
    foreach ($rows as $row) {
        $wards[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'expected_units' => (int) $row['expected_units'],
        ];
    }

    return $wards;
}

/**
 * @return array<string, array{id: int, name: string, expected_units: int}>
 */
function pu_ward_map(): array
{
    $map = [];
    foreach (pu_wards() as $ward) {
        $key = pu_canonical_ward($ward['name']);
        if ($key !== null) {
            $map[$key] = $ward;
        }
    }

    return $map;
}

/**
 * @return array{
 *     total_units: int,
 *     total_wards: int,
 *     existing_units: int,
 *     new_units: int,
 *     assigned_units: int,
 *     unassigned_units: int,
 *     inactive_units: int
 * }
 */
function pu_summary(): array
{
    $row = db()->query(
        "SELECT
            COUNT(*) AS total_units,
            COALESCE(SUM(classification = 'EXISTING PU'), 0) AS existing_units,
            COALESCE(SUM(classification = 'NEW PU'), 0) AS new_units,
            COALESCE(SUM(agent_user_id IS NOT NULL), 0) AS assigned_units,
            COALESCE(SUM(agent_user_id IS NULL), 0) AS unassigned_units,
            COALESCE(SUM(is_active = 0), 0) AS inactive_units
         FROM polling_units"
    )->fetch();
    if (!is_array($row)) {
        $row = [];
    }

    return [
        'total_units' => (int) ($row['total_units'] ?? 0),
        'total_wards' => count(pu_wards()),
        'existing_units' => (int) ($row['existing_units'] ?? 0),
        'new_units' => (int) ($row['new_units'] ?? 0),
        'assigned_units' => (int) ($row['assigned_units'] ?? 0),
        'unassigned_units' => (int) ($row['unassigned_units'] ?? 0),
        'inactive_units' => (int) ($row['inactive_units'] ?? 0),
    ];
}

/**
 * @return list<array{ward: string, expected: int, actual: int, gap: int}>
 */
function pu_discrepancies(array $extraByWard = []): array
{
    $statement = db()->query(
        'SELECT w.name, w.expected_units, COUNT(pu.id) AS actual_units
         FROM wards w
         LEFT JOIN polling_units pu ON pu.ward_id = w.id
         GROUP BY w.id, w.name, w.expected_units
         ORDER BY w.name'
    );
    $rows = [];
    foreach ($statement as $row) {
        $name = (string) $row['name'];
        $actual = (int) $row['actual_units'] + (int) ($extraByWard[$name] ?? 0);
        $expected = (int) $row['expected_units'];
        $rows[] = [
            'ward' => $name,
            'expected' => $expected,
            'actual' => $actual,
            'gap' => $expected - $actual,
        ];
    }

    return $rows;
}

function pu_counts_match(array $rows): bool
{
    if ($rows === []) {
        return false;
    }
    foreach ($rows as $row) {
        if ((int) ($row['gap'] ?? 1) !== 0) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string, mixed> $query
 * @return array{q: string, ward: int, classification: string, assignment: string, page: int}
 */
function pu_filters_from_query(array $query): array
{
    $q = trim((string) ($query['q'] ?? ''));
    if (strlen($q) > 100) {
        $q = substr($q, 0, 100);
    }
    $ward = filter_var($query['ward'] ?? '', FILTER_VALIDATE_INT);
    $classification = (string) ($query['classification'] ?? '');
    if (!in_array($classification, PU_CLASSIFICATIONS, true)) {
        $classification = '';
    }
    $assignment = (string) ($query['assignment'] ?? '');
    if (!in_array($assignment, ['assigned', 'unassigned'], true)) {
        $assignment = '';
    }
    $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT);
    if ($page === false || $page < 1) {
        $page = 1;
    }
    if ($page > 10000) {
        $page = 10000;
    }
    $wardId = $ward === false ? 0 : (int) $ward;
    if ($wardId > 0) {
        $known = false;
        foreach (pu_wards() as $item) {
            if ($item['id'] === $wardId) {
                $known = true;
                break;
            }
        }
        if (!$known) {
            $wardId = 0;
        }
    }

    return [
        'q' => $q,
        'ward' => $wardId,
        'classification' => $classification,
        'assignment' => $assignment,
        'page' => $page,
    ];
}

/**
 * @param array{q: string, ward: int, classification: string, assignment: string, page: int} $filters
 */
function pu_filters_query(array $filters, ?int $page = null): string
{
    $params = [];
    if ($filters['q'] !== '') {
        $params['q'] = $filters['q'];
    }
    if ($filters['ward'] > 0) {
        $params['ward'] = (string) $filters['ward'];
    }
    if ($filters['classification'] !== '') {
        $params['classification'] = $filters['classification'];
    }
    if ($filters['assignment'] !== '') {
        $params['assignment'] = $filters['assignment'];
    }
    $params['page'] = (string) ($page ?? $filters['page']);
    if ($params['page'] === '1' && count($params) === 1) {
        return '';
    }

    return http_build_query($params);
}

function pu_like(string $value): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%';
}

/**
 * @param array{q: string, ward: int, classification: string, assignment: string, page: int} $filters
 * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, from: int, to: int}
 */
function pu_list(array $filters): array
{
    $where = [];
    $params = [];
    if ($filters['q'] !== '') {
        $like = pu_like($filters['q']);
        $where[] = "(pu.unit_code LIKE :q_code ESCAPE '\\\\' OR pu.name LIKE :q_name ESCAPE '\\\\')";
        $params['q_code'] = $like;
        $params['q_name'] = $like;
    }
    if ($filters['ward'] > 0) {
        $where[] = 'pu.ward_id = :ward_id';
        $params['ward_id'] = $filters['ward'];
    }
    if ($filters['classification'] !== '') {
        $where[] = 'pu.classification = :classification';
        $params['classification'] = $filters['classification'];
    }
    if ($filters['assignment'] === 'assigned') {
        $where[] = 'pu.agent_user_id IS NOT NULL';
    } elseif ($filters['assignment'] === 'unassigned') {
        $where[] = 'pu.agent_user_id IS NULL';
    }
    $sqlWhere = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

    $count = db()->prepare("SELECT COUNT(*) FROM polling_units pu {$sqlWhere}");
    foreach ($params as $key => $value) {
        $count->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $count->execute();
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / PU_PAGE_SIZE));
    $page = min($filters['page'], $pages);
    $offset = ($page - 1) * PU_PAGE_SIZE;
    $limit = PU_PAGE_SIZE;

    $statement = db()->prepare(
        "SELECT pu.id, pu.unit_code, pu.name, pu.lga_name, pu.classification, pu.is_active,
                pu.agent_user_id, w.name AS ward_name,
                agent.full_name AS agent_name, agent.username AS agent_username
         FROM polling_units pu
         INNER JOIN wards w ON w.id = pu.ward_id
         LEFT JOIN users agent ON agent.id = pu.agent_user_id
         {$sqlWhere}
         ORDER BY w.name, pu.unit_code
         LIMIT {$limit} OFFSET {$offset}"
    );
    foreach ($params as $key => $value) {
        $statement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $statement->execute();

    return [
        'rows' => $statement->fetchAll(),
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'from' => $total === 0 ? 0 : $offset + 1,
        'to' => min($offset + PU_PAGE_SIZE, $total),
    ];
}

/**
 * @return array<string, mixed>|null
 */
function pu_find(int $id): ?array
{
    $statement = db()->prepare(
        'SELECT pu.id, pu.unit_code, pu.name, pu.ward_id, pu.lga_name, pu.classification,
                pu.agent_user_id, pu.assigned_at, pu.is_active, pu.created_at, pu.updated_at,
                w.name AS ward_name,
                agent.full_name AS agent_name, agent.username AS agent_username,
                agent.is_active AS agent_active,
                assigner.full_name AS assigned_by_name
         FROM polling_units pu
         INNER JOIN wards w ON w.id = pu.ward_id
         LEFT JOIN users agent ON agent.id = pu.agent_user_id
         LEFT JOIN users assigner ON assigner.id = pu.assigned_by
         WHERE pu.id = :id
         LIMIT 1'
    );
    $statement->bindValue(':id', $id, PDO::PARAM_INT);
    $statement->execute();
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

/**
 * @return list<array<string, mixed>>
 */
function pu_recent_audit(int $unitId): array
{
    $statement = db()->prepare(
        'SELECT a.action, a.summary, a.created_at, u.full_name
         FROM audit_log a
         LEFT JOIN users u ON u.id = a.actor_user_id
         WHERE a.entity_type = :entity AND a.entity_id = :id
         ORDER BY a.id DESC
         LIMIT 8'
    );
    $statement->execute(['entity' => 'polling_unit', 'id' => $unitId]);

    return $statement->fetchAll();
}

/**
 * @return list<array{id: int, full_name: string, username: string}>
 */
function pu_agents(): array
{
    $statement = db()->query(
        "SELECT id, full_name, username
         FROM users
         WHERE role = 'polling_agent' AND is_active = 1
         ORDER BY full_name, username"
    );
    $agents = [];
    foreach ($statement as $row) {
        $agents[] = [
            'id' => (int) $row['id'],
            'full_name' => (string) $row['full_name'],
            'username' => (string) $row['username'],
        ];
    }

    return $agents;
}

/**
 * @param array<string, mixed> $input
 * @return array{errors: array<string, string>, data: array{agent_name: string, agent_username: string, agent_email: string}}
 */
function pu_validate_agent(array $input): array
{
    $errors = [];
    $fullName = trim((string) ($input['agent_name'] ?? ''));
    $username = strtolower(trim((string) ($input['agent_username'] ?? '')));
    $email = strtolower(trim((string) ($input['agent_email'] ?? '')));

    if ($fullName === '' || strlen($fullName) > 150 || preg_match('/[\x00-\x1F\x7F]/', $fullName)) {
        $errors['agent_name'] = 'Enter the agent’s full name, using 150 characters or fewer.';
    }
    if (!preg_match('/\A[a-z0-9._-]{3,64}\z/', $username)) {
        $errors['agent_username'] = 'Username must be 3–64 characters and use only letters, numbers, dots, underscores, or hyphens.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        $errors['agent_email'] = 'Enter a valid email address.';
    }

    return [
        'errors' => $errors,
        'data' => [
            'agent_name' => $fullName,
            'agent_username' => $username,
            'agent_email' => $email,
        ],
    ];
}

/**
 * Creates a polling-agent account with an unusable random password hash.
 * The agent chooses a real password through a single-use setup link emailed separately.
 *
 * @param array{agent_name: string, agent_username: string, agent_email: string} $data
 * @param (callable(PDO, int): void)|null $withinTransaction Runs after the account and assignment are written, before commit.
 * @return array{ok: bool, error: string, agent_id?: int}
 */
function pu_register_agent(int $unitId, array $data, int $actorId, ?callable $withinTransaction = null): array
{
    $unit = pu_find($unitId);
    if ($unit === null) {
        return ['ok' => false, 'error' => 'That polling unit was not found.'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, full_name, role, is_active)
             VALUES (:username, :email, :password_hash, :full_name, :role, 1)'
        );
        $insert->execute([
            'username' => $data['agent_username'],
            'email' => $data['agent_email'],
            'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'full_name' => $data['agent_name'],
            'role' => 'polling_agent',
        ]);
        $agentId = (int) $pdo->lastInsertId();
        $assigned = pu_assign($unitId, $agentId, $actorId);
        if (!$assigned['ok']) {
            $pdo->rollBack();

            return $assigned;
        }
        if ($withinTransaction !== null) {
            $withinTransaction($pdo, $agentId);
        }
        $pdo->commit();

        return ['ok' => true, 'error' => '', 'agent_id' => $agentId];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function pu_code_taken(string $code, ?int $ignoreId = null): bool
{
    if ($ignoreId === null) {
        $statement = db()->prepare('SELECT id FROM polling_units WHERE unit_code = :code LIMIT 1');
        $statement->execute(['code' => $code]);
    } else {
        $statement = db()->prepare('SELECT id FROM polling_units WHERE unit_code = :code AND id <> :id LIMIT 1');
        $statement->bindValue(':code', $code, PDO::PARAM_STR);
        $statement->bindValue(':id', $ignoreId, PDO::PARAM_INT);
        $statement->execute();
    }

    return (bool) $statement->fetch();
}

/**
 * @param array<string, mixed> $input
 * @return array{errors: array<string, string>, data: array{unit_code: string, name: string, ward_id: int, classification: string, is_active: int}}
 */
function pu_validate_unit(array $input, ?int $ignoreId = null): array
{
    $errors = [];
    $code = pu_normalize_code((string) ($input['unit_code'] ?? ''));
    $name = pu_normalize_name((string) ($input['name'] ?? ''));
    $wardId = filter_var($input['ward_id'] ?? '', FILTER_VALIDATE_INT);
    $classification = pu_canonical_classification((string) ($input['classification'] ?? ''));
    $wards = pu_wards();
    $knownWard = false;
    if ($wardId !== false) {
        foreach ($wards as $ward) {
            if ($ward['id'] === (int) $wardId) {
                $knownWard = true;
                break;
            }
        }
    }

    if ($code === null) {
        $errors['unit_code'] = 'Enter a polling-unit ID using letters, numbers, and - / . _ only, up to 32 characters.';
    } elseif (pu_code_taken($code, $ignoreId)) {
        $errors['unit_code'] = 'That polling-unit ID is already in use.';
    }
    if ($name === null) {
        $errors['name'] = 'Enter a polling-unit name of 150 characters or fewer.';
    }
    if ($wardId === false || (int) $wardId < 1 || !$knownWard) {
        $errors['ward_id'] = 'Select a ward.';
    }
    if ($classification === null) {
        $errors['classification'] = 'Classification must be EXISTING PU or NEW PU.';
    }

    return [
        'errors' => $errors,
        'data' => [
            'unit_code' => $code ?? trim((string) ($input['unit_code'] ?? '')),
            'name' => $name ?? trim((string) ($input['name'] ?? '')),
            'ward_id' => $wardId === false ? 0 : (int) $wardId,
            'classification' => $classification ?? '',
            'is_active' => isset($input['is_active']) ? 1 : 0,
        ],
    ];
}

function pu_audit(?int $actorId, string $action, ?int $entityId, string $summary): void
{
    $summary = str_replace(["\r", "\n"], ' ', $summary);
    $length = function_exists('mb_substr') ? mb_strlen($summary) : strlen($summary);
    if ($length > 255) {
        $summary = (function_exists('mb_substr') ? mb_substr($summary, 0, 252) : substr($summary, 0, 252)) . '...';
    }
    $statement = db()->prepare(
        'INSERT INTO audit_log (actor_user_id, action, entity_type, entity_id, summary)
         VALUES (:actor, :action, :entity, :entity_id, :summary)'
    );
    $statement->bindValue(':actor', $actorId !== null && $actorId > 0 ? $actorId : null, $actorId !== null && $actorId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $statement->bindValue(':action', $action, PDO::PARAM_STR);
    $statement->bindValue(':entity', 'polling_unit', PDO::PARAM_STR);
    $statement->bindValue(':entity_id', $entityId !== null && $entityId > 0 ? $entityId : null, $entityId !== null && $entityId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $statement->bindValue(':summary', $summary, PDO::PARAM_STR);
    $statement->execute();
}

function pu_create(array $data, int $actorId): int
{
    $lga = pu_lga_for_ward_id((int) $data['ward_id']) ?? PU_LGA;
    $statement = db()->prepare(
        'INSERT INTO polling_units (unit_code, name, ward_id, lga_name, classification, is_active)
         VALUES (:unit_code, :name, :ward_id, :lga_name, :classification, 1)'
    );
    $statement->bindValue(':unit_code', $data['unit_code'], PDO::PARAM_STR);
    $statement->bindValue(':name', $data['name'], PDO::PARAM_STR);
    $statement->bindValue(':ward_id', $data['ward_id'], PDO::PARAM_INT);
    $statement->bindValue(':lga_name', $lga, PDO::PARAM_STR);
    $statement->bindValue(':classification', $data['classification'], PDO::PARAM_STR);
    $statement->execute();
    $id = (int) db()->lastInsertId();
    pu_audit($actorId, 'create', $id, 'Created polling unit ' . $data['unit_code'] . '.');

    return $id;
}

function pu_update(int $id, array $data, int $actorId, string $previousCode): void
{
    $lga = pu_lga_for_ward_id((int) $data['ward_id']) ?? PU_LGA;
    $statement = db()->prepare(
        'UPDATE polling_units
         SET unit_code = :unit_code, name = :name, ward_id = :ward_id,
             lga_name = :lga_name, classification = :classification, is_active = :is_active
         WHERE id = :id'
    );
    $statement->bindValue(':unit_code', $data['unit_code'], PDO::PARAM_STR);
    $statement->bindValue(':name', $data['name'], PDO::PARAM_STR);
    $statement->bindValue(':ward_id', $data['ward_id'], PDO::PARAM_INT);
    $statement->bindValue(':lga_name', $lga, PDO::PARAM_STR);
    $statement->bindValue(':classification', $data['classification'], PDO::PARAM_STR);
    $statement->bindValue(':is_active', $data['is_active'], PDO::PARAM_INT);
    $statement->bindValue(':id', $id, PDO::PARAM_INT);
    $statement->execute();
    $summary = 'Updated polling unit ' . $data['unit_code'] . '.';
    if ($previousCode !== $data['unit_code']) {
        $summary = 'Updated polling unit ' . $previousCode . ' to ' . $data['unit_code'] . '.';
    }
    pu_audit($actorId, 'update', $id, $summary);
}

/**
 * @return array{ok: bool, error: string}
 */
function pu_assign(int $unitId, int $agentId, int $actorId): array
{
    $unit = pu_find($unitId);
    if ($unit === null) {
        return ['ok' => false, 'error' => 'That polling unit was not found.'];
    }
    $statement = db()->prepare(
        'SELECT id, username, role, is_active FROM users WHERE id = :id LIMIT 1'
    );
    $statement->bindValue(':id', $agentId, PDO::PARAM_INT);
    $statement->execute();
    $agent = $statement->fetch();
    if (!is_array($agent)) {
        return ['ok' => false, 'error' => 'That account does not exist.'];
    }
    if ((string) $agent['role'] !== 'polling_agent') {
        return ['ok' => false, 'error' => 'Only a registered polling agent can be assigned to a polling unit.'];
    }
    if ((int) $agent['is_active'] !== 1) {
        return ['ok' => false, 'error' => 'That polling agent account is inactive.'];
    }
    $update = db()->prepare(
        'UPDATE polling_units
         SET agent_user_id = :agent, assigned_by = :actor, assigned_at = NOW()
         WHERE id = :id'
    );
    $update->bindValue(':agent', $agentId, PDO::PARAM_INT);
    $update->bindValue(':actor', $actorId, PDO::PARAM_INT);
    $update->bindValue(':id', $unitId, PDO::PARAM_INT);
    $update->execute();
    pu_audit($actorId, 'assign', $unitId, 'Assigned ' . $agent['username'] . ' to polling unit ' . $unit['unit_code'] . '.');

    return ['ok' => true, 'error' => ''];
}

/**
 * @return array{ok: bool, error: string}
 */
function pu_unassign(int $unitId, int $actorId): array
{
    $unit = pu_find($unitId);
    if ($unit === null) {
        return ['ok' => false, 'error' => 'That polling unit was not found.'];
    }
    if ($unit['agent_user_id'] === null) {
        return ['ok' => false, 'error' => 'This polling unit has no assigned agent.'];
    }
    $update = db()->prepare(
        'UPDATE polling_units
         SET agent_user_id = NULL, assigned_by = NULL, assigned_at = NULL
         WHERE id = :id'
    );
    $update->bindValue(':id', $unitId, PDO::PARAM_INT);
    $update->execute();
    pu_audit($actorId, 'unassign', $unitId, 'Cleared the agent on polling unit ' . $unit['unit_code'] . '.');

    return ['ok' => true, 'error' => ''];
}

/**
 * @return array{ok: bool, error: string}
 */
function pu_set_active(int $unitId, bool $active, int $actorId): array
{
    $unit = pu_find($unitId);
    if ($unit === null) {
        return ['ok' => false, 'error' => 'That polling unit was not found.'];
    }
    $current = (int) $unit['is_active'] === 1;
    if ($current === $active) {
        return ['ok' => false, 'error' => $active ? 'This polling unit is already active.' : 'This polling unit is already inactive.'];
    }
    $update = db()->prepare('UPDATE polling_units SET is_active = :active WHERE id = :id');
    $update->bindValue(':active', $active ? 1 : 0, PDO::PARAM_INT);
    $update->bindValue(':id', $unitId, PDO::PARAM_INT);
    $update->execute();
    pu_audit(
        $actorId,
        $active ? 'reactivate' : 'deactivate',
        $unitId,
        ($active ? 'Reactivated' : 'Deactivated') . ' polling unit ' . $unit['unit_code'] . '.'
    );

    return ['ok' => true, 'error' => ''];
}

function pu_is_duplicate_key(PDOException $exception): bool
{
    return ($exception->errorInfo[0] ?? '') === '23000';
}

/**
 * @return array{ok: bool, message: string, insert_rows: list<array<string, mixed>>, inserted: int, updated: int, skipped: int, failed: int, failures: list<array{line: int, polling_unit_id: string, message: string}>, skipped_rows: list<array{line: int, polling_unit_id: string, message: string}>, discrepancies: list<array{ward: string, expected: int, actual: int, gap: int}>, projected: bool}
 */
function pu_import_contents(string $contents, bool $apply, ?int $actorId): array
{
    $empty = pu_empty_import();
    if (strlen($contents) > PU_IMPORT_MAX_BYTES) {
        $empty['message'] = 'The CSV file is larger than 2 MB.';

        return $empty;
    }
    if (str_starts_with($contents, "\xEF\xBB\xBF")) {
        $contents = substr($contents, 3);
    }
    if ($contents === '' || (function_exists('mb_check_encoding') && !mb_check_encoding($contents, 'UTF-8'))) {
        $empty['message'] = 'The CSV file must be UTF-8 text.';

        return $empty;
    }

    $parsed = pu_parse_csv_records($contents);
    if ($parsed['message'] !== '') {
        $empty['message'] = $parsed['message'];

        return $empty;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $classified = pu_classify_import_rows($parsed['records']);
        $extra = [];
        foreach ($classified['insert_rows'] as $row) {
            $wardName = (string) $row['ward_name'];
            $extra[$wardName] = ($extra[$wardName] ?? 0) + 1;
        }
        if ($apply && $classified['insert_rows'] !== []) {
            $written = pu_write_import_rows($classified['insert_rows'], $actorId);
            $classified['inserted'] = $written['inserted'];
            $classified['skipped'] += $written['skipped'];
            $classified['failed'] += $written['failed'];
            $classified['skipped_rows'] = array_merge($classified['skipped_rows'], $written['skipped_rows']);
            $classified['failures'] = array_merge($classified['failures'], $written['failures']);
            $classified['insert_rows'] = [];
            $extra = [];
        }
        $report = $classified;
        $report['ok'] = true;
        $report['message'] = '';
        $report['updated'] = 0;
        $report['projected'] = !$apply;
        $report['discrepancies'] = pu_discrepancies($apply ? [] : $extra);
        if ($apply) {
            $pdo->commit();
        } else {
            $pdo->rollBack();
        }

        return $report;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());
        $empty['message'] = 'The import was rolled back. No polling units were saved.';

        return $empty;
    }
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{ok: bool, message: string, inserted: int, updated: int, skipped: int, failed: int, failures: list<array{line: int, polling_unit_id: string, message: string}>, skipped_rows: list<array{line: int, polling_unit_id: string, message: string}>}
 */
function pu_commit_rows(array $rows, int $actorId): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $written = pu_write_import_rows($rows, $actorId);
        $pdo->commit();

        return [
            'ok' => true,
            'message' => '',
            'inserted' => $written['inserted'],
            'updated' => 0,
            'skipped' => $written['skipped'],
            'failed' => $written['failed'],
            'failures' => $written['failures'],
            'skipped_rows' => $written['skipped_rows'],
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());

        return [
            'ok' => false,
            'message' => 'The import was rolled back. No polling units were saved.',
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'failures' => [],
            'skipped_rows' => [],
        ];
    }
}

/**
 * @return array{ok: bool, message: string, insert_rows: list<array<string, mixed>>, inserted: int, updated: int, skipped: int, failed: int, failures: list<array{line: int, polling_unit_id: string, message: string}>, skipped_rows: list<array{line: int, polling_unit_id: string, message: string}>, discrepancies: list<array{ward: string, expected: int, actual: int, gap: int}>, projected: bool}
 */
function pu_empty_import(): array
{
    return [
        'ok' => false,
        'message' => 'The CSV file could not be read.',
        'insert_rows' => [],
        'inserted' => 0,
        'updated' => 0,
        'skipped' => 0,
        'failed' => 0,
        'failures' => [],
        'skipped_rows' => [],
        'discrepancies' => [],
        'projected' => true,
    ];
}

/**
 * @return array{message: string, records: list<array{line: int, polling_unit_id: string, polling_unit_name: string, ward_name: string, lga_name: string, classification: string}>}
 */
function pu_parse_csv_records(string $contents): array
{
    $handle = fopen('php://temp', 'rb+');
    if ($handle === false) {
        return ['message' => 'The CSV file could not be read.', 'records' => []];
    }
    fwrite($handle, $contents);
    rewind($handle);
    $header = fgetcsv($handle);
    if (!is_array($header)) {
        fclose($handle);

        return ['message' => 'The CSV file needs a header row.', 'records' => []];
    }
    $map = [];
    foreach ($header as $index => $column) {
        $name = strtolower(trim((string) $column));
        $map[$name] = $index;
    }
    $required = ['polling_unit_id', 'polling_unit_name', 'ward_name', 'lga_name', 'classification'];
    foreach ($required as $column) {
        if (!array_key_exists($column, $map)) {
            fclose($handle);

            return ['message' => 'The CSV header must include polling_unit_id, polling_unit_name, ward_name, lga_name, and classification.', 'records' => []];
        }
    }

    $records = [];
    $line = 1;
    while (($row = fgetcsv($handle)) !== false) {
        $line++;
        if (!is_array($row) || pu_csv_row_empty($row)) {
            continue;
        }
        $records[] = [
            'line' => $line,
            'polling_unit_id' => trim((string) ($row[$map['polling_unit_id']] ?? '')),
            'polling_unit_name' => trim((string) ($row[$map['polling_unit_name']] ?? '')),
            'ward_name' => trim((string) ($row[$map['ward_name']] ?? '')),
            'lga_name' => trim((string) ($row[$map['lga_name']] ?? '')),
            'classification' => trim((string) ($row[$map['classification']] ?? '')),
        ];
        if (count($records) > PU_IMPORT_MAX_ROWS) {
            fclose($handle);

            return ['message' => 'The CSV file has more than ' . PU_IMPORT_MAX_ROWS . ' data rows.', 'records' => []];
        }
    }
    fclose($handle);

    return ['message' => '', 'records' => $records];
}

/**
 * @param list<string|null> $row
 */
function pu_csv_row_empty(array $row): bool
{
    foreach ($row as $value) {
        if (trim((string) $value) !== '') {
            return false;
        }
    }

    return true;
}

/**
 * @param list<array{line: int, polling_unit_id: string, polling_unit_name: string, ward_name: string, lga_name: string, classification: string}> $records
 * @return array{insert_rows: list<array<string, mixed>>, inserted: int, skipped: int, failed: int, failures: list<array{line: int, polling_unit_id: string, message: string}>, skipped_rows: list<array{line: int, polling_unit_id: string, message: string}>}
 */
function pu_classify_import_rows(array $records): array
{
    $wardMap = pu_ward_map();
    $seen = [];
    $candidates = [];
    $failed = 0;
    $failures = [];

    foreach ($records as $record) {
        $code = pu_normalize_code($record['polling_unit_id']);
        $name = pu_normalize_name($record['polling_unit_name']);
        $wardKey = pu_canonical_ward($record['ward_name']);
        $lga = pu_canonical_lga($record['lga_name']);
        $classification = pu_canonical_classification($record['classification']);
        $displayCode = $record['polling_unit_id'] !== '' ? $record['polling_unit_id'] : '(blank)';

        if ($code === null) {
            $failed++;
            $failures[] = ['line' => $record['line'], 'polling_unit_id' => $displayCode, 'message' => 'Polling-unit ID is missing or invalid.'];
            continue;
        }
        if (isset($seen[$code])) {
            $failed++;
            $failures[] = ['line' => $record['line'], 'polling_unit_id' => $code, 'message' => 'Duplicate polling-unit ID in this file.'];
            continue;
        }
        $seen[$code] = true;
        if ($name === null) {
            $failed++;
            $failures[] = ['line' => $record['line'], 'polling_unit_id' => $code, 'message' => 'Polling-unit name is missing or invalid.'];
            continue;
        }
        if ($wardKey === null || !isset($wardMap[$wardKey])) {
            $failed++;
            $failures[] = ['line' => $record['line'], 'polling_unit_id' => $code, 'message' => 'Ward name does not match a registered constituency ward.'];
            continue;
        }
        $expectedLga = pu_lga_for_ward_key($wardKey);
        if ($lga === null || $expectedLga === null || $lga !== $expectedLga) {
            $failed++;
            $failures[] = ['line' => $record['line'], 'polling_unit_id' => $code, 'message' => 'LGA must match the ward (Ohaji/Egbema, Oguta, or Oru West).'];
            continue;
        }
        if ($classification === null) {
            $failed++;
            $failures[] = ['line' => $record['line'], 'polling_unit_id' => $code, 'message' => 'Classification must be EXISTING PU or NEW PU.'];
            continue;
        }
        $candidates[] = [
            'line' => $record['line'],
            'unit_code' => $code,
            'name' => $name,
            'ward_id' => $wardMap[$wardKey]['id'],
            'ward_name' => $wardMap[$wardKey]['name'],
            'lga_name' => $lga,
            'classification' => $classification,
        ];
    }

    $existing = pu_existing_codes(array_column($candidates, 'unit_code'));
    $insertRows = [];
    $skipped = 0;
    $skippedRows = [];
    foreach ($candidates as $candidate) {
        if (isset($existing[$candidate['unit_code']])) {
            $skipped++;
            $skippedRows[] = [
                'line' => $candidate['line'],
                'polling_unit_id' => $candidate['unit_code'],
                'message' => 'Already registered. The existing record was not changed.',
            ];
            continue;
        }
        $insertRows[] = $candidate;
    }

    return [
        'insert_rows' => $insertRows,
        'inserted' => count($insertRows),
        'skipped' => $skipped,
        'failed' => $failed,
        'failures' => $failures,
        'skipped_rows' => $skippedRows,
    ];
}

/**
 * @param list<string> $codes
 * @return array<string, true>
 */
function pu_existing_codes(array $codes): array
{
    $codes = array_values(array_unique(array_filter($codes, static fn (string $code): bool => $code !== '')));
    if ($codes === []) {
        return [];
    }
    $placeholders = implode(', ', array_fill(0, count($codes), '?'));
    $statement = db()->prepare("SELECT unit_code FROM polling_units WHERE unit_code IN ({$placeholders})");
    foreach ($codes as $index => $code) {
        $statement->bindValue($index + 1, $code, PDO::PARAM_STR);
    }
    $statement->execute();
    $found = [];
    foreach ($statement as $row) {
        $found[(string) $row['unit_code']] = true;
    }

    return $found;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{inserted: int, skipped: int, failed: int, failures: list<array{line: int, polling_unit_id: string, message: string}>, skipped_rows: list<array{line: int, polling_unit_id: string, message: string}>}
 */
function pu_write_import_rows(array $rows, ?int $actorId): array
{
    $exists = db()->prepare('SELECT id, name FROM polling_units WHERE unit_code = :code LIMIT 1');
    $insert = db()->prepare(
        'INSERT INTO polling_units (unit_code, name, ward_id, lga_name, classification, is_active)
         VALUES (:unit_code, :name, :ward_id, :lga_name, :classification, 1)'
    );
    $wardMap = pu_ward_map();
    $inserted = 0;
    $skipped = 0;
    $failed = 0;
    $failures = [];
    $skippedRows = [];
    $seen = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            $failed++;
            $failures[] = ['line' => 0, 'polling_unit_id' => '(blank)', 'message' => 'The row could not be read.'];
            continue;
        }
        $line = (int) ($row['line'] ?? 0);
        $code = pu_normalize_code((string) ($row['unit_code'] ?? ''));
        $name = pu_normalize_name((string) ($row['name'] ?? ''));
        $classification = pu_canonical_classification((string) ($row['classification'] ?? ''));
        $lga = pu_canonical_lga((string) ($row['lga_name'] ?? ''));
        $wardLga = pu_lga_for_ward_id($wardId = (int) ($row['ward_id'] ?? 0));
        if ($lga === null || $wardLga === null || $lga !== $wardLga) {
            $lga = null;
        }
        $wardOk = false;
        foreach ($wardMap as $ward) {
            if ($ward['id'] === $wardId) {
                $wardOk = true;
                break;
            }
        }
        $display = $code ?? '(blank)';
        if ($code === null || $name === null || $classification === null || $lga === null || !$wardOk) {
            $failed++;
            $failures[] = ['line' => $line, 'polling_unit_id' => $display, 'message' => 'The row failed validation and was not saved.'];
            continue;
        }
        if (isset($seen[$code])) {
            $failed++;
            $failures[] = ['line' => $line, 'polling_unit_id' => $code, 'message' => 'Duplicate polling-unit ID in this file.'];
            continue;
        }
        $seen[$code] = true;
        $exists->execute(['code' => $code]);
        $current = $exists->fetch();
        if (is_array($current)) {
            $skipped++;
            $skippedRows[] = [
                'line' => $line,
                'polling_unit_id' => $code,
                'message' => 'Already registered. The existing record was not changed.',
            ];
            continue;
        }
        $insert->bindValue(':unit_code', $code, PDO::PARAM_STR);
        $insert->bindValue(':name', $name, PDO::PARAM_STR);
        $insert->bindValue(':ward_id', $wardId, PDO::PARAM_INT);
        $insert->bindValue(':lga_name', $lga, PDO::PARAM_STR);
        $insert->bindValue(':classification', $classification, PDO::PARAM_STR);
        $insert->execute();
        $inserted++;
    }

    if ($inserted > 0) {
        pu_audit($actorId, 'import', null, 'Imported ' . $inserted . ' polling units from CSV. Existing IDs were left unchanged.');
    }

    return [
        'inserted' => $inserted,
        'skipped' => $skipped,
        'failed' => $failed,
        'failures' => $failures,
        'skipped_rows' => $skippedRows,
    ];
}

/**
 * @param array{id: int, username: string, email: string, full_name: string, role: string, last_login_at?: ?string} $user
 */
function pu_render_missing_schema(array $user): void
{
    render_shell_start('Polling Unit Management', 'polling-units', $user);
    ?>
    <nav class="pu-crumb" aria-label="Breadcrumb">
        <a href="<?= e(url('admin/dashboard.php')) ?>">Administration</a>
        <span aria-hidden="true">/</span>
        <span>Polling Unit Management</span>
    </nav>
    <header class="cms-page__header">
        <div>
            <h1 class="cms-page__title">Polling Unit Management</h1>
            <p class="cms-page__subtitle">The polling-unit tables are not available yet.</p>
        </div>
    </header>
    <article class="cms-card">
        <div class="cms-card__body">
            <p>Apply the polling-unit migrations, then reload this page. They create missing tables and ward targets for Ohaji/Egbema and Oguta. They do not remove existing data.</p>
            <p class="cms-muted">mysql -u root election_monitor_app &lt; database/migration_polling_units.sql</p>
        </div>
    </article>
    <?php
    render_shell_end();
}

function pu_form_script(): void
{
    ?>
    <script nonce="<?= e(app_csp_nonce()) ?>">
    document.querySelectorAll('form[data-pu-busy]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var button = form.querySelector('[type="submit"]');
            if (!button || button.disabled) {
                return;
            }
            button.disabled = true;
            var label = form.getAttribute('data-pu-busy');
            if (label) {
                button.textContent = label;
            }
        });
    });
    </script>
    <?php
}
