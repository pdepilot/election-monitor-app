<?php
declare(strict_types=1);

/*
 * Temporary fixtures for the offline browser test (local development only).
 *   php scripts/_offline_fixture.php setup     creates users, test candidates, assignments
 *   php scripts/_offline_fixture.php teardown  removes only rows created since setup
 * Polling units are borrowed (agent assignment) and restored with their original updated_at.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/result_submissions.php';

const FIXTURE_STATE = __DIR__ . '/../storage/offline-browser-fixture.json';
const FIXTURE_TABLES = ['polling_unit_results', 'result_submissions', 'audit_log', 'candidates', 'result_reviews', 'result_discrepancies', 'login_attempts'];

function fixture_count(string $sql): int
{
    return (int) db()->query($sql)->fetchColumn();
}

function fixture_teardown(array $state): bool
{
    foreach (['result_submissions', 'result_discrepancies', 'result_reviews', 'polling_unit_results', 'audit_log', 'login_attempts'] as $table) {
        db()->exec('DELETE FROM ' . $table . ' WHERE id > ' . (int) $state['start'][$table]);
    }
    db()->exec('DELETE FROM candidates WHERE id > ' . (int) $state['start']['candidates'] . " AND party IN ('TSTA', 'TSTB')");
    foreach ($state['units_backup'] as $unit) {
        db()->prepare('UPDATE polling_units SET agent_user_id = :agent, is_active = :active, updated_at = :updated WHERE id = :id')
            ->execute(['agent' => $unit['agent_user_id'], 'active' => (int) $unit['is_active'], 'updated' => $unit['updated_at'], 'id' => (int) $unit['id']]);
    }
    $ids = array_map('intval', array_values($state['user_ids']));
    if ($ids !== []) {
        db()->exec('DELETE FROM remember_tokens WHERE user_id IN (' . implode(',', $ids) . ')');
        db()->exec('DELETE FROM users WHERE id IN (' . implode(',', $ids) . ')');
    }

    return fixture_count("SELECT COUNT(*) FROM users WHERE username LIKE 'ob.%'") === 0
        && fixture_count("SELECT COUNT(*) FROM candidates WHERE party IN ('TSTA', 'TSTB')") === 0
        && fixture_count('SELECT COUNT(*) FROM result_submissions WHERE id > ' . (int) $state['start']['result_submissions']) === 0
        && fixture_count('SELECT COUNT(*) FROM polling_unit_results WHERE id > ' . (int) $state['start']['polling_unit_results']) === 0;
}

$command = $argv[1] ?? '';
if ($command === 'teardown') {
    if (!is_file(FIXTURE_STATE)) {
        echo json_encode(['ok' => true, 'note' => 'nothing to remove']) . PHP_EOL;
        exit(0);
    }
    $state = json_decode((string) file_get_contents(FIXTURE_STATE), true);
    $clean = fixture_teardown($state);
    if ($clean) {
        unlink(FIXTURE_STATE);
    }
    echo json_encode(['ok' => $clean]) . PHP_EOL;
    exit($clean ? 0 : 1);
}
if ($command !== 'setup') {
    fwrite(STDERR, "Usage: php scripts/_offline_fixture.php setup|teardown\n");
    exit(2);
}
if (is_file(FIXTURE_STATE)) {
    fwrite(STDERR, "A previous fixture is still recorded; run teardown first.\n");
    exit(1);
}

$state = ['start' => [], 'user_ids' => [], 'units_backup' => []];
foreach (FIXTURE_TABLES as $table) {
    $state['start'][$table] = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM ' . $table)->fetchColumn();
}
$units = db()->query('SELECT id, unit_code, is_active, agent_user_id, updated_at FROM polling_units WHERE is_active = 1 AND agent_user_id IS NULL ORDER BY id DESC LIMIT 15')->fetchAll();
if (count($units) < 15) {
    fwrite(STDERR, "Not enough active, unassigned polling units to borrow.\n");
    exit(1);
}
$state['units_backup'] = $units;
file_put_contents(FIXTURE_STATE, json_encode($state));

try {
    $users = ['ob.agent' => 'polling_agent', 'ob.agent2' => 'polling_agent', 'ob.admin' => 'admin'];
    $passwords = [];
    $insert = db()->prepare('INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (:u, :e, :h, :n, :r, 1)');
    foreach ($users as $name => $role) {
        $passwords[$name] = bin2hex(random_bytes(10));
        $insert->execute(['u' => $name, 'e' => $name . '@ohaji-egbema.local', 'h' => password_hash($passwords[$name], PASSWORD_DEFAULT), 'n' => 'Offline Browser ' . $name, 'r' => $role]);
        $state['user_ids'][$name] = (int) db()->lastInsertId();
    }
    file_put_contents(FIXTURE_STATE, json_encode($state));
    $candidates = [];
    foreach (['TSTA' => 'TEST CANDIDATE A', 'TSTB' => 'TEST CANDIDATE B'] as $party => $name) {
        db()->prepare('INSERT INTO candidates (full_name, party, sort_order, is_active) VALUES (:n, :p, 990, 1)')->execute(['n' => $name, 'p' => $party]);
        $candidates[] = (int) db()->lastInsertId();
    }
    $assign = db()->prepare('UPDATE polling_units SET agent_user_id = :a, updated_at = updated_at WHERE id = :u');
    $unitIds = array_map(static fn (array $u): int => (int) $u['id'], $units);
    foreach (array_slice($unitIds, 0, 14) as $unitId) {
        $assign->execute(['a' => $state['user_ids']['ob.agent'], 'u' => $unitId]);
    }
    $assign->execute(['a' => $state['user_ids']['ob.agent2'], 'u' => $unitIds[14]]);
} catch (Throwable $exception) {
    fixture_teardown($state);
    unlink(FIXTURE_STATE);
    fwrite(STDERR, 'Fixture setup failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

echo json_encode([
    'ok' => true,
    'base' => 'http://localhost/election-monitor-app/',
    'users' => array_map(static fn (string $name): array => ['login' => $name, 'password' => $passwords[$name], 'id' => $state['user_ids'][$name]], array_combine(array_keys($users), array_keys($users))),
    'units' => array_slice($unitIds, 0, 14),
    'other_unit' => $unitIds[14],
    'candidates' => $candidates,
]) . PHP_EOL;
