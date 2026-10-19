<?php
declare(strict_types=1);

// Phase D: geographic scope for supervisor, collation_officer, and viewer.
// Borrows unassigned units; restores agent assignments and removes scope fixtures.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/scope.php';
require_once dirname(__DIR__) . '/includes/results.php';
require_once dirname(__DIR__) . '/includes/collation.php';
require_once dirname(__DIR__) . '/includes/reports.php';
require_once dirname(__DIR__) . '/includes/result_sheets.php';

const BASE = 'http://localhost/election-monitor-app/';
$failures = 0;
$skips = 0;

function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

function skip(string $label): void
{
    global $skips;
    echo 'SKIP ' . $label . PHP_EOL;
    $skips++;
}

final class PdClient
{
    public CurlHandle $ch;

    public function __construct()
    {
        $this->ch = curl_init();
        curl_setopt_array($this->ch, [
            CURLOPT_COOKIEFILE => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
    }

    public function req(string $path, ?string $body = null, array $headers = []): array
    {
        curl_setopt($this->ch, CURLOPT_URL, BASE . ltrim($path, '/'));
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headers);
        if ($body === null) {
            curl_setopt($this->ch, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($this->ch, CURLOPT_POST, true);
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = (string) curl_exec($this->ch);
        $size = (int) curl_getinfo($this->ch, CURLINFO_HEADER_SIZE);

        return [
            'code' => (int) curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE),
            'headers' => substr($raw, 0, $size),
            'body' => substr($raw, $size),
        ];
    }

    public function login(string $login, string $password): void
    {
        $page = $this->req('portal/login');
        preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $page['body'], $m);
        $this->req('portal/login', http_build_query([
            'csrf_token' => $m[1] ?? '',
            'login' => $login,
            'password' => $password,
        ]));
    }
}

if (!scope_schema_ready()) {
    echo "FAIL scope tables missing — apply database/migration_user_scopes.sql locally first\n";
    exit(1);
}

$assignmentFingerprint = static function (): string {
    return hash('sha256', (string) db()->query(
        'SELECT GROUP_CONCAT(CONCAT(id, ":", IFNULL(agent_user_id, "n"), ":", is_active) ORDER BY id SEPARATOR ",") FROM polling_units'
    )->fetchColumn());
};
$scopeFingerprint = static function (): string {
    $wards = (string) db()->query(
        'SELECT GROUP_CONCAT(CONCAT(user_id, ":", ward_id) ORDER BY user_id, ward_id SEPARATOR ",") FROM user_ward_scopes'
    )->fetchColumn();
    $units = (string) db()->query(
        'SELECT GROUP_CONCAT(CONCAT(user_id, ":", polling_unit_id) ORDER BY user_id, polling_unit_id SEPARATOR ",") FROM user_unit_scopes'
    )->fetchColumn();

    return hash('sha256', $wards . '|' . $units);
};

$unitCountBefore = (int) db()->query('SELECT COUNT(*) FROM polling_units')->fetchColumn();
$fingerprintBefore = $assignmentFingerprint();
$scopeBefore = $scopeFingerprint();

$users = [
    'pd.super' => 'supervisor',
    'pd.super2' => 'supervisor',
    'pd.officer' => 'collation_officer',
    'pd.viewer' => 'viewer',
    'pd.agent' => 'polling_agent',
    'pd.admin' => 'admin',
];
$pass = [];
$ids = [];
$borrowed = [];

try {
    $insert = db()->prepare(
        'INSERT INTO users (username, email, password_hash, full_name, role, is_active)
         VALUES (:u, :e, :h, :n, :r, 1)'
    );
    foreach ($users as $name => $role) {
        $pass[$name] = bin2hex(random_bytes(10));
        $insert->execute([
            'u' => $name,
            'e' => $name . '@ohaji-egbema.local',
            'h' => password_hash($pass[$name], PASSWORD_DEFAULT),
            'n' => 'Phase D ' . $name,
            'r' => $role,
        ]);
        $ids[$name] = (int) db()->lastInsertId();
    }

    $wardRows = db()->query(
        'SELECT w.id, w.name, COUNT(pu.id) AS units
         FROM wards w
         INNER JOIN polling_units pu ON pu.ward_id = w.id AND pu.is_active = 1 AND pu.agent_user_id IS NULL
         GROUP BY w.id, w.name
         HAVING units >= 1
         ORDER BY units DESC, w.id
         LIMIT 2'
    )->fetchAll();
    if (count($wardRows) < 2) {
        throw new RuntimeException('Need two wards with active unassigned units for Phase D fixtures.');
    }
    $wardA = (int) $wardRows[0]['id'];
    $wardB = (int) $wardRows[1]['id'];
    $unitA = (int) db()->query(
        'SELECT id FROM polling_units WHERE ward_id = ' . $wardA . ' AND is_active = 1 AND agent_user_id IS NULL ORDER BY id LIMIT 1'
    )->fetchColumn();
    $unitB = (int) db()->query(
        'SELECT id FROM polling_units WHERE ward_id = ' . $wardB . ' AND is_active = 1 AND agent_user_id IS NULL ORDER BY id LIMIT 1'
    )->fetchColumn();
    if ($unitA < 1 || $unitB < 1) {
        throw new RuntimeException('Could not borrow active units in two wards.');
    }
    $borrowed[$unitA] = db()->query('SELECT id, agent_user_id, is_active, updated_at FROM polling_units WHERE id = ' . $unitA)->fetch();
    $borrowed[$unitB] = db()->query('SELECT id, agent_user_id, is_active, updated_at FROM polling_units WHERE id = ' . $unitB)->fetch();
    db()->prepare('UPDATE polling_units SET agent_user_id = :a WHERE id = :u')->execute(['a' => $ids['pd.agent'], 'u' => $unitA]);

    $actorId = $ids['pd.admin'];
    $super = ['id' => $ids['pd.super'], 'role' => 'supervisor'];
    $super2 = ['id' => $ids['pd.super2'], 'role' => 'supervisor'];
    $officer = ['id' => $ids['pd.officer'], 'role' => 'collation_officer'];
    $viewer = ['id' => $ids['pd.viewer'], 'role' => 'viewer'];
    $agent = ['id' => $ids['pd.agent'], 'role' => 'polling_agent'];
    $admin = ['id' => $ids['pd.admin'], 'role' => 'admin'];

    $unitARow = result_unit($unitA);
    $unitBRow = result_unit($unitB);
    if (!is_array($unitARow) || !is_array($unitBRow)) {
        throw new RuntimeException('result_unit lookup failed for borrowed units.');
    }

    echo "-- empty scope denies access\n";
    check(!scope_can_unit($super, $unitARow), 'supervisor with no scope cannot read Ward A unit');
    check(!scope_can_ward($super, $wardA), 'supervisor with no scope cannot read Ward A');
    check(result_list(['q' => '', 'ward' => 0, 'status' => '', 'page' => 1], $super)['total'] === 0, 'empty-scope supervisor result list is empty');
    check(result_summary($super)['units'] === 0, 'empty-scope supervisor summary has zero units');

    echo "-- assign Ward A to supervisor\n";
    $assignA = scope_assign_ward($ids['pd.super'], $wardA, $actorId);
    check($assignA['ok'], 'assign Ward A to supervisor');
    $dup = scope_assign_ward($ids['pd.super'], $wardA, $actorId);
    check(!$dup['ok'] && str_contains($dup['error'], 'already assigned'), 'duplicate ward assignment rejected');
    check(scope_can_unit($super, $unitARow) && scope_can_ward($super, $wardA), 'supervisor can read Ward A after assignment');
    check(!scope_can_unit($super, $unitBRow) && !scope_can_ward($super, $wardB), 'supervisor cannot read Ward B by unit or ward id');

    $listA = result_list(['q' => '', 'ward' => 0, 'status' => '', 'page' => 1], $super);
    $idsInList = array_map(static fn (array $r): int => (int) $r['id'], $listA['rows']);
    check(in_array($unitA, $idsInList, true), 'result list includes in-scope unit');
    check(!in_array($unitB, $idsInList, true), 'result list excludes out-of-scope unit');
    $filterLeak = result_list(['q' => '', 'ward' => $wardB, 'status' => '', 'page' => 1], $super);
    check($filterLeak['total'] === 0, 'ward filter for out-of-scope ward returns no rows');

    echo "-- multi-ward supervisor\n";
    check(scope_assign_ward($ids['pd.super2'], $wardA, $actorId)['ok'], 'assign Ward A to second supervisor');
    check(scope_assign_ward($ids['pd.super2'], $wardB, $actorId)['ok'], 'assign Ward B to second supervisor');
    check(scope_can_unit($super2, $unitARow) && scope_can_unit($super2, $unitBRow), 'multi-ward supervisor can read both wards');
    $onlyA = scope_accessible_ward_ids($super);
    $both = scope_accessible_ward_ids($super2);
    check($onlyA === [$wardA] || (is_array($onlyA) && count($onlyA) === 1 && in_array($wardA, $onlyA, true)), 'first supervisor ward set is only Ward A');
    check(is_array($both) && in_array($wardA, $both, true) && in_array($wardB, $both, true) && count($both) === 2, 'second supervisor ward set is exactly A and B');

    echo "-- collation officer scope\n";
    check(scope_assign_ward($ids['pd.officer'], $wardA, $actorId)['ok'], 'assign Ward A to collation officer');
    check(scope_can_ward($officer, $wardA) && !scope_can_ward($officer, $wardB), 'collation officer limited to assigned ward');
    check(collation_ward($wardA, $officer) !== null, 'officer can load in-scope ward collation row');
    check(collation_ward($wardB, $officer) === null, 'officer cannot load out-of-scope ward collation row');
    check(collation_can_sign($officer, $wardA) && !collation_can_sign($officer, $wardB), 'officer may sign only in-scope wards');
    check(!collation_can_sign_constituency($officer), 'officer cannot sign constituency total');

    $unitOnly = (int) db()->query(
        'SELECT id FROM polling_units WHERE ward_id = ' . $wardB . ' AND id <> ' . $unitB . ' AND is_active = 1 AND agent_user_id IS NULL ORDER BY id LIMIT 1'
    )->fetchColumn();
    if ($unitOnly > 0) {
        $badWard = scope_assign_unit($ids['pd.officer'], $unitOnly, $actorId, $wardA);
        check(!$badWard['ok'] && str_contains($badWard['error'], 'does not belong'), 'unit/ward mismatch rejected');
        $okUnit = scope_assign_unit($ids['pd.viewer'], $unitB, $actorId, $wardB);
        check($okUnit['ok'], 'viewer unit-level assignment accepted');
        check(scope_can_unit($viewer, $unitBRow) && !scope_can_ward($viewer, $wardB), 'unit scope grants unit not full ward');
        check(!scope_can_unit($viewer, $unitARow), 'viewer unit scope does not grant Ward A');
    } else {
        skip('second active unit in Ward B for unit-scope mismatch test');
        check(scope_assign_unit($ids['pd.viewer'], $unitB, $actorId, $wardB)['ok'], 'viewer unit-level assignment accepted');
        check(scope_can_unit($viewer, $unitBRow) && !scope_can_ward($viewer, $wardB), 'unit scope grants unit not full ward');
    }

    echo "-- viewer read-only\n";
    check(result_can_view_unit($viewer, $unitBRow), 'viewer can view assigned unit');
    check(!result_can_edit($viewer, $unitBRow, null), 'viewer cannot edit results');
    check(!collation_can_sign($viewer, $wardB), 'viewer cannot sign ward collation');
    check(!sheet_is_reviewer($viewer), 'viewer is not a sheet reviewer');

    $viewerClient = new PdClient();
    $viewerClient->login('pd.viewer', $pass['pd.viewer']);
    $entryGet = $viewerClient->req('results/entry?unit=' . $unitB);
    check($entryGet['code'] === 200 && !str_contains($entryGet['body'], 'Access denied'), 'viewer can open in-scope entry page');
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $entryGet['body'], $vm);
    $viewerClient->req('results/entry?unit=' . $unitB, http_build_query([
        'csrf_token' => $vm[1] ?? '',
        'intent' => 'save_draft',
        'unit_id' => (string) $unitB,
        'accredited_voters' => '10',
        'rejected_ballots' => '0',
        'declared_valid_votes' => '10',
        'declared_total_votes' => '10',
    ]));
    $viewerSaved = (int) db()->query(
        'SELECT COUNT(*) FROM polling_unit_results WHERE polling_unit_id = ' . $unitB
    )->fetchColumn();
    check($viewerSaved === 0, 'viewer POST does not create a result record');
    $outOfScopeEntry = $viewerClient->req('results/entry?unit=' . $unitA);
    check($outOfScopeEntry['code'] === 403 || str_contains($outOfScopeEntry['body'], 'Access denied'), 'viewer denied out-of-scope entry URL');

