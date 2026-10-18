<?php
declare(strict_types=1);

/*
 * Offline-first result submission API. A device queues a submission under a random
 * submission_id and may send it many times; the server records each accepted submission once
 * and replays the original outcome for retries. Every send is checked against the current
 * database: role, assignment, active unit, candidate set, the existing result rules, and the
 * agent lock on submitted results. Refusals are never stored, so a retry is always judged by
 * the rules in force at that moment.
 */

require_once __DIR__ . '/agent_access.php';
require_once __DIR__ . '/result_review.php';

const SUBMISSION_SCHEMA_VERSION = 1;
const SUBMISSION_MAX_BODY = 16384;
const SUBMISSION_RATE_LIMITS = ['submit' => [30, 60], 'status' => [60, 60]];
const SUBMISSION_UUID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';
const SUBMISSION_NOTICE = 'Independent monitoring result (provisional). Not an official INEC result.';

/**
 * @param array<string, mixed> $body
 * @param array<string, string> $headers
 */
function submission_respond(int $status, array $body, array $headers = []): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
    }
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function submission_error(int $status, string $code, string $message, array $extra = [], array $headers = []): never
{
    submission_respond($status, ['ok' => false, 'code' => $code, 'message' => $message] + $extra, $headers);
}

function submission_schema_ready(): bool
{
    try {
        db()->query('SELECT 1 FROM result_submissions LIMIT 1');

        return result_review_schema_ready();
    } catch (Throwable $exception) {
        return false;
    }
}

function submission_utc_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
}

/**
 * Sliding-window limit kept in the session, keyed by bucket and authenticated user id
 * so opening a new session does not reset the account's budget.
 * Returns the seconds to wait, or null when the request may proceed.
 */
function submission_rate_limited(string $bucket, int $userId = 0): ?int
{
    [$max, $window] = SUBMISSION_RATE_LIMITS[$bucket];
    $now = time();
    $key = 'api_rate_' . $bucket . '_' . max(0, $userId);
    $hits = array_values(array_filter(
        is_array($_SESSION[$key] ?? null) ? $_SESSION[$key] : [],
        static fn ($t): bool => is_int($t) && $t > $now - $window
    ));
    if (count($hits) >= $max) {
        $_SESSION[$key] = $hits;

        return max(1, $hits[0] + $window - $now);
    }
    $hits[] = $now;
    $_SESSION[$key] = $hits;

    return null;
}

/**
 * Shared request gate for both endpoints: database, session, role, CSRF (for writes), rate limit.
 *
 * @return array{id: int, username: string, email: string, full_name: string, role: string}
 */
function submission_require_agent(string $bucket, bool $checkCsrf): array
{
    try {
        auth_boot();
        $user = auth_user();
    } catch (Throwable $exception) {
        error_log('Submission API unavailable: ' . $exception->getMessage());
        submission_error(503, 'temporarily_unavailable', 'The server is temporarily unavailable. Your result stays queued on this device.', [], ['Retry-After' => '30']);
    }
    if ($user === null) {
        submission_error(401, 'auth_required', 'Your session has ended. Sign in again to send queued results.');
    }
    if ($user['role'] !== 'polling_agent') {
        submission_error(403, 'role_forbidden', 'Only polling agents can submit results from the device queue.');
    }
    if ($checkCsrf) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!csrf_verify(is_string($token) ? $token : null)) {
            submission_error(403, 'csrf_invalid', 'The security token expired. The device will fetch a new one and retry.');
        }
    }
    $wait = submission_rate_limited($bucket, (int) $user['id']);
    if ($wait !== null) {
        submission_error(429, 'rate_limited', 'Too many requests. The device will retry shortly.', ['retry_after' => $wait], ['Retry-After' => (string) $wait]);
    }
    if (!submission_schema_ready()) {
        submission_error(503, 'temporarily_unavailable', 'Result submission is not available on the server yet.', [], ['Retry-After' => '60']);
    }

    return $user;
}

/**
 * @param list<array{id: int}> $candidates
 */
