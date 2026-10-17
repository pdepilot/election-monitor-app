<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/dashboard_view.php';
require_once __DIR__ . '/result_review.php';

const COLLATION_READERS = ['super_admin', 'admin', 'supervisor', 'collation_officer', 'viewer'];
const COLLATION_SIGNERS = ['super_admin', 'admin', 'supervisor', 'collation_officer'];

/**
 * @return array{id: int, username: string, email: string, full_name: string, role: string, last_login_at?: ?string}
 */
function collation_require_user(): array
{
    $user = auth_user();
    if ($user === null) {
        redirect_to('portal/login.php');
    }
    if (!in_array($user['role'], COLLATION_READERS, true)) {
        render_forbidden($user);
        exit;
    }

    return $user;
}

function collation_open_discrepancies(?int $wardId = null): int
{
    return result_review_schema_ready() ? review_open_discrepancies($wardId) : 0;
}

/**
 * @return array<int, array<string, int>>
 */
function collation_review_summary(): array
{
    return result_review_schema_ready() && sheet_schema_ready() ? review_summary_by_ward() : [];
}

function collation_can_sign(array $user): bool
{
    return in_array($user['role'], COLLATION_SIGNERS, true);
}

function collation_schema_ready(): bool
{
    try {
        $pdo = db();
        $pdo->query('SELECT 1 FROM ward_collations LIMIT 1');
        $pdo->query('SELECT 1 FROM ward_collation_votes LIMIT 1');
        $pdo->query('SELECT 1 FROM constituency_collations LIMIT 1');
        $pdo->query('SELECT 1 FROM constituency_collation_votes LIMIT 1');
        $pdo->query('SELECT 1 FROM polling_unit_results LIMIT 1');

        return true;
    } catch (Throwable $exception) {
        error_log($exception->getMessage());

        return false;
    }
}

function collation_redirect(string $path): never
{
    if (!preg_match('#\Acollation/(index|ward|constituency)\.php(?:\?[a-z0-9_=&%+.-]*)?\z#', $path)) {
        $path = 'collation/index.php';
    }
    header('Location: ' . url($path), true, 302);
    exit;
}

function collation_flash(string $kind, string $message): void
{
    if (!in_array($kind, ['success', 'error', 'warn'], true)) {
        $kind = 'success';
    }
    $_SESSION['collation_flash'] = ['kind' => $kind, 'message' => $message];
}

/**
 * @return array{kind: string, message: string}|null
 */