    $collatePost = $viewerClient->req('collation/ward?id=' . $wardA, http_build_query([
        'csrf_token' => $vm[1] ?? 'x',
        'intent' => 'collate',
        'ward_id' => (string) $wardA,
    ]));
    check(
        $collatePost['code'] === 403
        || str_contains($collatePost['body'], 'cannot sign')
        || str_contains($collatePost['headers'], 'Location:'),
        'viewer ward-collate POST is refused'
    );

    echo "-- revoke takes effect\n";
    check(scope_revoke_ward($ids['pd.super'], $wardA, $actorId)['ok'], 'revoke Ward A from supervisor');
    check(!scope_can_unit($super, $unitARow) && !scope_can_ward($super, $wardA), 'revoked ward denied on next check');
    $superClient = new PdClient();
    $superClient->login('pd.super', $pass['pd.super']);
    $revokedPage = $superClient->req('results/entry?unit=' . $unitA);
    check($revokedPage['code'] === 403 || str_contains($revokedPage['body'], 'Access denied'), 'revoked supervisor denied entry URL on next request');
    check(scope_assign_ward($ids['pd.super'], $wardA, $actorId)['ok'], 're-assign Ward A for remaining checks');

    echo "-- inactive unit does not bypass agent rules\n";
    $inactiveProbe = $unitBRow;
    $inactiveProbe['agent_user_id'] = $ids['pd.agent'];
    $inactiveProbe['is_active'] = 0;
    check(!scope_can_unit($agent, $inactiveProbe), 'agent denied inactive unit even if agent_user_id matches');
    check(!result_can_edit($agent, $inactiveProbe, null), 'agent cannot edit inactive unit');
    $prevActive = (int) $borrowed[$unitB]['is_active'];
    db()->prepare('UPDATE polling_units SET is_active = 0, updated_at = updated_at WHERE id = :id')->execute(['id' => $unitB]);
    $flipped = result_unit($unitB);
    check(is_array($flipped) && (int) $flipped['is_active'] === 0 && !result_can_view_unit($agent, $flipped), 'agent cannot view temporarily inactivated assigned unit');
    db()->prepare('UPDATE polling_units SET is_active = :a, updated_at = updated_at WHERE id = :id')->execute(['a' => $prevActive, 'id' => $unitB]);

