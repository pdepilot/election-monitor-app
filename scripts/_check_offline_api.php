<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/result_submissions.php';

const BASE = 'http://localhost/election-monitor-app/';
$failures = 0;

function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

final class Client
{
    public CurlHandle $ch;
    public string $csrf = '';

    public function __construct()
    {
        $this->ch = curl_init();
        curl_setopt_array($this->ch, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false]);
    }

    public function req(string $path, ?string $body = null, array $headers = [], string $method = ''): array
    {
        curl_setopt($this->ch, CURLOPT_URL, BASE . $path);
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $method !== '' ? $method : null);
        if ($body === null) {
            curl_setopt($this->ch, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($this->ch, CURLOPT_POST, true);
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = (string) curl_exec($this->ch);
        $size = (int) curl_getinfo($this->ch, CURLINFO_HEADER_SIZE);

        return ['code' => (int) curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size), 'json' => json_decode(substr($raw, $size), true)];
    }

    public function login(string $login, string $password): void
    {
        $page = $this->req('portal/login');
        preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $page['body'], $m);
        $this->req('portal/login', http_build_query(['csrf_token' => $m[1] ?? '', 'login' => $login, 'password' => $password]));
        $status = $this->req('results/api-status');
        $this->csrf = (string) ($status['json']['csrf_token'] ?? '');
    }

    public function submit(array $payload, ?string $csrf = null): array
    {
        return $this->req('results/api-submit', json_encode($payload), ['Content-Type: application/json', 'X-CSRF-Token: ' . ($csrf ?? $this->csrf)]);
    }
}

function uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function payload(string $id, int $unit, array $votes, int $accredited = 120, int $rejected = 5): array
{
    $valid = array_sum($votes);

    return [
        'schema_version' => 1,
        'submission_id' => $id,
        'polling_unit_id' => $unit,
        'client_created_at' => gmdate('Y-m-d\TH:i:s') . '.000Z',
        'figures' => ['accredited_voters' => $accredited, 'rejected_ballots' => $rejected, 'declared_valid_votes' => $valid, 'declared_total_votes' => $valid + $rejected],
        'votes' => array_map('intval', $votes),
    ];
}

function n(string $sql, array $params = []): int
{
    $s = db()->prepare($sql);
    $s->execute($params);

    return (int) $s->fetchColumn();
}

