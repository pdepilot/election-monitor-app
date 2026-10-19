<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/scope.php';
require_once __DIR__ . '/dashboard_view.php';

const RESULT_PAGE_SIZE = 20;
const RESULT_MANAGERS = ['super_admin', 'admin'];
const RESULT_VIEWERS = ['super_admin', 'admin', 'supervisor', 'collation_officer', 'polling_agent', 'viewer'];

/**
 * @return array{id: int, username: string, email: string, full_name: string, role: string, last_login_at?: ?string}
 */
function result_require_user(): array
{
    $user = auth_user();
    if ($user === null) {
        redirect_to('portal/login.php');
    }
    if (!in_array($user['role'], RESULT_VIEWERS, true)) {
        render_forbidden($user);
        exit;
    }

    return $user;
}

function result_schema_ready(): bool
{
    try {
        $pdo = db();
        $pdo->query('SELECT 1 FROM candidates LIMIT 1');
        $pdo->query('SELECT 1 FROM polling_unit_results LIMIT 1');
        $pdo->query('SELECT 1 FROM polling_unit_result_votes LIMIT 1');

        return true;
    } catch (Throwable $exception) {
        error_log($exception->getMessage());

        return false;
    }
}

function result_redirect(string $path): never
{
    if (!preg_match('#\Aresults/(index|entry|candidates|reviews|review)\.php(?:\?[a-z0-9_=&%+.-]*)?\z#', $path)) {
        $path = 'results/index.php';
    }
    header('Location: ' . url($path), true, 302);
    exit;
}

function result_flash(string $kind, string $message): void
{
    if (!in_array($kind, ['success', 'error', 'warn'], true)) {
        $kind = 'success';
    }
    $_SESSION['result_flash'] = ['kind' => $kind, 'message' => $message];
}

/**
 * @return array{kind: string, message: string}|null
 */
function result_take_flash(): ?array
{
    $flash = $_SESSION['result_flash'] ?? null;
    unset($_SESSION['result_flash']);
    if (!is_array($flash)) {
        return null;
    }
    $kind = $flash['kind'] ?? '';
    $message = $flash['message'] ?? '';
    if (!is_string($kind) || !is_string($message) || $message === '' || !in_array($kind, ['success', 'error', 'warn'], true)) {
        return null;
    }

    return ['kind' => $kind, 'message' => $message];
}

function result_is_manager(array $user): bool
{
    return in_array($user['role'], RESULT_MANAGERS, true);
}

/**
 * @param array{id: int, role: string} $user
 * @param array<string, mixed> $unit
 */
function result_can_view_unit(array $user, array $unit): bool
{
    if (!in_array($user['role'], RESULT_VIEWERS, true)) {
        return false;
    }

    return scope_can_unit($user, $unit);
}

/**
 * @param array{id: int, role: string} $user
 * @param array<string, mixed> $unit
 * @param array<string, mixed>|null $sheet
 */
function result_can_edit(array $user, array $unit, ?array $sheet): bool
{
    if ((int) ($unit['is_active'] ?? 0) !== 1) {
        return false;
    }
    $submitted = is_array($sheet) && (string) ($sheet['status'] ?? '') === 'submitted';
    if ($submitted) {
        return result_is_manager($user);
    }
    if (result_is_manager($user)) {
        return true;
    }

    return $user['role'] === 'polling_agent' && (int) ($unit['agent_user_id'] ?? 0) === (int) $user['id'];
}

/**
 * @param array{id: int, username: string, email: string, full_name: string, role: string, last_login_at?: ?string} $user
 */
function result_render_missing_schema(array $user): void
{
    render_shell_start('Results', 'results', $user);
    ?>
    <header class="cms-page__header">
        <div>
            <h1 class="cms-page__title">Results</h1>
            <p class="cms-page__subtitle">The result tables are not available yet.</p>
        </div>
    </header>
    <article class="cms-card">
        <div class="cms-card__body">
            <p>Apply the results migration, then reload this page. It creates the result tables and does not remove existing data.</p>
            <p class="cms-muted">mysql -u root election_monitor_app &lt; database/migration_results.sql</p>
        </div>
    </article>
    <?php
    render_shell_end();
}