    echo "-- polling agent isolation preserved\n";
    check(scope_can_unit($agent, $unitARow) && !scope_can_unit($agent, $unitBRow), 'agent limited to assigned active unit');
    $agentList = result_list(['q' => '', 'ward' => 0, 'status' => '', 'page' => 1], $agent);
    $agentIds = array_map(static fn (array $r): int => (int) $r['id'], $agentList['rows']);
    check($agentIds === [$unitA] || (count($agentIds) === 1 && $agentIds[0] === $unitA), 'agent result list is only their unit');

    echo "-- admin org-wide unchanged\n";
    check(scope_is_org_wide($admin) && scope_can_unit($admin, $unitBRow) && scope_can_ward($admin, $wardB), 'admin retains organisation-wide access');
    check(result_summary($admin)['units'] === $unitCountBefore, 'admin summary still covers all polling units');

    echo "-- reports and exports scoped\n";
    $progress = report_progress($super);
    $progressIds = array_map(static fn (array $w): int => (int) $w['id'], $progress);
    check(in_array($wardA, $progressIds, true) && !in_array($wardB, $progressIds, true), 'progress report limited to scoped wards');
    $scores = report_scores($super);
    $scoreIds = array_map(static fn (array $w): int => (int) $w['id'], $scores['wards']);
    check(in_array($wardA, $scoreIds, true) && !in_array($wardB, $scoreIds, true) && $scores['scoped'] === true, 'scores report limited to scoped wards');
    $collationReport = report_collation($officer);
    $cIds = array_map(static fn (array $w): int => (int) $w['id'], $collationReport['wards']);
    check(in_array($wardA, $cIds, true) && !in_array($wardB, $cIds, true) && $collationReport['constituency'] === null, 'collation report scoped; no constituency for officer');

