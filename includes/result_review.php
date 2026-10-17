<?php
declare(strict_types=1);

/*
 * Reviewer comparison of entered figures against an uploaded result sheet. Reviews and
 * discrepancies are append-only records: resolving a discrepancy adds an explanation but never
 * edits the figures. Corrections go through the normal result correction path, which changes
 * the figures fingerprint and so marks the earlier review as out of date.
 */

require_once __DIR__ . '/result_sheets.php';

const REVIEW_STATES = [
    'unreviewed' => ['Not compared', 'cms-badge--warn'],
    'match' => ['Matches sheet', 'cms-badge--ok'],
    'resolved' => ['Discrepancy resolved', 'cms-badge--ok'],
    'discrepancy' => ['Open discrepancy', 'cms-badge--off'],
    'stale' => ['Figures changed since review', 'cms-badge--warn'],
];

function review_can(array $user): bool
{
    return sheet_is_reviewer($user);
}

/**
 * Canonical fingerprint of the entered figures, so a later correction is detectable.
 *
 * @param array<string, mixed> $result
 * @param array<int, ?int> $votes
 */
function review_figures_sha256(array $result, array $votes): string
{
    ksort($votes);
    $figures = [];
    foreach (array_keys(RESULT_FIGURE_FIELDS) as $field) {
        $figures[$field] = $result[$field] === null ? null : (int) $result[$field];
    }
    $pairs = [];
    foreach ($votes as $candidateId => $count) {
        $pairs[] = [(int) $candidateId, $count];
    }

    return hash('sha256', 'em-result-figures-v1|' . json_encode([$figures, $pairs], JSON_THROW_ON_ERROR));
}

function review_field_label(string $field, ?int $candidateId, array $candidateNames): string
{
    if ($field === 'votes' && $candidateId !== null) {
        return 'Votes: ' . ($candidateNames[$candidateId] ?? 'candidate ' . $candidateId);
    }

    return explode(' (', RESULT_FIGURE_FIELDS[$field] ?? $field)[0];
}

/**
 * @return array{state: string, open: int, review: ?array<string, mixed>, current_sha: string}
 */
function review_state(array $result): array
{
    $votes = result_votes((int) $result['id']);
    $current = review_figures_sha256($result, $votes);
    $latest = db()->prepare('SELECT * FROM result_reviews WHERE result_id = :id ORDER BY id DESC LIMIT 1');
    $latest->bindValue(':id', (int) $result['id'], PDO::PARAM_INT);
    $latest->execute();
    $review = $latest->fetch() ?: null;
    $open = db()->prepare("SELECT COUNT(*) FROM result_discrepancies WHERE result_id = :id AND status = 'open'");
    $open->bindValue(':id', (int) $result['id'], PDO::PARAM_INT);
    $open->execute();
    $openCount = (int) $open->fetchColumn();
    if ($openCount > 0) {
        $state = 'discrepancy';
    } elseif ($review === null) {
        $state = 'unreviewed';
    } elseif (!hash_equals((string) $review['figures_sha256'], $current)) {
        $state = 'stale';
    } else {
        $state = (string) $review['outcome'] === 'match' ? 'match' : 'resolved';
    }

    return ['state' => $state, 'open' => $openCount, 'review' => $review, 'current_sha' => $current];
}

/**
 * Review state for every submitted result, keyed by polling unit id.
 *
 * @return array<int, array{state: string, open: int, ward_id: int}>
 */
function review_states_by_unit(): array
{
    $rows = db()->query(
        "SELECT r.*, pu.ward_id
         FROM polling_unit_results r
         INNER JOIN polling_units pu ON pu.id = r.polling_unit_id
         WHERE r.status = 'submitted'"
    )->fetchAll();
    $states = [];
    foreach ($rows as $row) {
        $state = review_state($row);
        $states[(int) $row['polling_unit_id']] = ['state' => $state['state'], 'open' => $state['open'], 'ward_id' => (int) $row['ward_id']];
    }

    return $states;
}