/**
 * @param array{id: int, role: string}|null $user
 * @return array{units: int, submitted: int, drafts: int, missing: int}
 */
function result_summary(?array $user = null, int $agentId = 0): array
{
    $where = [];
    $params = [];
    if ($agentId > 0) {
        $where[] = 'pu.agent_user_id = :agent';
        $where[] = 'pu.is_active = 1';
        $params['agent'] = $agentId;
    } elseif (is_array($user)) {
        $scope = scope_sql_units($user, 'pu');
        $where[] = $scope['sql'];
        $params = array_merge($params, $scope['params']);
    }
    $sqlWhere = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
    $statement = db()->prepare(
        "SELECT
            COUNT(*) AS units,
            COALESCE(SUM(r.status = 'submitted'), 0) AS submitted,
            COALESCE(SUM(r.status = 'draft'), 0) AS drafts,
            COALESCE(SUM(r.id IS NULL), 0) AS missing
         FROM polling_units pu
         LEFT JOIN polling_unit_results r ON r.polling_unit_id = pu.id
         {$sqlWhere}"
    );
    foreach ($params as $key => $value) {
        $statement->bindValue(':' . $key, $value, PDO::PARAM_INT);
    }
    $statement->execute();
    $row = $statement->fetch();
    if (!is_array($row)) {
        $row = [];
    }

    return [
        'units' => (int) ($row['units'] ?? 0),
        'submitted' => (int) ($row['submitted'] ?? 0),
        'drafts' => (int) ($row['drafts'] ?? 0),
        'missing' => (int) ($row['missing'] ?? 0),
    ];
}

/**
 * @param array{id: int, role: string}|null $user
 * @return list<array{id: int, name: string}>
 */
function result_wards(?array $user = null): array
{
    if (is_array($user)) {
        $scope = scope_sql_filter_wards($user, 'w');
        $statement = db()->prepare('SELECT w.id, w.name FROM wards w WHERE ' . $scope['sql'] . ' ORDER BY w.name');
        foreach ($scope['params'] as $key => $value) {
            $statement->bindValue(':' . $key, $value, PDO::PARAM_INT);
        }
        $statement->execute();
        $rows = $statement->fetchAll();
    } else {
        $rows = db()->query('SELECT id, name FROM wards ORDER BY name')->fetchAll();
    }
    $wards = [];
    foreach ($rows as $row) {
        $wards[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }

    return $wards;
}

/**
 * @param array<string, mixed> $query
 * @return array{q: string, ward: int, status: string, page: int}
 */
/**
 * @param array{id: int, role: string}|null $user
 */
function result_filters_from_query(array $query, ?array $user = null): array
{
    $q = trim((string) ($query['q'] ?? ''));
    if (strlen($q) > 100) {
        $q = substr($q, 0, 100);
    }
    $ward = filter_var($query['ward'] ?? '', FILTER_VALIDATE_INT);
    $wardId = $ward === false ? 0 : (int) $ward;
    if ($wardId > 0) {
        $known = false;
        foreach (result_wards($user) as $item) {
            if ($item['id'] === $wardId) {
                $known = true;
                break;
            }
        }
        if (!$known) {
            $wardId = 0;
        }
    }
    $status = (string) ($query['status'] ?? '');
    if (!in_array($status, ['submitted', 'draft', 'missing'], true)) {
        $status = '';
    }
    $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT);
    if ($page === false || $page < 1) {
        $page = 1;
    }
    if ($page > 10000) {
        $page = 10000;
    }

    return ['q' => $q, 'ward' => $wardId, 'status' => $status, 'page' => $page];
}

/**
 * @param array{q: string, ward: int, status: string, page: int} $filters
 */
function result_filters_query(array $filters, ?int $page = null): string
{
    $params = [];
    if ($filters['q'] !== '') {
        $params['q'] = $filters['q'];
    }
    if ($filters['ward'] > 0) {
        $params['ward'] = (string) $filters['ward'];
    }
    if ($filters['status'] !== '') {
        $params['status'] = $filters['status'];
    }
    $params['page'] = (string) ($page ?? $filters['page']);
    if ($params['page'] === '1' && count($params) === 1) {
        return '';
    }

    return http_build_query($params);
}