    echo "-- sheet download scope\n";
    $sheetId = (int) db()->query(
        'SELECT s.id FROM result_sheet_uploads s
         INNER JOIN polling_units pu ON pu.id = s.polling_unit_id
         WHERE pu.ward_id = ' . $wardB . '
         ORDER BY s.id DESC LIMIT 1'
    )->fetchColumn();
    if ($sheetId < 1) {
        $sheetId = (int) db()->query(
            'SELECT s.id FROM result_sheet_uploads s
             INNER JOIN polling_units pu ON pu.id = s.polling_unit_id
             WHERE pu.ward_id <> ' . $wardA . '
             ORDER BY s.id DESC LIMIT 1'
        )->fetchColumn();
    }
    if ($sheetId > 0) {
        $upload = sheet_find($sheetId);
        if (is_array($upload)) {
            check(!sheet_can_view($super, $upload), 'supervisor denied out-of-scope sheet metadata');
            $superClient2 = new PdClient();
            $superClient2->login('pd.super', $pass['pd.super']);
            $dl = $superClient2->req('results/sheet-file?id=' . $sheetId);
            check(in_array($dl['code'], [403, 404], true), 'supervisor denied out-of-scope sheet download');
        } else {
            skip('sheet_find failed for existing upload');
        }
    } else {
        // Synthesize metadata-only view check without writing to private storage.
        $fakeUpload = [
            'id' => 0,
            'polling_unit_id' => $unitB,
            'is_active' => 1,
        ];
        check(!sheet_can_view($super, $fakeUpload), 'supervisor denied fabricated out-of-scope sheet metadata');
        skip('no existing result sheet outside Ward A for HTTP download denial');
    }

