<?php
declare(strict_types=1);

require_once __DIR__ . '/collation.php';
require_once __DIR__ . '/scope.php';

const REPORT_READERS = ['super_admin', 'admin', 'supervisor', 'collation_officer', 'viewer'];

/**
 * @return array{id: int, username: string, email: string, full_name: string, role: string, last_login_at?: ?string}
 */
function report_require_user(): array
{
    $user = auth_user();
    if ($user === null) {
        redirect_to('portal/login.php');
    }
    if (!in_array($user['role'], REPORT_READERS, true)) {
        render_forbidden($user);
        exit;
    }

    return $user;
}

function report_schema_ready(): bool
{
    try {
        $pdo = db();
        $pdo->query('SELECT 1 FROM wards LIMIT 1');
        $pdo->query('SELECT 1 FROM polling_units LIMIT 1');
        $pdo->query('SELECT 1 FROM candidates LIMIT 1');
        $pdo->query('SELECT 1 FROM polling_unit_results LIMIT 1');
        $pdo->query('SELECT 1 FROM polling_unit_result_votes LIMIT 1');

        return true;
    } catch (Throwable $exception) {
        error_log($exception->getMessage());

        return false;
    }
}

/**
 * @param array{id: int, username: string, email: string, full_name: string, role: string, last_login_at?: ?string} $user
 */
function report_render_missing_schema(array $user): void
{
    render_shell_start('Reports', 'reports', $user);
    ?>
    <header class="cms-page__header">
        <div>
            <h1 class="cms-page__title">Reports</h1>
            <p class="cms-page__subtitle">The result tables are not available yet.</p>
        </div>
    </header>
    <article class="cms-card">
        <div class="cms-card__body">
            <p>Apply the results migration, then reload this page. Reports do not create polling units or candidates.</p>
        </div>
    </article>
    <?php
    render_shell_end();
}

/**
 * @param array{id: int, role: string} $user
 * @return array<string, int|bool>
 */
function report_overview(array $user): array
{
    $unitScope = scope_sql_units($user, 'pu');
    $wardScope = scope_sql_filter_wards($user, 'w');
    $register = db()->prepare(
        "SELECT COUNT(*) AS units,
                COALESCE(SUM(is_active = 1), 0) AS active_units,
                COALESCE(SUM(agent_user_id IS NOT NULL), 0) AS assigned_units
         FROM polling_units pu
         WHERE {$unitScope['sql']}"
    );
    foreach ($unitScope['params'] as $key => $value) {
        $register->bindValue(':' . $key, $value, PDO::PARAM_INT);
    }
    $register->execute();
    $registerRow = $register->fetch();
    $progress = db()->prepare(
        "SELECT COALESCE(SUM(r.status = 'submitted'), 0) AS submitted,
                COALESCE(SUM(r.status = 'draft'), 0) AS drafts,
                COALESCE(SUM(pu.is_active = 1 AND (r.id IS NULL OR r.status <> 'submitted')), 0) AS missing,
                COALESCE(SUM(CASE WHEN r.status = 'submitted' THEN r.accredited_voters ELSE 0 END), 0) AS accredited,
                COALESCE(SUM(CASE WHEN r.status = 'submitted' THEN r.rejected_ballots ELSE 0 END), 0) AS rejected
         FROM polling_units pu
         LEFT JOIN polling_unit_results r ON r.polling_unit_id = pu.id
         WHERE {$unitScope['sql']}"
    );
    foreach ($unitScope['params'] as $key => $value) {
        $progress->bindValue(':' . $key, $value, PDO::PARAM_INT);
    }
    $progress->execute();
    $progressRow = $progress->fetch();
    $wardCount = db()->prepare('SELECT COUNT(*) FROM wards w WHERE ' . $wardScope['sql']);
    foreach ($wardScope['params'] as $key => $value) {
        $wardCount->bindValue(':' . $key, $value, PDO::PARAM_INT);
    }
    $wardCount->execute();
    if (!is_array($registerRow)) {
        $registerRow = [];
    }
    if (!is_array($progressRow)) {
        $progressRow = [];
    }

    return [
        'wards' => (int) $wardCount->fetchColumn(),
        'units' => (int) ($registerRow['units'] ?? 0),
        'active_units' => (int) ($registerRow['active_units'] ?? 0),
        'assigned_units' => (int) ($registerRow['assigned_units'] ?? 0),
        'submitted' => (int) ($progressRow['submitted'] ?? 0),
        'drafts' => (int) ($progressRow['drafts'] ?? 0),
        'missing' => (int) ($progressRow['missing'] ?? 0),
        'accredited' => (int) ($progressRow['accredited'] ?? 0),
        'rejected' => (int) ($progressRow['rejected'] ?? 0),
        'valid_votes' => report_submitted_valid_votes($user),
        'candidates' => (int) db()->query('SELECT COUNT(*) FROM candidates WHERE is_active = 1')->fetchColumn(),
        'collation_ready' => collation_schema_ready(),
    ];
}