function result_like(string $value): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%';
}

/**
 * @param array{q: string, ward: int, status: string, page: int} $filters
 * @param array{id: int, role: string} $user
 * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, from: int, to: int}
 */
function result_list(array $filters, array $user): array
{
    $where = [];
    $params = [];
    $scope = scope_sql_units($user, 'pu');
    $where[] = $scope['sql'];
    $params = array_merge($params, $scope['params']);
    if ($user['role'] === 'polling_agent') {
        $where[] = 'pu.is_active = 1';
    }
    if ($filters['q'] !== '') {
        $like = result_like($filters['q']);
        $where[] = "(pu.unit_code LIKE :q_code ESCAPE '\\\\' OR pu.name LIKE :q_name ESCAPE '\\\\')";
        $params['q_code'] = $like;
        $params['q_name'] = $like;
    }
    if ($filters['ward'] > 0) {
        $where[] = 'pu.ward_id = :ward_id';
        $params['ward_id'] = $filters['ward'];
    }
    if ($filters['status'] === 'missing') {
        $where[] = 'r.id IS NULL';
    } elseif ($filters['status'] !== '') {
        $where[] = 'r.status = :status';
        $params['status'] = $filters['status'];
    }
    $sqlWhere = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
    $count = db()->prepare(
        "SELECT COUNT(*)
         FROM polling_units pu
         LEFT JOIN polling_unit_results r ON r.polling_unit_id = pu.id
         {$sqlWhere}"
    );
    foreach ($params as $key => $value) {
        $count->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $count->execute();
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / RESULT_PAGE_SIZE));
    $page = min($filters['page'], $pages);
    $offset = ($page - 1) * RESULT_PAGE_SIZE;
    $limit = RESULT_PAGE_SIZE;
    $statement = db()->prepare(
        "SELECT pu.id, pu.unit_code, pu.name, pu.is_active, pu.agent_user_id,
                w.name AS ward_name, agent.full_name AS agent_name,
                r.id AS result_id, r.status, r.accredited_voters, r.rejected_ballots,
                COALESCE(v.valid_votes, 0) AS valid_votes
         FROM polling_units pu
         INNER JOIN wards w ON w.id = pu.ward_id
         LEFT JOIN users agent ON agent.id = pu.agent_user_id
         LEFT JOIN polling_unit_results r ON r.polling_unit_id = pu.id
         LEFT JOIN (
            SELECT result_id, SUM(votes) AS valid_votes
            FROM polling_unit_result_votes
            GROUP BY result_id
         ) v ON v.result_id = r.id
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
        'to' => min($offset + RESULT_PAGE_SIZE, $total),
    ];
}

/**
 * @return array<string, mixed>|null
 */