    $fakeIn = [
        'id' => 0,
        'polling_unit_id' => $unitA,
        'is_active' => 1,
    ];
    check(sheet_can_view($super, $fakeIn), 'supervisor can view fabricated in-scope sheet metadata');

    echo "-- assignment validation\n";
    $badUser = scope_assign_ward($ids['pd.admin'], $wardA, $actorId);
    check(!$badUser['ok'], 'cannot assign scope to admin account');
    $badAgent = scope_assign_ward($ids['pd.agent'], $wardA, $actorId);
    check(!$badAgent['ok'], 'cannot assign scope to polling agent');
    $missingWard = scope_assign_ward($ids['pd.super'], 999999999, $actorId);
    check(!$missingWard['ok'], 'nonexistent ward rejected');
    $missingUnit = scope_assign_unit($ids['pd.viewer'], 999999999, $actorId);
    check(!$missingUnit['ok'], 'nonexistent unit rejected');
    $supUnit = scope_assign_unit($ids['pd.super'], $unitA, $actorId);
    check(!$supUnit['ok'] && str_contains($supUnit['error'], 'ward'), 'supervisor cannot receive unit-level scope');

    echo "-- supervisor cannot edit results\n";
    check(!result_can_edit($super, $unitARow, null), 'supervisor cannot enter or edit results');

    $auditCount = (int) db()->query(
        "SELECT COUNT(*) FROM audit_log WHERE action LIKE 'scope_%' AND entity_id = " . $ids['pd.super']
    )->fetchColumn();
    check($auditCount >= 2, 'scope assign/revoke recorded in audit_log');
} catch (Throwable $e) {
    echo 'FAIL fixture/setup: ' . $e->getMessage() . PHP_EOL;
    $failures++;
}

echo "-- cleanup and integrity\n";
try {
    $pdIds = array_values($ids);
    if ($pdIds !== []) {
        $in = implode(',', array_map('intval', $pdIds));
        db()->exec("DELETE FROM user_ward_scopes WHERE user_id IN ({$in})");
        db()->exec("DELETE FROM user_unit_scopes WHERE user_id IN ({$in})");
        db()->exec("DELETE FROM audit_log WHERE actor_user_id IN ({$in}) OR entity_id IN ({$in})");
    }
    foreach ($borrowed as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        db()->prepare(
            'UPDATE polling_units SET agent_user_id = :a, is_active = :active, updated_at = :u WHERE id = :id'
        )->execute([
            'a' => $row['agent_user_id'],
            'active' => (int) $row['is_active'],
            'u' => $row['updated_at'],
            'id' => $id,
        ]);
    }
    if ($pdIds !== []) {
        db()->exec('DELETE FROM users WHERE id IN (' . implode(',', array_map('intval', $pdIds)) . ')');
    }
} catch (Throwable $e) {
    echo 'FAIL cleanup: ' . $e->getMessage() . PHP_EOL;
    $failures++;
}

$unitCountAfter = (int) db()->query('SELECT COUNT(*) FROM polling_units')->fetchColumn();
$fingerprintAfter = $assignmentFingerprint();
$scopeAfter = $scopeFingerprint();
check($unitCountAfter === $unitCountBefore, 'polling unit count unchanged (' . $unitCountBefore . ')');
check($fingerprintAfter === $fingerprintBefore, 'agent assignment fingerprint restored');
check($scopeAfter === $scopeBefore, 'pre-existing scope assignments restored');

echo "\nPhase D scopes: " . ($failures === 0 ? 'all checks passed' : $failures . ' failed') . ($skips > 0 ? ", {$skips} skipped" : '') . ".\n";
exit($failures === 0 ? 0 : 1);