/**
 * @param array{id: int, role: string} $user
 */
function report_submitted_valid_votes(array $user): int
{
    $scope = scope_sql_units($user, 'pu');
    $statement = db()->prepare(
        "SELECT COALESCE(SUM(v.votes), 0)
         FROM polling_unit_result_votes v
         INNER JOIN polling_unit_results r ON r.id = v.result_id AND r.status = 'submitted'
         INNER JOIN polling_units pu ON pu.id = r.polling_unit_id
         WHERE {$scope['sql']}"
    );
    foreach ($scope['params'] as $key => $value) {
        $statement->bindValue(':' . $key, $value, PDO::PARAM_INT);
    }
    $statement->execute();

    return (int) $statement->fetchColumn();
}

/**
 * @param array{id: int, role: string} $user
 * @return list<array<string, int|string>>
 */
function report_progress(array $user): array
{
    $scope = scope_sql_filter_wards($user, 'w');
    $unitScope = scope_sql_units($user, 'pu');
    $statement = db()->prepare(
        "SELECT w.id, w.name,
                COUNT(pu.id) AS units,
                COALESCE(SUM(pu.is_active = 1), 0) AS active_units,
                COALESCE(SUM(r.status = 'submitted'), 0) AS submitted,
                COALESCE(SUM(r.status = 'draft'), 0) AS drafts,
                COALESCE(SUM(pu.is_active = 1 AND (r.id IS NULL OR r.status <> 'submitted')), 0) AS missing,
                COALESCE(SUM(CASE WHEN r.status = 'submitted' THEN r.accredited_voters ELSE 0 END), 0) AS accredited,
                COALESCE(SUM(CASE WHEN r.status = 'submitted' THEN r.rejected_ballots ELSE 0 END), 0) AS rejected,
                COALESCE(SUM(CASE WHEN r.status = 'submitted' THEN vv.votes ELSE 0 END), 0) AS valid_votes
         FROM wards w
         LEFT JOIN polling_units pu ON pu.ward_id = w.id AND {$unitScope['sql']}
         LEFT JOIN polling_unit_results r ON r.polling_unit_id = pu.id
         LEFT JOIN (
            SELECT result_id, SUM(votes) AS votes
            FROM polling_unit_result_votes
            GROUP BY result_id
         ) vv ON vv.result_id = r.id
         WHERE {$scope['sql']}
         GROUP BY w.id, w.name
         ORDER BY w.name"
    );
    $params = array_merge($unitScope['params'], $scope['params']);
    foreach ($params as $key => $value) {
        $statement->bindValue(':' . $key, $value, PDO::PARAM_INT);
    }
    $statement->execute();
    $rows = [];
    foreach ($statement as $row) {
        $rows[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'units' => (int) $row['units'],
            'active_units' => (int) $row['active_units'],
            'submitted' => (int) $row['submitted'],
            'drafts' => (int) $row['drafts'],
            'missing' => (int) $row['missing'],
            'accredited' => (int) $row['accredited'],
            'rejected' => (int) $row['rejected'],
            'valid_votes' => (int) $row['valid_votes'],
        ];
    }

    return $rows;
}