/**
 * @return array<int, array<string, int>> ward id => state counts plus 'open'
 */
function review_summary_by_ward(): array
{
    $summary = [];
    foreach (review_states_by_unit() as $state) {
        $ward = $state['ward_id'];
        $summary[$ward] ??= ['unreviewed' => 0, 'match' => 0, 'resolved' => 0, 'discrepancy' => 0, 'stale' => 0, 'open' => 0];
        $summary[$ward][$state['state']]++;
        $summary[$ward]['open'] += $state['open'];
    }

    return $summary;
}

function review_open_discrepancies(?int $wardId = null): int
{
    $sql = "SELECT COUNT(*) FROM result_discrepancies d
            INNER JOIN polling_unit_results r ON r.id = d.result_id AND r.status = 'submitted'
            INNER JOIN polling_units pu ON pu.id = d.polling_unit_id
            WHERE d.status = 'open'" . ($wardId !== null ? ' AND pu.ward_id = :ward' : '');
    $statement = db()->prepare($sql);
    if ($wardId !== null) {
        $statement->bindValue(':ward', $wardId, PDO::PARAM_INT);
    }
    $statement->execute();

    return (int) $statement->fetchColumn();
}

/**
 * @return list<array<string, mixed>>
 */
function review_history(int $resultId): array
{
    $statement = db()->prepare(
        'SELECT v.*, u.full_name AS reviewer_name, s.version_no
         FROM result_reviews v
         LEFT JOIN users u ON u.id = v.reviewer_id
         LEFT JOIN result_sheet_uploads s ON s.id = v.upload_id
         WHERE v.result_id = :id
         ORDER BY v.id DESC'
    );
    $statement->bindValue(':id', $resultId, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

/**
 * @return list<array<string, mixed>>
 */
function review_discrepancies(int $resultId): array
{
    $statement = db()->prepare(
        'SELECT d.*, u.full_name AS resolved_by_name
         FROM result_discrepancies d
         LEFT JOIN users u ON u.id = d.resolved_by
         WHERE d.result_id = :id
         ORDER BY d.status = \'open\' DESC, d.id DESC'
    );
    $statement->bindValue(':id', $resultId, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function review_clean_note(string $note): string
{
    return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $note)));
}

/**
 * Records one comparison. Every figure must be read from the sheet; a blank sheet value means
 * the figure could not be read and is recorded as a discrepancy.
 *
 * @param array{id: int, role: string} $user
 * @param array<string, mixed> $input
 * @return array{ok: bool, message: string, code: int}
 */
function review_record(array $user, int $unitId, array $input): array
{
    $fail = static fn (string $message, int $code = 422): array => ['ok' => false, 'message' => $message, 'code' => $code];
    if (!review_can($user)) {
        return $fail('Your account cannot review results.', 403);
    }
    $uploadId = filter_var($input['upload_id'] ?? '', FILTER_VALIDATE_INT);
    $upload = is_int($uploadId) && $uploadId > 0 ? sheet_find($uploadId) : null;
    if ($upload === null || (int) $upload['polling_unit_id'] !== $unitId) {
        return $fail('Choose a result sheet uploaded for this polling unit.', 404);
    }
    $check = sheet_verify($upload, (int) $user['id']);
    if (!$check['deliverable']) {
        return $fail('That result sheet failed its integrity check (' . sheet_status_label($check['status']) . '), so it cannot be used for comparison.', 409);
    }
    $note = review_clean_note(is_string($input['note'] ?? null) ? $input['note'] : '');
    $noteLength = function_exists('mb_strlen') ? mb_strlen($note) : strlen($note);
    if ($noteLength > 255) {
        return $fail('Keep the review note to 255 characters.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT * FROM polling_unit_results WHERE polling_unit_id = :unit LIMIT 1 FOR UPDATE');
        $lock->bindValue(':unit', $unitId, PDO::PARAM_INT);
        $lock->execute();
        $result = $lock->fetch();
        if (!is_array($result) || (string) $result['status'] !== 'submitted') {
            $pdo->rollBack();

            return $fail('Only submitted results can be compared with a sheet.', 409);
        }
        $votes = result_votes((int) $result['id']);
        $sha = review_figures_sha256($result, $votes);
        if (!hash_equals($sha, is_string($input['figures_sha256'] ?? null) ? $input['figures_sha256'] : '')) {
            $pdo->rollBack();

            return $fail('The entered figures changed after you opened this page. Reload and compare again.', 409);
        }
        $sheetValues = is_array($input['sheet'] ?? null) ? $input['sheet'] : [];
        $sheetVotes = is_array($sheetValues['votes'] ?? null) ? $sheetValues['votes'] : [];
        $rows = [];
        foreach (array_keys(RESULT_FIGURE_FIELDS) as $field) {
            $rows[] = ['field' => $field, 'candidate' => null, 'entered' => $result[$field] === null ? null : (int) $result[$field], 'raw' => $sheetValues[$field] ?? null];
        }
        foreach ($votes as $candidateId => $count) {
            $rows[] = ['field' => 'votes', 'candidate' => (int) $candidateId, 'entered' => $count, 'raw' => $sheetVotes[$candidateId] ?? $sheetVotes[(string) $candidateId] ?? null];
        }
        $differences = [];
        foreach ($rows as $row) {
            $raw = is_string($row['raw']) ? trim($row['raw']) : '';
            if ($raw === '') {
                $sheetValue = null;
            } else {
                $sheetValue = result_count_value($raw);
                if ($sheetValue === null) {
                    $pdo->rollBack();

                    return $fail('Sheet values must be whole numbers from 0 to 100000. Leave a box blank only if the figure cannot be read.');
                }
            }
            if ($sheetValue === null || $sheetValue !== $row['entered']) {
                $differences[] = $row + ['sheet' => $sheetValue];
            }
        }
        if ($differences !== [] && $noteLength < 5) {
            $pdo->rollBack();

            return $fail('Describe the discrepancy in a note of at least 5 characters.');
        }
        $outcome = $differences === [] ? 'match' : 'discrepancy';
        $insert = $pdo->prepare(
            'INSERT INTO result_reviews (result_id, polling_unit_id, upload_id, reviewer_id, outcome, figures_sha256, note, reviewed_at_utc)
             VALUES (:result, :unit, :upload, :reviewer, :outcome, :sha, :note, :at)'
        );
        $insert->bindValue(':result', (int) $result['id'], PDO::PARAM_INT);
        $insert->bindValue(':unit', $unitId, PDO::PARAM_INT);
        $insert->bindValue(':upload', (int) $upload['id'], PDO::PARAM_INT);
        $insert->bindValue(':reviewer', (int) $user['id'], PDO::PARAM_INT);
        $insert->bindValue(':outcome', $outcome, PDO::PARAM_STR);
        $insert->bindValue(':sha', $sha, PDO::PARAM_STR);
        $insert->bindValue(':note', $note === '' ? null : $note, $note === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $insert->bindValue(':at', sheet_utc_now(), PDO::PARAM_STR);
        $insert->execute();
        $reviewId = (int) $pdo->lastInsertId();
        $discrepancy = $pdo->prepare(
            'INSERT INTO result_discrepancies (review_id, result_id, polling_unit_id, field, candidate_id, entered_value, sheet_value)
             VALUES (:review, :result, :unit, :field, :candidate, :entered, :sheet)'
        );
        foreach ($differences as $row) {
            $discrepancy->bindValue(':review', $reviewId, PDO::PARAM_INT);
            $discrepancy->bindValue(':result', (int) $result['id'], PDO::PARAM_INT);
            $discrepancy->bindValue(':unit', $unitId, PDO::PARAM_INT);
            $discrepancy->bindValue(':field', $row['field'], PDO::PARAM_STR);
            $discrepancy->bindValue(':candidate', $row['candidate'], $row['candidate'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $discrepancy->bindValue(':entered', $row['entered'], $row['entered'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $discrepancy->bindValue(':sheet', $row['sheet'], $row['sheet'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $discrepancy->execute();
        }
        result_audit(
            (int) $user['id'],
            $outcome === 'match' ? 'review_match' : 'review_discrepancy',
            (int) $result['id'],
            'Compared polling unit ' . $unitId . ' with sheet record ' . (int) $upload['id'] . ': '
                . ($outcome === 'match' ? 'all figures match.' : count($differences) . ' discrepanc' . (count($differences) === 1 ? 'y' : 'ies') . ' recorded (review ' . $reviewId . ').')
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return $outcome === 'match'
        ? ['ok' => true, 'message' => 'Comparison recorded: every figure matches the sheet.', 'code' => 200]
        : ['ok' => true, 'message' => count($differences) . ' discrepanc' . (count($differences) === 1 ? 'y' : 'ies') . ' recorded. Ward collation is blocked until each one is resolved.', 'code' => 200];
}

/**
 * Resolves one discrepancy with an explanation. Figures are not changed here.
 *
 * @param array{id: int, role: string} $user
 * @return array{ok: bool, message: string, code: int}
 */
function review_resolve(array $user, int $unitId, int $discrepancyId, string $note): array
{
    $fail = static fn (string $message, int $code = 422): array => ['ok' => false, 'message' => $message, 'code' => $code];
    if (!review_can($user)) {
        return $fail('Your account cannot review results.', 403);
    }
    $note = review_clean_note($note);
    $length = function_exists('mb_strlen') ? mb_strlen($note) : strlen($note);
    if ($length < 10 || $length > 200) {
        return $fail('Explain the resolution in 10 to 200 characters.');
    }
    $update = db()->prepare(
        "UPDATE result_discrepancies
         SET status = 'resolved', resolved_by = :by, resolved_at_utc = :at, resolution_note = :note
         WHERE id = :id AND polling_unit_id = :unit AND status = 'open'"
    );
    $update->bindValue(':by', (int) $user['id'], PDO::PARAM_INT);
    $update->bindValue(':at', sheet_utc_now(), PDO::PARAM_STR);
    $update->bindValue(':note', $note, PDO::PARAM_STR);
    $update->bindValue(':id', $discrepancyId, PDO::PARAM_INT);
    $update->bindValue(':unit', $unitId, PDO::PARAM_INT);
    $update->execute();
    if ($update->rowCount() !== 1) {
        return $fail('That discrepancy was not found or is already resolved.', 404);
    }
    $resultId = (int) db()->query('SELECT result_id FROM result_discrepancies WHERE id = ' . $discrepancyId)->fetchColumn();
    result_audit((int) $user['id'], 'resolve_discrepancy', $resultId, 'Resolved discrepancy ' . $discrepancyId . ' for polling unit ' . $unitId . '. ' . $note);

    return ['ok' => true, 'message' => 'Discrepancy resolved. The entered figures were not changed; correct them from the result page if they are wrong.', 'code' => 200];
}

/**
 * @return list<array<string, mixed>>
 */
function review_queue(string $filter): array
{
    $rows = db()->query(
        "SELECT r.*, pu.unit_code, pu.name AS unit_name, w.name AS ward_name,
                (SELECT COUNT(*) FROM result_sheet_uploads s WHERE s.polling_unit_id = pu.id) AS sheet_count
         FROM polling_unit_results r
         INNER JOIN polling_units pu ON pu.id = r.polling_unit_id
         INNER JOIN wards w ON w.id = pu.ward_id
         WHERE r.status = 'submitted'
         ORDER BY w.name, pu.unit_code"
    )->fetchAll();
    $queue = [];
    foreach ($rows as $row) {
        $state = review_state($row);
        if ($filter !== 'all' && $state['state'] !== $filter) {
            continue;
        }
        $queue[] = $row + ['review_state' => $state['state'], 'open' => $state['open']];
    }

    return $queue;
}