function result_unit(int $id): ?array
{
    $statement = db()->prepare(
        'SELECT pu.id, pu.unit_code, pu.name, pu.lga_name, pu.is_active, pu.agent_user_id,
                w.name AS ward_name, agent.full_name AS agent_name, agent.username AS agent_username
         FROM polling_units pu
         INNER JOIN wards w ON w.id = pu.ward_id
         LEFT JOIN users agent ON agent.id = pu.agent_user_id
         WHERE pu.id = :id
         LIMIT 1'
    );
    $statement->bindValue(':id', $id, PDO::PARAM_INT);
    $statement->execute();
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

/**
 * @return array<string, mixed>|null
 */
function result_sheet(int $unitId): ?array
{
    $statement = db()->prepare(
        'SELECT *
         FROM polling_unit_results
         WHERE polling_unit_id = :id
         LIMIT 1'
    );
    $statement->bindValue(':id', $unitId, PDO::PARAM_INT);
    $statement->execute();
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

/**
 * @return array<int, ?int> null where a draft left the count blank
 */
function result_votes(int $resultId): array
{
    $statement = db()->prepare('SELECT candidate_id, votes FROM polling_unit_result_votes WHERE result_id = :id');
    $statement->bindValue(':id', $resultId, PDO::PARAM_INT);
    $statement->execute();
    $votes = [];
    foreach ($statement as $row) {
        $votes[(int) $row['candidate_id']] = $row['votes'] === null ? null : (int) $row['votes'];
    }

    return $votes;
}

/**
 * @return list<array{id: int, full_name: string, party: string, sort_order: int, is_active: int}>
 */
function result_candidates(bool $activeOnly): array
{
    $sql = 'SELECT id, full_name, party, sort_order, is_active FROM candidates';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY sort_order, party, full_name';
    $rows = db()->query($sql)->fetchAll();
    $candidates = [];
    foreach ($rows as $row) {
        $candidates[] = [
            'id' => (int) $row['id'],
            'full_name' => (string) $row['full_name'],
            'party' => (string) $row['party'],
            'sort_order' => (int) $row['sort_order'],
            'is_active' => (int) $row['is_active'],
        ];
    }

    return $candidates;
}

/**
 * @return list<array{id: int, full_name: string, party: string, sort_order: int, is_active: int}>
 */
function result_candidates_for_sheet(?int $resultId): array
{
    if ($resultId === null) {
        return result_candidates(true);
    }
    $statement = db()->prepare(
        'SELECT c.id, c.full_name, c.party, c.sort_order, c.is_active
         FROM candidates c
         LEFT JOIN polling_unit_result_votes v ON v.candidate_id = c.id AND v.result_id = :result_id
         WHERE c.is_active = 1 OR v.id IS NOT NULL
         ORDER BY c.sort_order, c.party, c.full_name'
    );
    $statement->bindValue(':result_id', $resultId, PDO::PARAM_INT);
    $statement->execute();
    $candidates = [];
    foreach ($statement as $row) {
        $candidates[] = [
            'id' => (int) $row['id'],
            'full_name' => (string) $row['full_name'],
            'party' => (string) $row['party'],
            'sort_order' => (int) $row['sort_order'],
            'is_active' => (int) $row['is_active'],
        ];
    }

    return $candidates;
}

function result_count_value(mixed $value): ?int
{
    if (is_int($value)) {
        $number = $value;
    } elseif (is_string($value) && preg_match('/\A\d{1,6}\z/', $value)) {
        $number = (int) $value;
    } else {
        return null;
    }
    if ($number < 0 || $number > 100000) {
        return null;
    }

    return $number;
}

const RESULT_FIGURE_FIELDS = [
    'accredited_voters' => 'Accredited voters',
    'rejected_ballots' => 'Rejected ballots',
    'declared_valid_votes' => 'Total valid votes (as written on the sheet)',
    'declared_total_votes' => 'Total votes cast (as written on the sheet)',
];

function result_review_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        db()->query('SELECT declared_valid_votes, declared_total_votes FROM polling_unit_results LIMIT 1');
        db()->query('SELECT 1 FROM result_reviews LIMIT 1');
        db()->query('SELECT 1 FROM result_discrepancies LIMIT 1');
        $ready = true;
    } catch (Throwable $exception) {
        $ready = false;
    }

    return $ready;
}

/**
 * Checks the figures. A draft may be incomplete or inconsistent (problems become warnings),
 * but every value it does contain must be a valid count. Submission requires every figure and
 * every arithmetic check to pass.
 *
 * @param array<string, mixed> $input
 * @param list<array{id: int}> $candidates
 * @return array{errors: array<string, string>, warnings: list<string>, data: array{accredited_voters: ?int, rejected_ballots: ?int, declared_valid_votes: ?int, declared_total_votes: ?int, votes: array<int, ?int>}}
 */