/**
 * @param array{id: int, role: string} $user
 * @return array{candidates: list<array{id: int, party: string, full_name: string}>, wards: list<array{id: int, name: string, votes: array<int, int>}>, totals: array<int, int>, scoped: bool}
 */
function report_scores(array $user): array
{
    $unitScope = scope_sql_units($user, 'pu');
    $wardScope = scope_sql_filter_wards($user, 'w');
    $candidateRows = db()->query(
        "SELECT c.id, c.party, c.full_name
         FROM candidates c
         WHERE c.is_active = 1
            OR EXISTS (
                SELECT 1
                FROM polling_unit_result_votes v
                INNER JOIN polling_unit_results r ON r.id = v.result_id AND r.status = 'submitted'
                WHERE v.candidate_id = c.id
            )
         ORDER BY c.sort_order, c.party, c.full_name"
    )->fetchAll();
    $candidates = [];
    $totals = [];
    foreach ($candidateRows as $row) {
        $id = (int) $row['id'];
        $candidates[] = [
            'id' => $id,
            'party' => (string) $row['party'],
            'full_name' => (string) $row['full_name'],
        ];
        $totals[$id] = 0;
    }
    $voteStmt = db()->prepare(
        "SELECT pu.ward_id, v.candidate_id, SUM(v.votes) AS votes
         FROM polling_unit_result_votes v
         INNER JOIN polling_unit_results r ON r.id = v.result_id AND r.status = 'submitted'
         INNER JOIN polling_units pu ON pu.id = r.polling_unit_id
         WHERE {$unitScope['sql']}
         GROUP BY pu.ward_id, v.candidate_id"
    );
    foreach ($unitScope['params'] as $key => $value) {
        $voteStmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
    }
    $voteStmt->execute();
    $byWard = [];
    foreach ($voteStmt as $row) {
        $wardId = (int) $row['ward_id'];
        $candidateId = (int) $row['candidate_id'];
        $votes = (int) $row['votes'];
        $byWard[$wardId][$candidateId] = $votes;
        if (isset($totals[$candidateId])) {
            $totals[$candidateId] += $votes;
        }
    }
    $wardStmt = db()->prepare('SELECT id, name FROM wards w WHERE ' . $wardScope['sql'] . ' ORDER BY name');
    foreach ($wardScope['params'] as $key => $value) {
        $wardStmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
    }
    $wardStmt->execute();
    $wards = [];
    foreach ($wardStmt as $row) {
        $wardId = (int) $row['id'];
        $votes = [];
        foreach ($candidates as $candidate) {
            $votes[$candidate['id']] = (int) ($byWard[$wardId][$candidate['id']] ?? 0);
        }
        $wards[] = [
            'id' => $wardId,
            'name' => (string) $row['name'],
            'votes' => $votes,
        ];
    }

    return [
        'candidates' => $candidates,
        'wards' => $wards,
        'totals' => $totals,
        'scoped' => !scope_is_org_wide($user),
    ];
}

/**
 * @param array{id: int, role: string} $user
 * @return array{available: bool, wards: list<array<string, mixed>>, constituency: ?array<string, mixed>, stale: int, ready: bool}
 */
function report_collation(array $user): array
{
    if (!collation_schema_ready()) {
        return [
            'available' => false,
            'wards' => [],
            'constituency' => null,
            'stale' => 0,
            'ready' => false,
        ];
    }
    $wardRows = collation_ward_rows($user);
    $rows = [];
    $stale = 0;
    $ready = true;
    foreach ($wardRows as $ward) {
        $state = 'Open';
        if ($ward['collation_id'] !== null) {
            $snapshot = collation_snapshot_votes((int) $ward['collation_id']);
            $live = collation_live_votes((int) $ward['id']);
            $stored = db()->prepare('SELECT accredited_voters, rejected_ballots FROM ward_collations WHERE id = :id');
            $stored->bindValue(':id', (int) $ward['collation_id'], PDO::PARAM_INT);
            $stored->execute();
            $figures = $stored->fetch();
            $isStale = !is_array($figures)
                || collation_votes_differ($snapshot, $live)
                || (int) $figures['accredited_voters'] !== $ward['accredited_voters']
                || (int) $figures['rejected_ballots'] !== $ward['rejected_ballots'];
            $state = $isStale ? 'Stale' : 'Collated';
            if ($isStale) {
                $stale++;
            }
        } else {
            $ready = false;
        }
        $ward['state'] = $state;
        $rows[] = $ward;
    }

    return [
        'available' => true,
        'wards' => $rows,
        'constituency' => scope_is_org_wide($user) ? collation_constituency_record() : null,
        'stale' => $stale,
        'ready' => $ready && $wardRows !== [],
    ];
}