function submission_candidate_fingerprint(array $candidates): string
{
    $ids = array_map(static fn (array $c): int => (int) $c['id'], $candidates);
    sort($ids);

    return hash('sha256', 'em-candidates-v1|' . json_encode($ids));
}

/**
 * @param array<string, int> $figures
 * @param array<int, int> $votes
 */
function submission_payload_sha256(int $unitId, array $figures, array $votes): string
{
    ksort($votes);
    $ordered = [];
    foreach (array_keys(RESULT_FIGURE_FIELDS) as $field) {
        $ordered[] = $figures[$field];
    }
    $pairs = [];
    foreach ($votes as $candidateId => $count) {
        $pairs[] = [(int) $candidateId, $count];
    }

    return hash('sha256', 'em-offline-submission-v1|' . json_encode([$unitId, $ordered, $pairs]));
}

/**
 * Structural validation only. Values are later checked by the existing result rules.
 *
 * @param mixed $json
 * @return array{submission_id: string, polling_unit_id: int, client_created_at: ?string, figures: array<string, int>, votes: array<int, int>}
 */
function submission_parse(mixed $json): array
{
    if (!is_array($json) || array_is_list($json)) {
        submission_error(400, 'bad_request', 'The submission is not a valid JSON object.');
    }
    $allowed = ['schema_version', 'submission_id', 'polling_unit_id', 'client_created_at', 'config_fingerprint', 'figures', 'votes'];
    if (array_diff(array_keys($json), $allowed) !== []) {
        submission_error(400, 'bad_request', 'The submission contains unexpected fields.');
    }
    if (($json['schema_version'] ?? null) !== SUBMISSION_SCHEMA_VERSION) {
        submission_error(400, 'unsupported_schema', 'This app version is out of date. Reload the page while online.');
    }
    $id = $json['submission_id'] ?? null;
    if (!is_string($id) || !preg_match(SUBMISSION_UUID_PATTERN, $id)) {
        submission_error(400, 'bad_request', 'The submission ID is missing or invalid.');
    }
    $unitId = $json['polling_unit_id'] ?? null;
    if (!is_int($unitId) || $unitId < 1 || $unitId > 2147483647) {
        submission_error(400, 'bad_request', 'The polling unit is missing or invalid.');
    }
    $created = $json['client_created_at'] ?? null;
    if ($created !== null && (!is_string($created) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,3})?Z\z/', $created))) {
        submission_error(400, 'bad_request', 'The device time stamp is invalid.');
    }
    $figuresIn = $json['figures'] ?? null;
    $votesIn = $json['votes'] ?? null;
    if (!is_array($figuresIn) || !is_array($votesIn) || array_diff(array_keys($figuresIn), array_keys(RESULT_FIGURE_FIELDS)) !== []) {
        submission_error(400, 'bad_request', 'The result figures are missing or malformed.');
    }
    $fieldErrors = [];
    $figures = [];
    foreach (array_keys(RESULT_FIGURE_FIELDS) as $field) {
        $value = $figuresIn[$field] ?? null;
        if (!is_int($value) || $value < 0 || $value > 100000) {
            $fieldErrors[$field] = 'Must be a whole number from 0 to 100000.';
            continue;
        }
        $figures[$field] = $value;
    }
    $votes = [];
    foreach ($votesIn as $candidateId => $value) {
        if (!preg_match('/\A[1-9][0-9]{0,9}\z/', (string) $candidateId)) {
            submission_error(400, 'bad_request', 'The candidate list is malformed.');
        }
        if (!is_int($value) || $value < 0 || $value > 100000) {
            $fieldErrors['votes'] = 'Every candidate needs a whole-number vote count from 0 to 100000.';
            continue;
        }
        $votes[(int) $candidateId] = $value;
    }
    if (count($votes) > 200) {
        submission_error(400, 'bad_request', 'The candidate list is too long.');
    }
    if ($fieldErrors !== []) {
        submission_error(422, 'validation_failed', 'Some figures are not valid. Correct them and submit again.', ['fields' => $fieldErrors]);
    }

    return ['submission_id' => $id, 'polling_unit_id' => $unitId, 'client_created_at' => $created, 'figures' => $figures, 'votes' => $votes];
}

