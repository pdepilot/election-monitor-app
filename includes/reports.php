<?php
declare(strict_types=1);

require_once __DIR__ . '/collation.php';

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
 * @return array<string, int|bool>
 */
function report_overview(): array
{
    $register = db()->query(
        'SELECT COUNT(*) AS units,
                COALESCE(SUM(is_active = 1), 0) AS active_units,
                COALESCE(SUM(agent_user_id IS NOT NULL), 0) AS assigned_units
         FROM polling_units'
    )->fetch();
    $progress = db()->query(
        "SELECT COALESCE(SUM(r.status = 'submitted'), 0) AS submitted,
                COALESCE(SUM(r.status = 'draft'), 0) AS drafts,
                COALESCE(SUM(pu.is_active = 1 AND (r.id IS NULL OR r.status <> 'submitted')), 0) AS missing,
                COALESCE(SUM(CASE WHEN r.status = 'submitted' THEN r.accredited_voters ELSE 0 END), 0) AS accredited,
                COALESCE(SUM(CASE WHEN r.status = 'submitted' THEN r.rejected_ballots ELSE 0 END), 0) AS rejected
         FROM polling_units pu
         LEFT JOIN polling_unit_results r ON r.polling_unit_id = pu.id"
    )->fetch();
    if (!is_array($register)) {
        $register = [];
    }
    if (!is_array($progress)) {
        $progress = [];
    }

    return [
        'wards' => (int) db()->query('SELECT COUNT(*) FROM wards')->fetchColumn(),
        'units' => (int) ($register['units'] ?? 0),
        'active_units' => (int) ($register['active_units'] ?? 0),
        'assigned_units' => (int) ($register['assigned_units'] ?? 0),
        'submitted' => (int) ($progress['submitted'] ?? 0),
        'drafts' => (int) ($progress['drafts'] ?? 0),
        'missing' => (int) ($progress['missing'] ?? 0),
        'accredited' => (int) ($progress['accredited'] ?? 0),
        'rejected' => (int) ($progress['rejected'] ?? 0),
        'valid_votes' => report_submitted_valid_votes(),
        'candidates' => (int) db()->query('SELECT COUNT(*) FROM candidates WHERE is_active = 1')->fetchColumn(),
        'collation_ready' => collation_schema_ready(),
    ];
}

function report_submitted_valid_votes(): int
{
    return (int) db()->query(
        "SELECT COALESCE(SUM(v.votes), 0)
         FROM polling_unit_result_votes v
         INNER JOIN polling_unit_results r ON r.id = v.result_id AND r.status = 'submitted'"
    )->fetchColumn();
}

/**
 * @return list<array<string, int|string>>
 */
function report_progress(): array
{
    $statement = db()->query(
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
         LEFT JOIN polling_units pu ON pu.ward_id = w.id
         LEFT JOIN polling_unit_results r ON r.polling_unit_id = pu.id
         LEFT JOIN (
            SELECT result_id, SUM(votes) AS votes
            FROM polling_unit_result_votes
            GROUP BY result_id
         ) vv ON vv.result_id = r.id
         GROUP BY w.id, w.name
         ORDER BY w.name"
    );
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
 * @return array{candidates: list<array{id: int, party: string, full_name: string}>, wards: list<array{id: int, name: string, votes: array<int, int>}>, totals: array<int, int>}
 */
function report_scores(): array
{
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
    $voteRows = db()->query(
        "SELECT pu.ward_id, v.candidate_id, SUM(v.votes) AS votes
         FROM polling_unit_result_votes v
         INNER JOIN polling_unit_results r ON r.id = v.result_id AND r.status = 'submitted'
         INNER JOIN polling_units pu ON pu.id = r.polling_unit_id
         GROUP BY pu.ward_id, v.candidate_id"
    )->fetchAll();
    $byWard = [];
    foreach ($voteRows as $row) {
        $wardId = (int) $row['ward_id'];
        $candidateId = (int) $row['candidate_id'];
        $votes = (int) $row['votes'];
        $byWard[$wardId][$candidateId] = $votes;
        if (isset($totals[$candidateId])) {
            $totals[$candidateId] += $votes;
        }
    }
    $wards = [];
    foreach (db()->query('SELECT id, name FROM wards ORDER BY name') as $row) {
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
    ];
}

/**
 * @return array{available: bool, wards: list<array<string, mixed>>, constituency: ?array<string, mixed>, stale: int, ready: bool}
 */
function report_collation(): array
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
    $preview = collation_constituency_preview();
    $rows = [];
    foreach ($preview['rows'] as $ward) {
        $state = 'Open';
        if ($ward['collation_id'] !== null) {
            $snapshot = collation_snapshot_votes((int) $ward['collation_id']);
            $live = collation_live_votes((int) $ward['id']);
            $stored = db()->prepare('SELECT accredited_voters, rejected_ballots FROM ward_collations WHERE id = :id');
            $stored->bindValue(':id', (int) $ward['collation_id'], PDO::PARAM_INT);
            $stored->execute();
            $figures = $stored->fetch();
            $stale = !is_array($figures)
                || collation_votes_differ($snapshot, $live)
                || (int) $figures['accredited_voters'] !== $ward['accredited_voters']
                || (int) $figures['rejected_ballots'] !== $ward['rejected_ballots'];
            $state = $stale ? 'Stale' : 'Collated';
        }
        $ward['state'] = $state;
        $rows[] = $ward;
    }

    return [
        'available' => true,
        'wards' => $rows,
        'constituency' => collation_constituency_record(),
        'stale' => $preview['stale'],
        'ready' => $preview['ready'],
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

function report_send_progress_csv(): never
{
    $rows = [];
    foreach (report_progress() as $ward) {
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

function report_send_scores_csv(): never
{
    $scores = report_scores();
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
    foreach ($scores['candidates'] as $candidate) {
        $rows[] = ['Ohaji/Egbema', $candidate['party'], $candidate['full_name'], (int) ($scores['totals'][$candidate['id']] ?? 0)];
    }
    report_send_csv('ohaji-egbema-scores.csv', ['ward', 'party', 'candidate', 'votes'], $rows);
}

function report_send_collation_csv(): never
{
    $report = report_collation();
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