/**
 * @param list<string|int> $cells
 */
function report_csv_cell(string|int $value): string
{
    $text = (string) $value;
    if ($text !== '' && in_array($text[0], ['=', '+', '-', '@'], true)) {
        return "'" . $text;
    }

    return $text;
}

/**
 * @param list<string> $header
 * @param list<list<string|int>> $rows
 */
function report_send_csv(string $filename, array $header, array $rows): never
{
    if (!preg_match('/\Aohaji-egbema-[a-z-]+\.csv\z/', $filename)) {
        $filename = 'ohaji-egbema-report.csv';
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'wb');
    if ($out === false) {
        http_response_code(500);
        exit;
    }
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header);
    foreach ($rows as $row) {
        $line = [];
        foreach ($row as $cell) {
            $line[] = report_csv_cell($cell);
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

/**
 * @param array{id: int, role: string} $user
 */
function report_send_progress_csv(array $user): never
{
    $rows = [];
    foreach (report_progress($user) as $ward) {
        $rows[] = [
            $ward['name'],
            $ward['units'],
            $ward['active_units'],
            $ward['submitted'],
            $ward['drafts'],
            $ward['missing'],
            $ward['accredited'],
            $ward['rejected'],
            $ward['valid_votes'],
        ];
    }
    report_send_csv('ohaji-egbema-result-progress.csv', [
        'ward',
        'polling_units',
        'active_units',
        'submitted',
        'drafts',
        'missing',
        'accredited_voters',
        'rejected_ballots',
        'valid_votes',
    ], $rows);
}

/**
 * @param array{id: int, role: string} $user
 */
function report_send_scores_csv(array $user): never
{
    $scores = report_scores($user);
    $rows = [];
    foreach ($scores['wards'] as $ward) {
        if ($scores['candidates'] === []) {
            $rows[] = [$ward['name'], '', '', 0];
            continue;
        }
        foreach ($scores['candidates'] as $candidate) {
            $rows[] = [
                $ward['name'],
                $candidate['party'],
                $candidate['full_name'],
                (int) ($ward['votes'][$candidate['id']] ?? 0),
            ];
        }
    }
    $totalLabel = $scores['scoped'] ? 'Scoped total' : 'Ohaji/Egbema';
    foreach ($scores['candidates'] as $candidate) {
        $rows[] = [$totalLabel, $candidate['party'], $candidate['full_name'], (int) ($scores['totals'][$candidate['id']] ?? 0)];
    }
    report_send_csv('ohaji-egbema-scores.csv', ['ward', 'party', 'candidate', 'votes'], $rows);
}

/**
 * @param array{id: int, role: string} $user
 */
function report_send_collation_csv(array $user): never
{
    $report = report_collation($user);
    $rows = [];
    foreach ($report['wards'] as $ward) {
        $rows[] = [
            (string) $ward['name'],
            (string) $ward['state'],
            (int) $ward['active_units'],
            (int) $ward['submitted'],
            (int) $ward['missing'],
            (int) $ward['accredited_voters'],
            (int) $ward['rejected_ballots'],
        ];
    }
    report_send_csv('ohaji-egbema-collation.csv', [
        'ward',
        'collation',
        'active_units',
        'submitted',
        'missing',
        'accredited_voters',
        'rejected_ballots',
    ], $rows);
}