function result_validate(array $input, array $candidates, bool $submit = true): array
{
    $errors = [];
    $data = [];
    $read = static function (mixed $raw, string $key, string $message) use (&$errors, $submit): ?int {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            if ($submit) {
                $errors[$key] = $message;
            }

            return null;
        }
        $value = result_count_value(is_string($raw) ? trim($raw) : $raw);
        if ($value === null) {
            $errors[$key] = $message;
        }

        return $value;
    };
    foreach (RESULT_FIGURE_FIELDS as $field => $label) {
        if ($field === 'declared_valid_votes' || $field === 'declared_total_votes') {
            $message = 'Enter the ' . lcfirst(explode(' (', $label)[0]) . ' written on the sheet, as a whole number from 0 to 100000.';
        } else {
            $message = 'Enter ' . lcfirst($label) . ' as a whole number from 0 to 100000.';
        }
        $data[$field] = $read($input[$field] ?? null, $field, $message);
    }
    $postedVotes = $input['votes'] ?? [];
    if (!is_array($postedVotes)) {
        $postedVotes = [];
    }
    $votes = [];
    $complete = true;
    foreach ($candidates as $candidate) {
        $candidateId = (int) $candidate['id'];
        $count = $read($postedVotes[$candidateId] ?? $postedVotes[(string) $candidateId] ?? null, 'votes', 'Enter a whole-number vote count for every candidate.');
        $complete = $complete && $count !== null;
        $votes[$candidateId] = $count;
    }
    $data['votes'] = $votes;

    $problems = [];
    if ($errors === []) {
        $sum = $complete ? array_sum($votes) : null;
        $declaredValid = $data['declared_valid_votes'];
        $declaredTotal = $data['declared_total_votes'];
        $rejected = $data['rejected_ballots'];
        $accredited = $data['accredited_voters'];
        if ($sum !== null && $declaredValid !== null && $sum !== $declaredValid) {
            $problems[] = 'Candidate votes add up to ' . $sum . ', but the sheet total of valid votes is ' . $declaredValid . '.';
        }
        if ($declaredValid !== null && $rejected !== null && $declaredTotal !== null && $declaredValid + $rejected !== $declaredTotal) {
            $problems[] = 'Valid votes (' . $declaredValid . ') plus rejected ballots (' . $rejected . ') do not equal total votes cast (' . $declaredTotal . ').';
        }
        if ($declaredTotal !== null && $accredited !== null && $declaredTotal > $accredited) {
            $problems[] = 'Total votes cast (' . $declaredTotal . ') cannot exceed accredited voters (' . $accredited . ').';
        }
        if ($sum !== null && $rejected !== null && $accredited !== null && $sum + $rejected > $accredited) {
            $problems[] = 'Candidate votes plus rejected ballots cannot exceed accredited voters.';
        }
    }
    if ($submit && $problems !== []) {
        $errors['form'] = 'The figures do not add up. ' . implode(' ', $problems);
        $problems = [];
    }

    return ['errors' => $errors, 'warnings' => $problems, 'data' => $data];
}

/**
 * Saves a draft or submission. The row is locked and the permission re-checked inside the
 * transaction, so a result submitted in another tab can never be changed by an agent, and an
 * agent who was unassigned in the meantime cannot save.
 *
 * @param array{accredited_voters: ?int, rejected_ballots: ?int, declared_valid_votes: ?int, declared_total_votes: ?int, votes: array<int, ?int>} $data
 * When called inside an open transaction it joins it: the caller then owns commit and rollback,
 * and a refusal leaves the rollback to the caller.
 *
 * @param array{id: int, role: string} $actor
 * @return array{ok: bool, message: string, result_id?: int}
 */