function collation_take_flash(): ?array
{
    $flash = $_SESSION['collation_flash'] ?? null;
    unset($_SESSION['collation_flash']);
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

/**
 * @param array{id: int, username: string, email: string, full_name: string, role: string, last_login_at?: ?string} $user
 */
function collation_render_missing_schema(array $user): void
{
    render_shell_start('Collation', 'collation', $user);
    ?>
    <header class="cms-page__header">
        <div>
            <h1 class="cms-page__title">Collation</h1>
            <p class="cms-page__subtitle">The collation tables are not available yet.</p>
        </div>
    </header>
    <article class="cms-card">
        <div class="cms-card__body">
            <p>Apply the collation migration, then reload this page. It does not remove polling units or results.</p>
            <p class="cms-muted">mysql -u root election_monitor_app &lt; database/migration_collation.sql</p>
        </div>
    </article>
    <?php
    render_shell_end();
}

/**
 * @return array{wards: int, collated: int, submitted: int, missing: int}
 */
function collation_summary(): array
{
    $row = db()->query(
        "SELECT
            COUNT(*) AS wards,
            COALESCE(SUM(c.id IS NOT NULL), 0) AS collated
         FROM wards w
         LEFT JOIN ward_collations c ON c.ward_id = w.id"
    )->fetch();
    $units = db()->query(
        "SELECT
            COALESCE(SUM(r.status = 'submitted'), 0) AS submitted,
            COALESCE(SUM(pu.is_active = 1 AND (r.id IS NULL OR r.status <> 'submitted')), 0) AS missing
         FROM polling_units pu
         LEFT JOIN polling_unit_results r ON r.polling_unit_id = pu.id"
    )->fetch();
    if (!is_array($row)) {
        $row = [];
    }
    if (!is_array($units)) {
        $units = [];
    }

    return [
        'wards' => (int) ($row['wards'] ?? 0),
        'collated' => (int) ($row['collated'] ?? 0),
        'submitted' => (int) ($units['submitted'] ?? 0),
        'missing' => (int) ($units['missing'] ?? 0),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function collation_ward_rows(): array
{
    $statement = db()->query(
        "SELECT w.id, w.name,
                COUNT(pu.id) AS units,
                COALESCE(SUM(pu.is_active = 1), 0) AS active_units,
                COALESCE(SUM(r.status = 'submitted'), 0) AS submitted,
                COALESCE(SUM(pu.is_active = 1 AND (r.id IS NULL OR r.status <> 'submitted')), 0) AS missing,
                COALESCE(SUM(CASE WHEN r.status = 'submitted' THEN r.accredited_voters ELSE 0 END), 0) AS accredited_voters,
                COALESCE(SUM(CASE WHEN r.status = 'submitted' THEN r.rejected_ballots ELSE 0 END), 0) AS rejected_ballots,
                c.id AS collation_id, c.collated_at, u.full_name AS collated_by_name
         FROM wards w
         LEFT JOIN polling_units pu ON pu.ward_id = w.id
         LEFT JOIN polling_unit_results r ON r.polling_unit_id = pu.id
         LEFT JOIN ward_collations c ON c.ward_id = w.id
         LEFT JOIN users u ON u.id = c.collated_by
         GROUP BY w.id, w.name, c.id, c.collated_at, u.full_name
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
            'missing' => (int) $row['missing'],
            'accredited_voters' => (int) $row['accredited_voters'],
            'rejected_ballots' => (int) $row['rejected_ballots'],
            'collation_id' => $row['collation_id'] === null ? null : (int) $row['collation_id'],
            'collated_at' => $row['collated_at'] !== null ? (string) $row['collated_at'] : null,
            'collated_by_name' => $row['collated_by_name'] !== null ? (string) $row['collated_by_name'] : null,
        ];
    }

    return $rows;
}

/**
 * @return array<string, mixed>|null
 */
function collation_ward(int $wardId): ?array
{
    foreach (collation_ward_rows() as $ward) {
        if ($ward['id'] === $wardId) {
            return $ward;
        }
    }

    return null;
}

/**
 * @return list<array{id: int, party: string, full_name: string, votes: int}>
 */
function collation_live_votes(int $wardId): array
{
    $statement = db()->prepare(
        "SELECT c.id, c.party, c.full_name, c.sort_order,
                COALESCE(SUM(CASE WHEN pu.ward_id = :ward_id AND r.status = 'submitted' THEN v.votes ELSE 0 END), 0) AS votes
         FROM candidates c
         LEFT JOIN polling_unit_result_votes v ON v.candidate_id = c.id
         LEFT JOIN polling_unit_results r ON r.id = v.result_id
         LEFT JOIN polling_units pu ON pu.id = r.polling_unit_id
         WHERE c.is_active = 1
            OR EXISTS (
                SELECT 1
                FROM polling_unit_result_votes vx
                INNER JOIN polling_unit_results rx ON rx.id = vx.result_id AND rx.status = 'submitted'
                INNER JOIN polling_units pux ON pux.id = rx.polling_unit_id
                WHERE vx.candidate_id = c.id AND pux.ward_id = :ward_exists
            )
         GROUP BY c.id, c.party, c.full_name, c.sort_order
         ORDER BY c.sort_order, c.party, c.full_name"
    );
    $statement->bindValue(':ward_id', $wardId, PDO::PARAM_INT);
    $statement->bindValue(':ward_exists', $wardId, PDO::PARAM_INT);
    $statement->execute();
    $votes = [];
    foreach ($statement as $row) {
        $votes[] = [
            'id' => (int) $row['id'],
            'party' => (string) $row['party'],
            'full_name' => (string) $row['full_name'],
            'votes' => (int) $row['votes'],
        ];
    }

    return $votes;
}

/**
 * @return array<int, int>
 */
function collation_snapshot_votes(int $collationId): array
{
    $statement = db()->prepare('SELECT candidate_id, votes FROM ward_collation_votes WHERE collation_id = :id');
    $statement->bindValue(':id', $collationId, PDO::PARAM_INT);
    $statement->execute();
    $votes = [];
    foreach ($statement as $row) {
        $votes[(int) $row['candidate_id']] = (int) $row['votes'];
    }

    return $votes;
}

/**
 * @param array<int, int> $snapshot
 * @param list<array{id: int, votes: int}> $live
 */
function collation_votes_differ(array $snapshot, array $live): bool
{
    $liveMap = [];
    foreach ($live as $row) {
        $liveMap[$row['id']] = $row['votes'];
    }
    $ids = array_unique(array_merge(array_keys($snapshot), array_keys($liveMap)));
    foreach ($ids as $id) {
        if ((int) ($snapshot[$id] ?? 0) !== (int) ($liveMap[$id] ?? 0)) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<array<string, mixed>>
 */
function collation_ward_units(int $wardId): array
{
    $statement = db()->prepare(
        "SELECT pu.id, pu.unit_code, pu.name, pu.is_active,
                r.status, r.accredited_voters, r.rejected_ballots,
                COALESCE(v.valid_votes, 0) AS valid_votes
         FROM polling_units pu
         LEFT JOIN polling_unit_results r ON r.polling_unit_id = pu.id
         LEFT JOIN (
            SELECT result_id, SUM(votes) AS valid_votes
            FROM polling_unit_result_votes
            GROUP BY result_id
         ) v ON v.result_id = r.id
         WHERE pu.ward_id = :ward_id
         ORDER BY pu.unit_code"
    );
    $statement->bindValue(':ward_id', $wardId, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

/**
 * @return array{ok: bool, error: string}
 */
function collation_sign_ward(int $wardId, int $actorId): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ward = collation_ward($wardId);
        if ($ward === null) {
            $pdo->rollBack();

            return ['ok' => false, 'error' => 'That ward was not found.'];
        }
        if ($ward['active_units'] < 1) {
            $pdo->rollBack();

            return ['ok' => false, 'error' => 'This ward has no active polling units to collate.'];
        }
        if ($ward['missing'] > 0) {
            $pdo->rollBack();

            return ['ok' => false, 'error' => 'Collation is waiting on ' . $ward['missing'] . ' active polling unit' . ($ward['missing'] === 1 ? '' : 's') . ' without a submitted result.'];
        }
        $open = collation_open_discrepancies($wardId);
        if ($open > 0) {
            $pdo->rollBack();

            return ['ok' => false, 'error' => 'This ward has ' . $open . ' open discrepanc' . ($open === 1 ? 'y' : 'ies') . ' between entered figures and result sheets. A reviewer must resolve them first.'];
        }
        $votes = collation_live_votes($wardId);
        $valid = 0;
        foreach ($votes as $vote) {
            $valid += $vote['votes'];
        }
        if (($valid + $ward['rejected_ballots']) > $ward['accredited_voters']) {
            $pdo->rollBack();

            return ['ok' => false, 'error' => 'The submitted scores are inconsistent: votes and rejected ballots exceed accredited voters.'];
        }
        $existing = $pdo->prepare('SELECT id FROM ward_collations WHERE ward_id = :ward_id LIMIT 1');
        $existing->bindValue(':ward_id', $wardId, PDO::PARAM_INT);
        $existing->execute();
        $current = $existing->fetch();
        if (is_array($current)) {
            $collationId = (int) $current['id'];
            $update = $pdo->prepare(
                'UPDATE ward_collations
                 SET accredited_voters = :accredited, rejected_ballots = :rejected, unit_count = :units,
                     collated_by = :actor, collated_at = NOW()
                 WHERE id = :id'
            );
            $update->bindValue(':accredited', $ward['accredited_voters'], PDO::PARAM_INT);
            $update->bindValue(':rejected', $ward['rejected_ballots'], PDO::PARAM_INT);
            $update->bindValue(':units', $ward['submitted'], PDO::PARAM_INT);
            $update->bindValue(':actor', $actorId, PDO::PARAM_INT);
            $update->bindValue(':id', $collationId, PDO::PARAM_INT);
            $update->execute();
            $clear = $pdo->prepare('DELETE FROM ward_collation_votes WHERE collation_id = :id');
            $clear->bindValue(':id', $collationId, PDO::PARAM_INT);
            $clear->execute();
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO ward_collations (ward_id, accredited_voters, rejected_ballots, unit_count, collated_by)
                 VALUES (:ward_id, :accredited, :rejected, :units, :actor)'
            );
            $insert->bindValue(':ward_id', $wardId, PDO::PARAM_INT);
            $insert->bindValue(':accredited', $ward['accredited_voters'], PDO::PARAM_INT);
            $insert->bindValue(':rejected', $ward['rejected_ballots'], PDO::PARAM_INT);
            $insert->bindValue(':units', $ward['submitted'], PDO::PARAM_INT);
            $insert->bindValue(':actor', $actorId, PDO::PARAM_INT);
            $insert->execute();
            $collationId = (int) $pdo->lastInsertId();
        }
        $voteInsert = $pdo->prepare(
            'INSERT INTO ward_collation_votes (collation_id, candidate_id, votes)
             VALUES (:collation_id, :candidate_id, :votes)'
        );
        foreach ($votes as $vote) {
            $voteInsert->bindValue(':collation_id', $collationId, PDO::PARAM_INT);
            $voteInsert->bindValue(':candidate_id', $vote['id'], PDO::PARAM_INT);
            $voteInsert->bindValue(':votes', $vote['votes'], PDO::PARAM_INT);
            $voteInsert->execute();
        }
        collation_audit($actorId, 'collate_ward', $collationId, 'Collated ward ' . $ward['name'] . '.');
        $pdo->commit();

        return ['ok' => true, 'error' => ''];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * @return array{rows: list<array<string, mixed>>, accredited: int, rejected: int, votes: list<array{id: int, party: string, full_name: string, votes: int}>, ready: bool, stale: int}
 */
function collation_constituency_preview(): array
{
    $wards = collation_ward_rows();
    $ready = true;
    $stale = 0;
    $accredited = 0;
    $rejected = 0;
    $voteMap = [];
    foreach ($wards as $ward) {
        if ($ward['active_units'] < 1) {
            continue;
        }
        if ($ward['collation_id'] === null) {
            $ready = false;
            continue;
        }
        $snapshot = collation_snapshot_votes((int) $ward['collation_id']);
        $live = collation_live_votes($ward['id']);
        $wardStale = collation_votes_differ($snapshot, $live)
            || (int) db()->query('SELECT accredited_voters FROM ward_collations WHERE id = ' . (int) $ward['collation_id'])->fetchColumn() !== $ward['accredited_voters']
            || (int) db()->query('SELECT rejected_ballots FROM ward_collations WHERE id = ' . (int) $ward['collation_id'])->fetchColumn() !== $ward['rejected_ballots'];
        if ($wardStale) {
            $stale++;
            $ready = false;
        }
        $stored = db()->prepare('SELECT accredited_voters, rejected_ballots FROM ward_collations WHERE id = :id');
        $stored->bindValue(':id', (int) $ward['collation_id'], PDO::PARAM_INT);
        $stored->execute();
        $figures = $stored->fetch();
        if (!is_array($figures)) {
            $ready = false;
            continue;
        }
        $accredited += (int) $figures['accredited_voters'];
        $rejected += (int) $figures['rejected_ballots'];
        foreach ($snapshot as $candidateId => $count) {
            $voteMap[$candidateId] = ($voteMap[$candidateId] ?? 0) + $count;
        }
    }
    $candidates = db()->query('SELECT id, party, full_name, sort_order, is_active FROM candidates ORDER BY sort_order, party, full_name')->fetchAll();
    $votes = [];
    foreach ($candidates as $candidate) {
        $id = (int) $candidate['id'];
        if (!isset($voteMap[$id]) && (int) $candidate['is_active'] !== 1) {
            continue;
        }
        $votes[] = [
            'id' => $id,
            'party' => (string) $candidate['party'],
            'full_name' => (string) $candidate['full_name'],
            'votes' => (int) ($voteMap[$id] ?? 0),
        ];
    }

    $open = collation_open_discrepancies();

    return [
        'rows' => $wards,
        'accredited' => $accredited,
        'rejected' => $rejected,
        'votes' => $votes,
        'ready' => $ready && $wards !== [] && $open === 0,
        'stale' => $stale,
        'discrepancies' => $open,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function collation_constituency_record(): ?array
{
    $row = db()->query(
        'SELECT c.id, c.accredited_voters, c.rejected_ballots, c.ward_count, c.collated_at, u.full_name AS collated_by_name
         FROM constituency_collations c
         LEFT JOIN users u ON u.id = c.collated_by
         WHERE c.scope_key = \'1\'
         LIMIT 1'
    )->fetch();
    if (!is_array($row)) {
        return null;
    }
    $votes = db()->prepare(
        'SELECT candidate_id, votes FROM constituency_collation_votes WHERE collation_id = :id'
    );
    $votes->bindValue(':id', (int) $row['id'], PDO::PARAM_INT);
    $votes->execute();
    $map = [];
    foreach ($votes as $vote) {
        $map[(int) $vote['candidate_id']] = (int) $vote['votes'];
    }
    $row['votes'] = $map;

    return $row;
}

/**
 * @return array{ok: bool, error: string}
 */
function collation_sign_constituency(int $actorId): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $preview = collation_constituency_preview();
        if (!$preview['ready']) {
            $pdo->rollBack();
            $message = $preview['discrepancies'] > 0
                ? 'Resolve the ' . $preview['discrepancies'] . ' open result-sheet discrepanc' . ($preview['discrepancies'] === 1 ? 'y' : 'ies') . ' before signing the constituency total.'
                : ($preview['stale'] > 0
                    ? 'Recollate the wards whose submitted results changed before signing the constituency total.'
                    : 'Every ward with active polling units must be collated first.');

            return ['ok' => false, 'error' => $message];
        }
        $valid = 0;
        foreach ($preview['votes'] as $vote) {
            $valid += $vote['votes'];
        }
        if (($valid + $preview['rejected']) > $preview['accredited']) {
            $pdo->rollBack();

            return ['ok' => false, 'error' => 'The ward collation totals are inconsistent.'];
        }
        $wardCount = 0;
        foreach ($preview['rows'] as $ward) {
            if ($ward['active_units'] > 0) {
                $wardCount++;
            }
        }
        $existing = $pdo->query("SELECT id FROM constituency_collations WHERE scope_key = '1' LIMIT 1")->fetch();
        if (is_array($existing)) {
            $collationId = (int) $existing['id'];
            $update = $pdo->prepare(
                'UPDATE constituency_collations
                 SET accredited_voters = :accredited, rejected_ballots = :rejected, ward_count = :wards,
                     collated_by = :actor, collated_at = NOW()
                 WHERE id = :id'
            );
            $update->bindValue(':accredited', $preview['accredited'], PDO::PARAM_INT);
            $update->bindValue(':rejected', $preview['rejected'], PDO::PARAM_INT);
            $update->bindValue(':wards', $wardCount, PDO::PARAM_INT);
            $update->bindValue(':actor', $actorId, PDO::PARAM_INT);
            $update->bindValue(':id', $collationId, PDO::PARAM_INT);
            $update->execute();
            $clear = $pdo->prepare('DELETE FROM constituency_collation_votes WHERE collation_id = :id');
            $clear->bindValue(':id', $collationId, PDO::PARAM_INT);
            $clear->execute();
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO constituency_collations (scope_key, accredited_voters, rejected_ballots, ward_count, collated_by)
                 VALUES (\'1\', :accredited, :rejected, :wards, :actor)'
            );
            $insert->bindValue(':accredited', $preview['accredited'], PDO::PARAM_INT);
            $insert->bindValue(':rejected', $preview['rejected'], PDO::PARAM_INT);
            $insert->bindValue(':wards', $wardCount, PDO::PARAM_INT);
            $insert->bindValue(':actor', $actorId, PDO::PARAM_INT);
            $insert->execute();
            $collationId = (int) $pdo->lastInsertId();
        }
        $voteInsert = $pdo->prepare(
            'INSERT INTO constituency_collation_votes (collation_id, candidate_id, votes)
             VALUES (:collation_id, :candidate_id, :votes)'
        );
        foreach ($preview['votes'] as $vote) {
            $voteInsert->bindValue(':collation_id', $collationId, PDO::PARAM_INT);
            $voteInsert->bindValue(':candidate_id', $vote['id'], PDO::PARAM_INT);
            $voteInsert->bindValue(':votes', $vote['votes'], PDO::PARAM_INT);
            $voteInsert->execute();
        }
        collation_audit($actorId, 'collate_constituency', $collationId, 'Collated the Ohaji/Egbema constituency total.');
        $pdo->commit();

        return ['ok' => true, 'error' => ''];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function collation_audit(?int $actorId, string $action, ?int $entityId, string $summary): void
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
    $statement->bindValue(':entity', 'collation', PDO::PARAM_STR);
    $statement->bindValue(':entity_id', $entityId !== null && $entityId > 0 ? $entityId : null, $entityId !== null && $entityId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $statement->bindValue(':summary', $summary, PDO::PARAM_STR);
    $statement->execute();
}

function collation_form_script(): void
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