/**
 * @param array<string, mixed> $row
 */
function submission_replay(array $row, int $userId, string $payloadSha): never
{
    if ((int) $row['user_id'] !== $userId) {
        submission_error(409, 'submission_id_conflict', 'This submission ID is already in use. Edit and resubmit the result to create a new submission.');
    }
    if (!hash_equals((string) $row['payload_sha256'], $payloadSha)) {
        submission_error(409, 'submission_id_reused', 'This submission ID was already used for different figures. The server kept the original; edit and resubmit to send a new submission.');
    }
    $body = json_decode((string) $row['response_json'], true);
    if (!is_array($body)) {
        submission_error(500, 'server_error', 'The stored outcome could not be read. Try again later.');
    }
    $body['replayed'] = true;
    $body['current'] = submission_current_state($row);
    submission_respond(200, $body);
}

function submission_find(string $submissionId, bool $lock = false): ?array
{
    $statement = db()->prepare('SELECT * FROM result_submissions WHERE submission_id = :id LIMIT 1' . ($lock ? ' LOCK IN SHARE MODE' : ''));
    $statement->bindValue(':id', $submissionId, PDO::PARAM_STR);
    $statement->execute();
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

/**
 * The server's present view of an accepted submission, assigned only from server data.
 *
 * @param array<string, mixed> $row
 * @return array{state: string, result_status: ?string, figures_changed: bool}
 */
function submission_current_state(array $row): array
{
    $statement = db()->prepare('SELECT * FROM polling_unit_results WHERE id = :id LIMIT 1');
    $statement->bindValue(':id', (int) $row['result_id'], PDO::PARAM_INT);
    $statement->execute();
    $result = $statement->fetch();
    if (!is_array($result)) {
        return ['state' => 'received', 'result_status' => null, 'figures_changed' => false];
    }
    $review = review_state($result);
    $changed = !hash_equals((string) $row['figures_sha256'], $review['current_sha']);
    $state = in_array($review['state'], ['match', 'resolved'], true) && !$changed ? 'accepted' : 'under_review';

    return ['state' => $state, 'result_status' => (string) $result['status'], 'figures_changed' => $changed];
}

/**
 * @param array{id: int, role: string} $user
 * @param array{submission_id: string, polling_unit_id: int, client_created_at: ?string, figures: array<string, int>, votes: array<int, int>} $data
 */
function submission_process(array $user, array $data): never
{
    $userId = (int) $user['id'];
    $unitId = $data['polling_unit_id'];
    $payloadSha = submission_payload_sha256($unitId, $data['figures'], $data['votes']);
    $existing = submission_find($data['submission_id']);
    if ($existing !== null) {
        submission_replay($existing, $userId, $payloadSha);
    }

    $pdo = db();
    $pdo->beginTransaction();
    $refuse = static function (int $status, string $code, string $message, array $extra = []) use ($pdo): never {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        submission_error($status, $code, $message, $extra);
    };
    try {
        $unitLock = $pdo->prepare('SELECT id, unit_code, is_active, agent_user_id FROM polling_units WHERE id = :id FOR UPDATE');
        $unitLock->bindValue(':id', $unitId, PDO::PARAM_INT);
        $unitLock->execute();
        $unit = $unitLock->fetch();
        $again = submission_find($data['submission_id'], true);
        if ($again !== null) {
            $pdo->rollBack();
            submission_replay($again, $userId, $payloadSha);
        }
        if (!is_array($unit) || (int) ($unit['agent_user_id'] ?? 0) !== $userId) {
            $refuse(403, 'unit_not_assigned', 'This polling unit is not assigned to your account any more. The result was kept on this device; contact your coordinator.');
        }
        if ((int) $unit['is_active'] !== 1) {
            $refuse(409, 'unit_inactive', 'This polling unit has been deactivated, so results cannot be submitted for it. The result was kept on this device; contact your coordinator.');
        }
        $resultLock = $pdo->prepare('SELECT id, status FROM polling_unit_results WHERE polling_unit_id = :id LIMIT 1 FOR UPDATE');
        $resultLock->bindValue(':id', $unitId, PDO::PARAM_INT);
        $resultLock->execute();
        $current = $resultLock->fetch();
        if (is_array($current) && (string) $current['status'] === 'submitted') {
            $refuse(409, 'already_submitted', 'A result for this polling unit was already submitted, so this one was not applied. Contact your coordinator if it needs correcting.');
        }
        $candidates = result_candidates_for_sheet(is_array($current) ? (int) $current['id'] : null);
        $expected = array_map(static fn (array $c): int => (int) $c['id'], $candidates);
        $given = array_keys($data['votes']);
        sort($expected);
        sort($given);
        if ($expected === [] || $expected !== $given) {
            $refuse(409, 'candidates_changed', 'The candidate list changed after this result was entered. Reload while online and enter the result again.', ['config_fingerprint' => submission_candidate_fingerprint($candidates)]);
        }
        $checked = result_validate($data['figures'] + ['votes' => $data['votes']], $candidates, true);
        if ($checked['errors'] !== []) {
            $refuse(422, 'validation_failed', $checked['errors']['form'] ?? 'Some figures are not valid. Correct them and submit again.', ['fields' => array_diff_key($checked['errors'], ['form' => true])]);
        }
        $saved = result_save($unitId, $checked['data'], $user, true);
        if (!$saved['ok'] || !isset($saved['result_id'])) {
            $refuse(409, 'not_editable', 'This result can no longer be changed from your account.');
        }
        $resultId = $saved['result_id'];
        $receivedAt = submission_utc_now();
        $result = $pdo->query('SELECT * FROM polling_unit_results WHERE id = ' . $resultId)->fetch();
        $figuresSha = review_figures_sha256($result, result_votes($resultId));
        $body = [
            'ok' => true,
            'code' => 'received',
            'submission_id' => $data['submission_id'],
            'polling_unit_id' => $unitId,
            'received_at' => str_replace(' ', 'T', $receivedAt) . 'Z',
            'result_status' => 'submitted',
            'state' => 'under_review',
            'replayed' => false,
            'message' => 'Received by the server and recorded. It now awaits review. ' . SUBMISSION_NOTICE,
        ];
        $insert = $pdo->prepare(
            'INSERT INTO result_submissions
                (submission_id, user_id, polling_unit_id, result_id, schema_version, payload_sha256, figures_sha256, client_created_at, received_at_utc, response_json)
             VALUES (:sid, :user, :unit, :result, :schema, :payload, :figures, :created, :received, :response)'
        );
        $insert->bindValue(':sid', $data['submission_id'], PDO::PARAM_STR);
        $insert->bindValue(':user', $userId, PDO::PARAM_INT);
        $insert->bindValue(':unit', $unitId, PDO::PARAM_INT);
        $insert->bindValue(':result', $resultId, PDO::PARAM_INT);
        $insert->bindValue(':schema', SUBMISSION_SCHEMA_VERSION, PDO::PARAM_INT);
        $insert->bindValue(':payload', $payloadSha, PDO::PARAM_STR);
        $insert->bindValue(':figures', $figuresSha, PDO::PARAM_STR);
        $insert->bindValue(':created', $data['client_created_at'], $data['client_created_at'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $insert->bindValue(':received', $receivedAt, PDO::PARAM_STR);
        $insert->bindValue(':response', json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PDO::PARAM_STR);
        $insert->execute();
        result_audit($userId, 'offline_submit', $resultId, 'Received device submission ' . $data['submission_id'] . ' for polling unit ' . $unitId . ($data['client_created_at'] !== null ? ' (saved on device ' . $data['client_created_at'] . ')' : '') . '.');
        $pdo->commit();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception->getCode() === '23000') {
            $row = submission_find($data['submission_id']);
            if ($row !== null) {
                submission_replay($row, $userId, $payloadSha);
            }
        }
        error_log('Submission failed: ' . $exception->getMessage());
        submission_error(503, 'temporarily_unavailable', 'The server could not record the result just now. It stays queued on this device.', [], ['Retry-After' => '15']);
    }

    submission_respond(201, $body);
}

/**
 * Device sync panel for polling agents (driven by assets/js/offline-sync.js). The page must
 * also contain a CSRF form field; the script reads it into memory and never stores it.
 *
 * @param array{id: int} $user
 */
function submission_sync_panel(array $user): void
{
    ?>
    <section id="em-sync" class="em-sync cms-card" hidden aria-label="Results saved on this device"
        data-user-id="<?= e((string) $user['id']) ?>"
        data-submit-url="<?= e(url('results/api-submit.php')) ?>"
        data-status-url="<?= e(url('results/api-status.php')) ?>"
        data-entry-url="<?= e(url('results/entry.php')) ?>"
        data-sw-url="<?= e(url('results/offline-sw.php')) ?>"
        data-sw-scope="<?= e(url('results/entry.php')) ?>"
        data-shell-url="<?= e(url('results/offline-shell.php')) ?>">
        <div class="cms-card__body">
            <div class="em-sync__bar">
                <span class="em-sync__conn" data-em-conn data-conn="online" role="status">Online</span>
                <span class="em-sync__stat"><strong data-em-pending>0</strong> pending</span>
                <span class="em-sync__stat"><strong data-em-attention>0</strong> need attention</span>
                <span class="em-sync__stat" data-em-last>No server sync yet on this device</span>
                <button type="button" class="cms-btn cms-btn--ghost cms-btn--sm em-sync__now" data-em-sync-now>Sync now</button>
            </div>
            <p class="em-sync__msg" data-em-sync-msg role="status" aria-live="polite"></p>
            <details class="em-sync__details">
                <summary>Results saved on this device (<span data-em-total>0</span>)</summary>
                <ul class="em-sync__items" data-em-list></ul>
            </details>
            <p class="em-sync__offline" data-em-offline-ready role="status"></p>
            <p class="em-sync__notice"><?= e(SUBMISSION_NOTICE) ?></p>
        </div>
    </section>
    <?php
}

/**
 * Units, candidates, and the server's current view of this agent's own submissions.
 *
 * @param array{id: int} $user
 * @param list<string> $ids
 * @return array<string, mixed>
 */
function submission_snapshot(array $user, array $ids): array
{
    $units = [];
    foreach (agent_assigned_units((int) $user['id']) as $unit) {
        $candidates = result_candidates_for_sheet($unit['result_id'] !== null ? (int) $unit['result_id'] : null);
        $units[] = [
            'id' => (int) $unit['id'],
            'code' => (string) $unit['unit_code'],
            'name' => (string) $unit['name'],
            'ward' => (string) $unit['ward_name'],
            'result_status' => $unit['status'] !== null ? (string) $unit['status'] : null,
            'candidates' => array_map(static fn (array $c): array => ['id' => $c['id'], 'party' => $c['party'], 'name' => $c['full_name']], $candidates),
            'config_fingerprint' => submission_candidate_fingerprint($candidates),
        ];
    }
    $submissions = [];
    if ($ids !== []) {
        $placeholders = implode(',', array_map(static fn (int $i): string => ':s' . $i, array_keys($ids)));
        $statement = db()->prepare('SELECT * FROM result_submissions WHERE user_id = :user AND submission_id IN (' . $placeholders . ')');
        $statement->bindValue(':user', (int) $user['id'], PDO::PARAM_INT);
        foreach ($ids as $index => $id) {
            $statement->bindValue(':s' . $index, $id, PDO::PARAM_STR);
        }
        $statement->execute();
        foreach ($statement as $row) {
            $submissions[(string) $row['submission_id']] = [
                'received_at' => str_replace(' ', 'T', (string) $row['received_at_utc']) . 'Z',
                'polling_unit_id' => (int) $row['polling_unit_id'],
            ] + submission_current_state($row);
        }
    }

    return [
        'ok' => true,
        'user_id' => (int) $user['id'],
        'csrf_token' => csrf_token(),
        'server_time' => str_replace(' ', 'T', submission_utc_now()) . 'Z',
        'units' => $units,
        'submissions' => $submissions,
        'notice' => SUBMISSION_NOTICE,
    ];
}