function result_save(int $unitId, array $data, array $actor, bool $submit, string $reason = ''): array
{
    $actorId = (int) $actor['id'];
    $reason = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $reason)));
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $unitLock = $pdo->prepare('SELECT id, is_active, agent_user_id FROM polling_units WHERE id = :id FOR UPDATE');
        $unitLock->bindValue(':id', $unitId, PDO::PARAM_INT);
        $unitLock->execute();
        $unit = $unitLock->fetch();
        $existing = $pdo->prepare('SELECT id, status FROM polling_unit_results WHERE polling_unit_id = :id LIMIT 1 FOR UPDATE');
        $existing->bindValue(':id', $unitId, PDO::PARAM_INT);
        $existing->execute();
        $current = $existing->fetch();
        if (!is_array($unit) || !result_can_edit($actor, $unit, is_array($current) ? $current : null)) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'message' => 'This result can no longer be changed from your account.'];
        }
        $wasSubmitted = is_array($current) && (string) $current['status'] === 'submitted';
        $length = function_exists('mb_strlen') ? mb_strlen($reason) : strlen($reason);
        if ($wasSubmitted && ($length < 5 || $length > 200)) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'message' => 'Correcting a submitted result needs a reason of 5 to 200 characters.'];
        }
        $status = ($submit || $wasSubmitted) ? 'submitted' : 'draft';
        $nullable = static fn (?int $value): int => $value === null ? PDO::PARAM_NULL : PDO::PARAM_INT;
        if (is_array($current)) {
            $resultId = (int) $current['id'];
            $update = $pdo->prepare(
                'UPDATE polling_unit_results
                 SET accredited_voters = :accredited, rejected_ballots = :rejected,
                     declared_valid_votes = :declared_valid, declared_total_votes = :declared_total, status = :status,
                     submitted_by = CASE WHEN :submit = 1 AND submitted_by IS NULL THEN :actor ELSE submitted_by END,
                     submitted_at = CASE WHEN :submit_at = 1 AND submitted_at IS NULL THEN NOW() ELSE submitted_at END,
                     updated_by = :updater
                 WHERE id = :id'
            );
            $update->bindValue(':status', $status, PDO::PARAM_STR);
            $update->bindValue(':submit', $status === 'submitted' ? 1 : 0, PDO::PARAM_INT);
            $update->bindValue(':submit_at', $status === 'submitted' ? 1 : 0, PDO::PARAM_INT);
            $update->bindValue(':actor', $actorId, PDO::PARAM_INT);
            $update->bindValue(':id', $resultId, PDO::PARAM_INT);
            $statement = $update;
        } else {
            $statement = $pdo->prepare(
                'INSERT INTO polling_unit_results
                    (polling_unit_id, accredited_voters, rejected_ballots, declared_valid_votes, declared_total_votes,
                     status, submitted_by, submitted_at, updated_by)
                 VALUES
                    (:unit_id, :accredited, :rejected, :declared_valid, :declared_total,
                     :status, :actor, CASE WHEN :submit = 1 THEN NOW() ELSE NULL END, :updater)'
            );
            $statement->bindValue(':unit_id', $unitId, PDO::PARAM_INT);
            $statement->bindValue(':status', $status, PDO::PARAM_STR);
            $statement->bindValue(':actor', $submit ? $actorId : null, $submit ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $statement->bindValue(':submit', $submit ? 1 : 0, PDO::PARAM_INT);
        }
        $statement->bindValue(':accredited', $data['accredited_voters'], $nullable($data['accredited_voters']));
        $statement->bindValue(':rejected', $data['rejected_ballots'], $nullable($data['rejected_ballots']));
        $statement->bindValue(':declared_valid', $data['declared_valid_votes'], $nullable($data['declared_valid_votes']));
        $statement->bindValue(':declared_total', $data['declared_total_votes'], $nullable($data['declared_total_votes']));
        $statement->bindValue(':updater', $actorId, PDO::PARAM_INT);
        $statement->execute();
        if (!is_array($current)) {
            $resultId = (int) $pdo->lastInsertId();
        }
        $vote = $pdo->prepare(
            'INSERT INTO polling_unit_result_votes (result_id, candidate_id, votes)
             VALUES (:result_id, :candidate_id, :votes)
             ON DUPLICATE KEY UPDATE votes = VALUES(votes)'
        );
        foreach ($data['votes'] as $candidateId => $count) {
            $vote->bindValue(':result_id', $resultId, PDO::PARAM_INT);
            $vote->bindValue(':candidate_id', (int) $candidateId, PDO::PARAM_INT);
            $vote->bindValue(':votes', $count, $nullable($count));
            $vote->execute();
        }
        if ($wasSubmitted) {
            result_audit($actorId, 'correct', $resultId, 'Corrected the submitted result for polling unit ' . $unitId . '. Reason: ' . $reason);
        } else {
            result_audit($actorId, $submit ? 'submit' : 'save', $resultId, ($submit ? 'Submitted' : 'Saved a draft of') . ' the result for polling unit ' . $unitId . '.');
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }

        return ['ok' => true, 'message' => $wasSubmitted ? 'Correction saved. Any earlier comparison with the result sheet must be repeated.' : ($submit ? 'Result submitted. It is now locked against agent edits.' : 'Draft saved.'), 'result_id' => $resultId];
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * @param array<string, mixed> $input
 * @return array{errors: array<string, string>, data: array{full_name: string, party: string, sort_order: int}}
 */
function result_validate_candidate(array $input): array
{
    $errors = [];
    $name = trim((string) ($input['full_name'] ?? ''));
    $party = strtoupper(trim((string) ($input['party'] ?? '')));
    $order = filter_var($input['sort_order'] ?? '0', FILTER_VALIDATE_INT);
    $length = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
    if ($name === '' || $length > 150 || preg_match('/[\x00-\x1F\x7F]/', $name) || preg_match('/\A[=+@]/', $name)) {
        $errors['full_name'] = 'Enter the candidate name, using 150 characters or fewer.';
    }
    if (!preg_match('/\A[A-Z][A-Z0-9]{1,11}\z/', $party)) {
        $errors['party'] = 'Enter a party code of 2 to 12 letters or numbers.';
    }
    if ($order === false || $order < 0 || $order > 999) {
        $errors['sort_order'] = 'Display order must be a whole number from 0 to 999.';
        $order = 0;
    }

    return [
        'errors' => $errors,
        'data' => [
            'full_name' => $name,
            'party' => $party,
            'sort_order' => (int) $order,
        ],
    ];
}

/**
 * @param array{full_name: string, party: string, sort_order: int} $data
 */
function result_add_candidate(array $data, int $actorId): void
{
    $statement = db()->prepare(
        'INSERT INTO candidates (full_name, party, sort_order, is_active)
         VALUES (:full_name, :party, :sort_order, 1)'
    );
    $statement->bindValue(':full_name', $data['full_name'], PDO::PARAM_STR);
    $statement->bindValue(':party', $data['party'], PDO::PARAM_STR);
    $statement->bindValue(':sort_order', $data['sort_order'], PDO::PARAM_INT);
    $statement->execute();
    result_audit($actorId, 'add_candidate', (int) db()->lastInsertId(), 'Added candidate ' . $data['party'] . '.');
}

function result_set_candidate_active(int $candidateId, bool $active, int $actorId): bool
{
    $statement = db()->prepare('UPDATE candidates SET is_active = :active WHERE id = :id');
    $statement->bindValue(':active', $active ? 1 : 0, PDO::PARAM_INT);
    $statement->bindValue(':id', $candidateId, PDO::PARAM_INT);
    $statement->execute();
    if ($statement->rowCount() < 1) {
        return false;
    }
    result_audit($actorId, $active ? 'activate_candidate' : 'deactivate_candidate', $candidateId, ($active ? 'Activated' : 'Deactivated') . ' a candidate.');

    return true;
}

function result_audit(?int $actorId, string $action, ?int $entityId, string $summary): void
{
    $summary = str_replace(["\r", "\n"], ' ', $summary);
    if ((function_exists('mb_strlen') ? mb_strlen($summary) : strlen($summary)) > 255) {
        $summary = (function_exists('mb_substr') ? mb_substr($summary, 0, 252) : substr($summary, 0, 252)) . '...';
    }
    $statement = db()->prepare(
        'INSERT INTO audit_log (actor_user_id, action, entity_type, entity_id, summary)
         VALUES (:actor, :action, :entity, :entity_id, :summary)'
    );
    $statement->bindValue(':actor', $actorId !== null && $actorId > 0 ? $actorId : null, $actorId !== null && $actorId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $statement->bindValue(':action', $action, PDO::PARAM_STR);
    $statement->bindValue(':entity', 'result', PDO::PARAM_STR);
    $statement->bindValue(':entity_id', $entityId !== null && $entityId > 0 ? $entityId : null, $entityId !== null && $entityId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $statement->bindValue(':summary', $summary, PDO::PARAM_STR);
    $statement->execute();
}

function result_form_script(): void
{
    ?>
    <script nonce="<?= e(app_csp_nonce()) ?>">
    document.querySelectorAll('form[data-pu-busy]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var button = event.submitter || form.querySelector('[type="submit"]');
            if (!button || button.disabled) {
                return;
            }
            if (button.name) {
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = button.name;
                hidden.value = button.value;
                form.appendChild(hidden);
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