$max = static fn (string $table): int => (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM ' . $table)->fetchColumn();
$start = [];
foreach (['polling_unit_results', 'result_submissions', 'audit_log', 'candidates', 'result_reviews', 'result_discrepancies'] as $table) {
    $start[$table] = $max($table);
}
$users = ['oa.agenta' => 'polling_agent', 'oa.agentb' => 'polling_agent', 'oa.agentc' => 'polling_agent', 'oa.admin' => 'admin', 'oa.viewer' => 'viewer'];
$pass = [];
$ids = [];
$units = [];
$unitBackup = [];

try {
    $insert = db()->prepare('INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (:u, :e, :h, :n, :r, 1)');
    foreach ($users as $name => $role) {
        $pass[$name] = bin2hex(random_bytes(10));
        $insert->execute(['u' => $name, 'e' => $name . '@ohaji-egbema.local', 'h' => password_hash($pass[$name], PASSWORD_DEFAULT), 'n' => 'Offline Test ' . $name, 'r' => $role]);
        $ids[$name] = (int) db()->lastInsertId();
    }
    $cands = [];
    foreach (['TSTA' => 'TEST CANDIDATE A', 'TSTB' => 'TEST CANDIDATE B'] as $party => $name) {
        db()->prepare('INSERT INTO candidates (full_name, party, sort_order, is_active) VALUES (:n, :p, 990, 1)')->execute(['n' => $name, 'p' => $party]);
        $cands[] = (int) db()->lastInsertId();
    }
    [$cA, $cB] = $cands;
    $units = db()->query('SELECT id, unit_code, is_active, agent_user_id, updated_at FROM polling_units WHERE is_active = 1 AND agent_user_id IS NULL ORDER BY id DESC LIMIT 3')->fetchAll();
    foreach ($units as $unit) {
        $unitBackup[(int) $unit['id']] = $unit;
    }
    [$uA, $uB, $uC] = array_map(static fn (array $u): int => (int) $u['id'], $units);
    $assign = db()->prepare('UPDATE polling_units SET agent_user_id = :a, updated_at = updated_at WHERE id = :u');
    $assign->execute(['a' => $ids['oa.agenta'], 'u' => $uA]);
    $assign->execute(['a' => $ids['oa.agentb'], 'u' => $uB]);
    $assign->execute(['a' => $ids['oa.agentc'], 'u' => $uC]);

    $a = new Client();
    $a->login('oa.agenta', $pass['oa.agenta']);
    $b = new Client();
    $b->login('oa.agentb', $pass['oa.agentb']);
    $b2 = new Client();
    $b2->login('oa.agentb', $pass['oa.agentb']);
    $c = new Client();
    $c->login('oa.agentc', $pass['oa.agentc']);
    $admin = new Client();
    $admin->login('oa.admin', $pass['oa.admin']);
    $viewer = new Client();
    $viewer->login('oa.viewer', $pass['oa.viewer']);
    $guest = new Client();
    $votesOk = [$cA => 40, $cB => 30];

    echo "-- request gate\n";
    check($guest->submit(payload(uuid(), $uA, $votesOk), str_repeat('a', 64))['json']['code'] === 'auth_required' && $guest->submit(payload(uuid(), $uA, $votesOk))['code'] === 401, 'no session: 401 auth_required');
    check($a->req('results/api-submit')['code'] === 405, 'GET on submit: 405');
    check($a->req('results/api-submit', json_encode(payload(uuid(), $uA, $votesOk)), ['Content-Type: application/x-www-form-urlencoded', 'X-CSRF-Token: ' . $a->csrf])['code'] === 415, 'form-encoded body refused (415)');
    $big = payload(uuid(), $uA, $votesOk);
    $big['client_created_at'] = str_repeat('x', 20000);
    check($a->submit($big)['code'] === 413, 'oversized body refused (413)');
    $roleA = $admin->submit(payload(uuid(), $uA, $votesOk));
    $roleV = $viewer->submit(payload(uuid(), $uA, $votesOk));
    check($roleA['code'] === 403 && $roleA['json']['code'] === 'role_forbidden' && $roleV['json']['code'] === 'role_forbidden', 'admin and viewer cannot use the agent submit API');
    $noCsrf = $a->req('results/api-submit', json_encode(payload(uuid(), $uA, $votesOk)), ['Content-Type: application/json']);
    $badCsrf = $a->submit(payload(uuid(), $uA, $votesOk), str_repeat('b', 64));
    check($noCsrf['json']['code'] === 'csrf_invalid' && $badCsrf['code'] === 403 && $badCsrf['json']['code'] === 'csrf_invalid' && n('SELECT COUNT(*) FROM polling_unit_results WHERE polling_unit_id = :u', ['u' => $uA]) === 0, 'missing or wrong CSRF token: 403, nothing stored');

    echo "-- status snapshot\n";
    $snap = $a->req('results/api-status');
    $snapUnits = array_column($snap['json']['units'] ?? [], 'id');
    check($snap['code'] === 200 && $snapUnits === [$uA] && strlen((string) $snap['json']['csrf_token']) === 64 && count($snap['json']['units'][0]['candidates']) === 2 && str_contains((string) $snap['json']['notice'], 'Not an official INEC result'), 'status lists only the agent\'s own unit, candidates, token, and notice');
    check(str_contains($snap['headers'], 'no-store') && !str_contains($snap['body'], 'password'), 'status response is not cacheable and holds no secrets beyond the session token');
    check($admin->req('results/api-status')['code'] === 403 && $guest->req('results/api-status')['code'] === 401 && $a->req('results/api-status?ids=not-a-uuid')['code'] === 400, 'status: other roles 403, guests 401, bad ids 400');

    echo "-- structure and values\n";
    $extra = payload(uuid(), $uA, $votesOk) + ['status' => 'accepted'];
    check($a->submit($extra)['json']['code'] === 'bad_request', 'unexpected fields refused (cannot self-assign a state)');
    $badId = payload('not-a-uuid', $uA, $votesOk);
    check($a->submit($badId)['json']['code'] === 'bad_request', 'malformed submission ID refused');
    $v2 = payload(uuid(), $uA, $votesOk);
    $v2['schema_version'] = 2;
    check($a->submit($v2)['json']['code'] === 'unsupported_schema', 'unknown schema version refused');
    $str = payload(uuid(), $uA, $votesOk);
    $str['figures']['accredited_voters'] = '120';
    $neg = payload(uuid(), $uA, $votesOk);
    $neg['votes'][$cA] = -1;
    $float = payload(uuid(), $uA, $votesOk);
    $float['figures']['rejected_ballots'] = 5.5;
    check($a->submit($str)['code'] === 422 && $a->submit($neg)['code'] === 422 && $a->submit($float)['code'] === 422, 'string, negative, and fractional counts refused (422)');
    $sumBad = payload(uuid(), $uA, $votesOk);
    $sumBad['figures']['declared_valid_votes'] = 71;
    $sumResp = $a->submit($sumBad);
    check($sumResp['code'] === 422 && $sumResp['json']['code'] === 'validation_failed' && str_contains($sumResp['json']['message'], 'add up') && n('SELECT COUNT(*) FROM polling_unit_results WHERE polling_unit_id = :u', ['u' => $uA]) === 0, 'existing arithmetic rules apply server-side');
    $over = payload(uuid(), $uA, $votesOk, 60);
    check($a->submit($over)['json']['code'] === 'validation_failed', 'total votes above accredited voters refused');

    echo "-- authorization and configuration\n";
    $otherUnit = $a->submit(payload(uuid(), $uB, $votesOk));
    $ghostUnit = $a->submit(payload(uuid(), 999999, $votesOk));
    check($otherUnit['code'] === 403 && $otherUnit['json']['code'] === 'unit_not_assigned' && $ghostUnit['json']['code'] === 'unit_not_assigned' && n('SELECT COUNT(*) FROM polling_unit_results WHERE polling_unit_id = :u', ['u' => $uB]) === 0, 'other agent\'s unit and unknown units give the same 403 (no enumeration)');
    $extraCand = $a->submit(payload(uuid(), $uA, $votesOk + [999999 => 0]));
    $missingCand = $a->submit(payload(uuid(), $uA, [$cA => 70]));
    check($extraCand['code'] === 409 && $extraCand['json']['code'] === 'candidates_changed' && $missingCand['json']['code'] === 'candidates_changed', 'unknown or missing candidates refused as candidates_changed');

    echo "-- accepted submission and idempotency\n";
    $sid = uuid();
    $first = $a->submit(payload($sid, $uA, $votesOk));
    $row = db()->query('SELECT * FROM polling_unit_results WHERE polling_unit_id = ' . $uA)->fetch();
    check($first['code'] === 201 && $first['json']['code'] === 'received' && $first['json']['state'] === 'under_review' && $first['json']['replayed'] === false && ($row['status'] ?? '') === 'submitted' && (int) $row['submitted_by'] === $ids['oa.agenta'], 'first send: 201 received, result submitted under the existing rules');
    check(str_contains($first['json']['message'], 'Not an official INEC result') && !isset($first['json']['verified']), 'response is labelled provisional and makes no verification claim');
    check(n("SELECT COUNT(*) FROM audit_log WHERE action = 'offline_submit' AND summary LIKE :s", ['s' => '%' . $sid . '%']) === 1 && n("SELECT COUNT(*) FROM audit_log WHERE action = 'submit' AND entity_id = :r", ['r' => (int) $row['id']]) === 1, 'audit records the submit and the device submission ID');
    $retries = [];
    for ($i = 0; $i < 3; $i++) {
        $retries[] = $a->submit(payload($sid, $uA, $votesOk));
    }
    $sameBody = true;
    foreach ($retries as $retry) {
        $sameBody = $sameBody && $retry['code'] === 200 && $retry['json']['replayed'] === true && $retry['json']['received_at'] === $first['json']['received_at'];
    }
    check($sameBody && n('SELECT COUNT(*) FROM result_submissions WHERE submission_id = :s', ['s' => $sid]) === 1 && n('SELECT COUNT(*) FROM polling_unit_results WHERE polling_unit_id = :u', ['u' => $uA]) === 1, 'retries (lost response) replay the original outcome; no duplicate records');
    $changed = $a->submit(payload($sid, $uA, [$cA => 41, $cB => 29]));
    check($changed['code'] === 409 && $changed['json']['code'] === 'submission_id_reused' && result_votes((int) $row['id'])[$cA] === 40, 'same ID with different figures refused; server data unchanged');
    $stolen = $b->submit(payload($sid, $uB, $votesOk));
    check($stolen['code'] === 409 && $stolen['json']['code'] === 'submission_id_conflict' && n('SELECT COUNT(*) FROM polling_unit_results WHERE polling_unit_id = :u', ['u' => $uB]) === 0, 'another agent reusing the ID is refused without revealing anything');
    $secondDevice = $a->submit(payload(uuid(), $uA, [$cA => 50, $cB => 20]));
    check($secondDevice['code'] === 409 && $secondDevice['json']['code'] === 'already_submitted' && result_votes((int) $row['id'])[$cA] === 40, 'a different submission for an already-submitted unit never overwrites it');

    echo "-- concurrency\n";
    $sidB = uuid();
    $mh = curl_multi_init();
    $handles = [];
    foreach ([$b, $b2, $b, $b2] as $i => $client) {
        $cookies = [];
        foreach (curl_getinfo($client->ch, CURLINFO_COOKIELIST) as $line) {
            $parts = explode("\t", $line);
            $cookies[] = $parts[5] . '=' . $parts[6];
        }
        $h = curl_init();
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => implode('; ', $cookies), CURLOPT_URL => BASE . 'results/api-submit', CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(payload($sidB, $uB, $votesOk)), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $client->csrf], CURLOPT_HEADER => false, CURLOPT_HTTPGET => false]);
        curl_multi_add_handle($mh, $h);
        $handles[] = $h;
    }
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);
    } while ($running > 0);
    $codes = array_map(static fn ($h): int => (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE), $handles);
    sort($codes);
    check($codes === [200, 200, 200, 201] && n('SELECT COUNT(*) FROM result_submissions WHERE submission_id = :s', ['s' => $sidB]) === 1 && n('SELECT COUNT(*) FROM polling_unit_results WHERE polling_unit_id = :u', ['u' => $uB]) === 1, 'four simultaneous sends of one submission from two sessions: one 201, three replays, one record');

    echo "-- assignment and unit changes\n";
    db()->prepare('UPDATE polling_units SET is_active = 0, updated_at = updated_at WHERE id = :u')->execute(['u' => $uC]);
    $inactive = $c->submit(payload(uuid(), $uC, $votesOk));
    db()->prepare('UPDATE polling_units SET is_active = 1, updated_at = updated_at WHERE id = :u')->execute(['u' => $uC]);
    check($inactive['code'] === 409 && $inactive['json']['code'] === 'unit_inactive' && n('SELECT COUNT(*) FROM polling_unit_results WHERE polling_unit_id = :u', ['u' => $uC]) === 0, 'deactivated unit refused (409 unit_inactive)');
    db()->prepare('UPDATE polling_units SET agent_user_id = NULL, updated_at = updated_at WHERE id = :u')->execute(['u' => $uC]);
    $revoked = $c->submit(payload(uuid(), $uC, $votesOk));
    $revokedStatus = $c->req('results/api-status');
    db()->prepare('UPDATE polling_units SET agent_user_id = :a, updated_at = updated_at WHERE id = :u')->execute(['a' => $ids['oa.agentc'], 'u' => $uC]);
    check($revoked['code'] === 403 && $revoked['json']['code'] === 'unit_not_assigned' && ($revokedStatus['json']['units'] ?? null) === [], 'revoked assignment refused on submit and dropped from the status snapshot');
    db()->prepare('UPDATE candidates SET is_active = 0 WHERE id = :id')->execute(['id' => $cB]);
    $configChanged = $c->submit(payload(uuid(), $uC, $votesOk));
    db()->prepare('UPDATE candidates SET is_active = 1 WHERE id = :id')->execute(['id' => $cB]);
    check($configChanged['json']['code'] === 'candidates_changed' && strlen((string) $configChanged['json']['config_fingerprint']) === 64, 'candidate deactivated before sync: refused with the new configuration fingerprint');

    echo "-- server status after receipt\n";
    $status = $a->req('results/api-status?ids=' . $sid . ',' . $sidB);
    check(($status['json']['submissions'][$sid]['state'] ?? '') === 'under_review' && !isset($status['json']['submissions'][$sidB]), 'status reports only the agent\'s own submissions, as under review');
    $adminUser = ['id' => $ids['oa.admin'], 'role' => 'admin'];
    result_save($uA, ['accredited_voters' => 120, 'rejected_ballots' => 5, 'declared_valid_votes' => 70, 'declared_total_votes' => 75, 'votes' => [$cA => 41, $cB => 29]], $adminUser, true, 'Reviewer correction test');
    $after = $a->req('results/api-status?ids=' . $sid);
    check(($after['json']['submissions'][$sid]['figures_changed'] ?? false) === true && $after['json']['submissions'][$sid]['state'] === 'under_review', 'a later reviewer correction is reported as changed figures, never as accepted');

    echo "-- rate limit, session end, and leaks\n";
    $limited = null;
    for ($i = 0; $i < 65; $i++) {
        $r = $c->req('results/api-status');
        if ($r['code'] === 429) {
            $limited = $r;
            break;
        }
    }
    check($limited !== null && preg_match('/^Retry-After:\s*\d+/mi', $limited['headers']) === 1 && $limited['json']['code'] === 'rate_limited', 'rate limit returns 429 with Retry-After');
    $logoutPage = $a->req('agent/dashboard');
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $logoutPage['body'], $m);
    $a->req('portal/logout', http_build_query(['csrf_token' => $m[1] ?? '']));
    $expired = $a->submit(payload(uuid(), $uA, $votesOk));
    check($expired['code'] === 401 && $expired['json']['code'] === 'auth_required', 'after sign-out the queued send gets 401 auth_required');
    $all = implode("\n", array_map(static fn (array $r): string => $r['body'], [$first, $changed, $stolen, $inactive, $revoked, $sumResp, $extraCand]));
    check(!str_contains($all, 'SQLSTATE') && !str_contains($all, 'C:\\') && !str_contains($all, 'xampp') && !str_contains($all, 'hmac'), 'error bodies leak no SQL, paths, or key material');
} finally {
    db()->exec('DELETE FROM result_submissions WHERE id > ' . $start['result_submissions']);
    db()->exec('DELETE FROM result_discrepancies WHERE id > ' . $start['result_discrepancies']);
    db()->exec('DELETE FROM result_reviews WHERE id > ' . $start['result_reviews']);
    db()->exec('DELETE FROM polling_unit_results WHERE id > ' . $start['polling_unit_results']);
    db()->exec('DELETE FROM audit_log WHERE id > ' . $start['audit_log']);
    db()->exec('DELETE FROM candidates WHERE id > ' . $start['candidates'] . " AND party IN ('TSTA', 'TSTB')");
    foreach ($unitBackup as $id => $unit) {
        db()->prepare('UPDATE polling_units SET agent_user_id = NULL, is_active = :active, updated_at = :updated WHERE id = :id')
            ->execute(['active' => (int) $unit['is_active'], 'updated' => $unit['updated_at'], 'id' => $id]);
    }
    if ($ids !== []) {
        db()->exec('DELETE FROM users WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
    }
    check(n("SELECT COUNT(*) FROM users WHERE username LIKE 'oa.%'") === 0 && n('SELECT COUNT(*) FROM polling_unit_results') === 0 && n('SELECT COUNT(*) FROM result_submissions') === 0 && n('SELECT COUNT(*) FROM polling_units WHERE agent_user_id IS NOT NULL') === 0, 'fixtures removed and polling units restored');
}

echo $failures === 0 ? 'ALL PASS' . PHP_EOL : 'FAILURES ' . $failures . PHP_EOL;
exit($failures === 0 ? 0 : 1);
