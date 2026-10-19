<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/scope.php';
require_once dirname(__DIR__) . '/includes/collation.php';

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
    private CurlHandle $ch;

    public function __construct()
    {
        $this->ch = curl_init();
        curl_setopt_array($this->ch, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false]);
    }

    /** @return array{code: int, body: string, location: string} */
    public function req(string $path, ?array $post = null, bool $multipart = false): array
    {
        curl_setopt($this->ch, CURLOPT_URL, BASE . str_replace('.php', '', $path));
        if ($post === null) {
            curl_setopt($this->ch, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($this->ch, CURLOPT_POST, true);
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, $multipart ? $post : http_build_query($post));
        }
        $raw = (string) curl_exec($this->ch);
        $size = (int) curl_getinfo($this->ch, CURLINFO_HEADER_SIZE);
        $headers = substr($raw, 0, $size);

        return [
            'code' => (int) curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE),
            'body' => substr($raw, $size),
            'location' => preg_match('/^Location:\s*(\S+)/mi', $headers, $m) ? $m[1] : '',
        ];
    }

    /** Follows one redirect so the flash message can be read. */
    public function post(string $path, array $post): array
    {
        $first = $this->req($path, $post);
        if ($first['code'] === 302 && $first['location'] !== '') {
            $next = $this->req((string) preg_replace('#^(https?://[^/]+)?/election-monitor-app/#', '', $first['location']));

            return $next + ['first' => $first['code']];
        }

        return $first + ['first' => $first['code']];
    }
}

function token(string $html): string
{
    return preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $html, $m) ? $m[1] : '';
}

function login(Client $c, string $login, string $password): array
{
    $page = $c->req('portal/login');

    return $c->req('portal/login', ['csrf_token' => token($page['body']), 'login' => $login, 'password' => $password]);
}

function test_jpeg(string $label): string
{
    $image = imagecreatetruecolor(320, 180);
    imagefill($image, 0, 0, imagecolorallocate($image, 240, 240, 240));
    imagestring($image, 5, 10, 70, 'TEST SHEET - NOT A RESULT', imagecolorallocate($image, 0, 0, 0));
    imagestring($image, 3, 10, 100, $label . ' ' . bin2hex(random_bytes(4)), imagecolorallocate($image, 120, 0, 0));
    ob_start();
    imagejpeg($image, null, 90);
    imagedestroy($image);

    return (string) ob_get_clean();
}

function upload_sheet(Client $c, int $unitId, string $bytes): array
{
    $page = $c->req('results/entry.php?unit=' . $unitId);
    $tmp = tempnam(sys_get_temp_dir(), 'sheet');
    file_put_contents($tmp, $bytes);
    $response = $c->req('results/entry.php?unit=' . $unitId, [
        'csrf_token' => token($page['body']),
        'intent' => 'upload_sheet',
        'unit_id' => (string) $unitId,
        'result_sheet' => new CURLFile($tmp, 'image/jpeg', 'sheet.jpg'),
    ], true);
    @unlink($tmp);

    return $response;
}

function result_row(int $unitId): array
{
    $s = db()->prepare('SELECT * FROM polling_unit_results WHERE polling_unit_id = :u');
    $s->execute(['u' => $unitId]);

    return $s->fetch() ?: [];
}

function count_where(string $sql, array $params = []): int
{
    $s = db()->prepare($sql);
    $s->execute($params);

    return (int) $s->fetchColumn();
}

/** @param array<int, string|int> $votes */
function figures(string|int $accredited, string|int $rejected, string|int $valid, string|int $total, array $votes): array
{
    return ['accredited_voters' => (string) $accredited, 'rejected_ballots' => (string) $rejected, 'declared_valid_votes' => (string) $valid, 'declared_total_votes' => (string) $total, 'votes' => array_map('strval', $votes)];
}

$max = static fn (string $table): int => (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM ' . $table)->fetchColumn();
$start = [];
foreach (['polling_unit_results', 'result_reviews', 'result_discrepancies', 'result_sheet_uploads', 'result_sheet_events', 'result_sheet_incidents', 'audit_log', 'ward_collations', 'candidates'] as $table) {
    $start[$table] = $max($table);
}
$users = ['rr.agenta' => 'polling_agent', 'rr.agentb' => 'polling_agent', 'rr.admin' => 'admin', 'rr.viewer' => 'viewer', 'rr.officer' => 'collation_officer'];
$pass = [];
$ids = [];
$units = [];
$candidateIds = [];
$wardUnits = [];

try {
    $insert = db()->prepare('INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (:u, :e, :h, :n, :r, 1)');
    foreach ($users as $name => $role) {
        $pass[$name] = bin2hex(random_bytes(10));
        $insert->execute(['u' => $name, 'e' => $name . '@ohaji-egbema.local', 'h' => password_hash($pass[$name], PASSWORD_DEFAULT), 'n' => 'Review Test ' . $name, 'r' => $role]);
        $ids[$name] = (int) db()->lastInsertId();
    }
    $addCandidate = db()->prepare('INSERT INTO candidates (full_name, party, sort_order, is_active) VALUES (:n, :p, :o, 1)');
    foreach (['TSTA' => 'TEST CANDIDATE A', 'TSTB' => 'TEST CANDIDATE B'] as $party => $name) {
        $addCandidate->execute(['n' => $name, 'p' => $party, 'o' => 990 + count($candidateIds)]);
        $candidateIds[] = (int) db()->lastInsertId();
    }
    [$cA, $cB] = $candidateIds;
    $wardId = (int) db()->query(
        'SELECT w.id FROM wards w INNER JOIN polling_units pu ON pu.ward_id = w.id
         WHERE NOT EXISTS (SELECT 1 FROM ward_collations c WHERE c.ward_id = w.id)
         GROUP BY w.id HAVING SUM(pu.is_active = 1 AND pu.agent_user_id IS NULL) >= 2 ORDER BY COUNT(*) LIMIT 1'
    )->fetchColumn();
    $units = db()->query('SELECT id, unit_code FROM polling_units WHERE ward_id = ' . $wardId . ' AND is_active = 1 AND agent_user_id IS NULL ORDER BY id LIMIT 2')->fetchAll();
    [$unitA, $unitB] = [(int) $units[0]['id'], (int) $units[1]['id']];
    $assign = db()->prepare('UPDATE polling_units SET agent_user_id = :agent WHERE id = :unit');
    $assign->execute(['agent' => $ids['rr.agenta'], 'unit' => $unitA]);
    $assign->execute(['agent' => $ids['rr.agentb'], 'unit' => $unitB]);
    if (scope_schema_ready()) {
        $scopeAssign = scope_assign_ward($ids['rr.officer'], $wardId, $ids['rr.admin']);
        if (!$scopeAssign['ok']) {
            throw new RuntimeException('Could not assign ward scope to collation officer: ' . $scopeAssign['error']);
        }
    }

    $agentA = new Client();
    $agentB = new Client();
    $admin = new Client();
    $viewer = new Client();
    $officer = new Client();
    $guest = new Client();
    login($agentA, 'rr.agenta', $pass['rr.agenta']);
    login($agentB, 'rr.agentb', $pass['rr.agentb']);
    login($admin, 'rr.admin', $pass['rr.admin']);
    login($viewer, 'rr.viewer', $pass['rr.viewer']);
    login($officer, 'rr.officer', $pass['rr.officer']);

    echo "-- Phase 2: assigned active units only\n";
    $pageA = $agentA->req('results/entry.php?unit=' . $unitA);
    $tokA = token($pageA['body']);
    check($pageA['code'] === 200 && str_contains($pageA['body'], 'name="declared_valid_votes"') && str_contains($pageA['body'], 'name="declared_total_votes"') && str_contains($pageA['body'], 'Save draft'), 'agent opens own unit with declared-total fields');
    check($agentA->req('results/entry.php?unit=' . $unitB)['code'] === 403, 'agent cannot open another agent\'s unit');
    $crossPost = $agentA->req('results/entry.php?unit=' . $unitB, ['csrf_token' => $tokA, 'unit_id' => (string) $unitB, 'intent' => 'save'] + figures(10, 0, 10, 10, [$cA => 5, $cB => 5]));
    check($crossPost['code'] === 403 && result_row($unitB) === [], 'agent cannot save figures into another unit by POST');
    $agentUser = ['id' => $ids['rr.agenta'], 'role' => 'polling_agent'];
    check(!result_can_edit($agentUser, ['is_active' => 0, 'agent_user_id' => $ids['rr.agenta']], null) && result_can_edit($agentUser, ['is_active' => 1, 'agent_user_id' => $ids['rr.agenta']], null), 'inactive units are not editable even when assigned');
    $badCsrf = $agentA->req('results/entry.php?unit=' . $unitA, ['csrf_token' => str_repeat('ab', 32), 'unit_id' => (string) $unitA, 'intent' => 'save'] + figures(10, 0, 10, 10, [$cA => 5, $cB => 5]));
    check($badCsrf['code'] === 403 && result_row($unitA) === [], 'invalid CSRF token saves nothing');

    echo "-- Phase 2: drafts\n";
    $partial = $agentA->post('results/entry.php?unit=' . $unitA, ['csrf_token' => $tokA, 'unit_id' => (string) $unitA, 'intent' => 'save', 'accredited_voters' => '120', 'rejected_ballots' => '', 'declared_valid_votes' => '', 'declared_total_votes' => '', 'votes' => [$cA => '40', $cB => '']]);
    $row = result_row($unitA);
    check($partial['first'] === 302 && ($row['status'] ?? '') === 'draft' && (int) $row['accredited_voters'] === 120 && $row['rejected_ballots'] === null && $row['declared_valid_votes'] === null, 'incomplete draft is preserved with blanks kept blank');
    $draftVotes = result_votes((int) $row['id']);
    check($draftVotes[$cA] === 40 && $draftVotes[$cB] === null && str_contains($partial['body'], 'value="40"') && str_contains($partial['body'], 'value="120"'), 'draft vote counts reload, blank stays blank (not 0)');
    $inconsistent = $agentA->post('results/entry.php?unit=' . $unitA, ['csrf_token' => $tokA, 'unit_id' => (string) $unitA, 'intent' => 'save'] + figures(120, 5, 90, 95, [$cA => 40, $cB => 30]));
    check(result_row($unitA)['status'] === 'draft' && (int) result_row($unitA)['declared_valid_votes'] === 90 && str_contains($inconsistent['body'], 'Check before submitting') && str_contains($inconsistent['body'], 'add up to 70'), 'inconsistent draft is saved with a warning');
    $before = result_row($unitA);
    foreach (['abc', '-5', '1.5', '1000000', '12 3'] as $bad) {
        $agentA->req('results/entry.php?unit=' . $unitA, ['csrf_token' => $tokA, 'unit_id' => (string) $unitA, 'intent' => 'save'] + figures($bad, 5, 90, 95, [$cA => 40, $cB => 30]));
    }
    check(result_row($unitA)['accredited_voters'] === $before['accredited_voters'] && result_row($unitA)['updated_at'] === $before['updated_at'], 'malformed numbers are rejected even in a draft');

    echo "-- Phase 2: submission arithmetic\n";
    $cases = [
        'blank figure' => figures(120, '', 70, 75, [$cA => 40, $cB => 30]),
        'candidate sum differs from declared valid' => figures(120, 5, 71, 76, [$cA => 40, $cB => 30]),
        'valid + rejected differs from total cast' => figures(120, 5, 70, 80, [$cA => 40, $cB => 30]),
        'total cast exceeds accredited' => figures(70, 5, 70, 75, [$cA => 40, $cB => 30]),
    ];
    foreach ($cases as $label => $data) {
        $response = $agentA->req('results/entry.php?unit=' . $unitA, ['csrf_token' => $tokA, 'unit_id' => (string) $unitA, 'intent' => 'submit'] + $data);
        check($response['code'] !== 302 && result_row($unitA)['status'] === 'draft', 'submission refused: ' . $label);
    }
    $submit = $agentA->post('results/entry.php?unit=' . $unitA, ['csrf_token' => $tokA, 'unit_id' => (string) $unitA, 'intent' => 'submit'] + figures(120, 5, 70, 75, [$cA => 40, $cB => 30]));
    $row = result_row($unitA);
    check($row['status'] === 'submitted' && (int) $row['submitted_by'] === $ids['rr.agenta'] && (int) $row['declared_total_votes'] === 75 && str_contains($submit['body'], 'locked against agent edits'), 'consistent figures submit and lock');

    echo "-- Phase 2: submitted results locked against agents\n";
    $lockedPage = $agentA->req('results/entry.php?unit=' . $unitA);
    check(str_contains($lockedPage['body'], 'locked against agent edits') && !str_contains($lockedPage['body'], 'value="save"') && str_contains($lockedPage['body'], 'readonly'), 'agent sees a read-only locked result');
    $snapshot = result_row($unitA);
    $edit1 = $agentA->req('results/entry.php?unit=' . $unitA, ['csrf_token' => $tokA, 'unit_id' => (string) $unitA, 'intent' => 'save'] + figures(120, 5, 70, 75, [$cA => 50, $cB => 20]));
    $edit2 = $agentA->req('results/entry.php?unit=' . $unitA, ['csrf_token' => $tokA, 'unit_id' => (string) $unitA, 'intent' => 'submit', 'correction_reason' => 'agent tries to correct'] + figures(120, 5, 70, 75, [$cA => 50, $cB => 20]));
    check($edit1['code'] === 403 && $edit2['code'] === 403 && result_row($unitA) == $snapshot && result_votes((int) $snapshot['id'])[$cA] === 40, 'agent save and submit after submission are refused server-side');
    $direct = result_save($unitA, ['accredited_voters' => 1, 'rejected_ballots' => 0, 'declared_valid_votes' => 1, 'declared_total_votes' => 1, 'votes' => [$cA => 1, $cB => 0]], $agentUser, false);
    check(!$direct['ok'] && result_row($unitA)['status'] === 'submitted' && (int) result_row($unitA)['accredited_voters'] === 120, 'lock is re-checked inside the save transaction (stale tab cannot overwrite)');

    echo "-- Phase 3: access control\n";
    $denied = true;
    foreach ([$agentA, $agentB, $viewer, $officer] as $client) {
        $denied = $denied && $client->req('results/reviews.php')['code'] === 403 && $client->req('results/review.php?unit=' . $unitA)['code'] === 403;
    }
    check($denied, 'agents, viewer, and officer cannot open review pages');
    check($guest->req('results/reviews.php')['code'] === 302 && $guest->req('results/review.php?unit=' . $unitA)['code'] === 302, 'guests are sent to login');
    $agentCompare = $agentA->req('results/review.php', ['csrf_token' => $tokA, 'intent' => 'compare', 'unit_id' => (string) $unitA, 'upload_id' => '1', 'figures_sha256' => str_repeat('0', 64)]);
    check($agentCompare['code'] === 403 && count_where('SELECT COUNT(*) FROM result_reviews WHERE id > ' . $start['result_reviews']) === 0, 'agent compare POST refused');
    check($admin->req('results/review.php?unit=abc')['code'] === 404 && $admin->req('results/review.php?unit=999999')['code'] === 404, 'forged unit ids get 404');

    echo "-- Phase 3: comparison with the sheet\n";
    $noSheet = $admin->req('results/review.php?unit=' . $unitA);
    check($noSheet['code'] === 200 && str_contains($noSheet['body'], 'No result sheet has been uploaded') && !str_contains($noSheet['body'], 'Record comparison'), 'no sheet: comparison not offered');
    $sheetBytes = test_jpeg('review');
    upload_sheet($agentA, $unitA, $sheetBytes);
    $uploadA = (int) db()->query('SELECT MAX(id) FROM result_sheet_uploads WHERE polling_unit_id = ' . $unitA)->fetchColumn();
    $page = $admin->req('results/review.php?unit=' . $unitA);
    preg_match('/name="figures_sha256" value="([a-f0-9]{64})"/', $page['body'], $m);
    $sha = $m[1] ?? '';
    $tokAdmin = token($page['body']);
    check($page['code'] === 200 && str_contains($page['body'], 'results/sheet-file?id=' . $uploadA) && str_contains($page['body'], 'entered: <strong>120</strong>') && $sha !== '' && str_contains($page['body'], 'not an official INEC result'), 'review page shows the sheet beside the entered figures');
    $matchSheet = ['accredited_voters' => '120', 'rejected_ballots' => '5', 'declared_valid_votes' => '70', 'declared_total_votes' => '75', 'votes' => [$cA => '40', $cB => '30']];
    $badTok = $admin->req('results/review.php', ['csrf_token' => str_repeat('cd', 32), 'intent' => 'compare', 'unit_id' => (string) $unitA, 'upload_id' => (string) $uploadA, 'figures_sha256' => $sha, 'sheet' => $matchSheet]);
    check(count_where('SELECT COUNT(*) FROM result_reviews WHERE id > ' . $start['result_reviews']) === 0, 'invalid CSRF token records no review');
    $match = $admin->post('results/review.php', ['csrf_token' => $tokAdmin, 'intent' => 'compare', 'unit_id' => (string) $unitA, 'upload_id' => (string) $uploadA, 'figures_sha256' => $sha, 'sheet' => $matchSheet]);
    $state = review_state(result_row($unitA));
    check($state['state'] === 'match' && str_contains($match['body'], 'every figure matches') && count_where("SELECT COUNT(*) FROM audit_log WHERE action = 'review_match' AND actor_user_id = :a", ['a' => $ids['rr.admin']]) === 1, 'matching comparison recorded and audited');

    $adminEntry = $admin->req('results/entry.php?unit=' . $unitA);
    $noReason = $admin->req('results/entry.php?unit=' . $unitA, ['csrf_token' => token($adminEntry['body']), 'unit_id' => (string) $unitA, 'intent' => 'submit', 'correction_reason' => ''] + figures(120, 5, 70, 75, [$cA => 41, $cB => 29]));
    check(result_votes((int) $snapshot['id'])[$cA] === 40, 'admin correction without a reason is refused');
    $admin->req('results/entry.php?unit=' . $unitA, ['csrf_token' => token($adminEntry['body']), 'unit_id' => (string) $unitA, 'intent' => 'submit', 'correction_reason' => 'Agent typed 40 for 41'] + figures(120, 5, 70, 75, [$cA => 41, $cB => 29]));
    check(result_votes((int) $snapshot['id'])[$cA] === 41 && result_row($unitA)['status'] === 'submitted' && count_where("SELECT COUNT(*) FROM audit_log WHERE action = 'correct' AND summary LIKE '%Agent typed 40 for 41%'") === 1, 'admin correction with reason is saved and audited');
    check(review_state(result_row($unitA))['state'] === 'stale', 'correction marks the earlier comparison out of date');

    $stale = $admin->req('results/review.php', ['csrf_token' => $tokAdmin, 'intent' => 'compare', 'unit_id' => (string) $unitA, 'upload_id' => (string) $uploadA, 'figures_sha256' => $sha, 'sheet' => $matchSheet]);
    check(count_where('SELECT COUNT(*) FROM result_reviews WHERE id > ' . $start['result_reviews']) === 1, 'comparison against figures that changed since the page loaded is refused');
    $page = $admin->req('results/review.php?unit=' . $unitA);
    preg_match('/name="figures_sha256" value="([a-f0-9]{64})"/', $page['body'], $m);
    $sha = $m[1] ?? '';
    $diffSheet = ['accredited_voters' => '120', 'rejected_ballots' => '5', 'declared_valid_votes' => '70', 'declared_total_votes' => '', 'votes' => [$cA => '40', $cB => '29']];
    $admin->req('results/review.php', ['csrf_token' => $tokAdmin, 'intent' => 'compare', 'unit_id' => (string) $unitA, 'upload_id' => (string) $uploadA, 'figures_sha256' => $sha, 'sheet' => $diffSheet, 'note' => '']);
    check(count_where('SELECT COUNT(*) FROM result_reviews WHERE id > ' . $start['result_reviews']) === 1, 'discrepancy without a note is refused');
    $admin->req('results/review.php', ['csrf_token' => $tokAdmin, 'intent' => 'compare', 'unit_id' => (string) $unitA, 'upload_id' => (string) $uploadA, 'figures_sha256' => $sha, 'sheet' => ['votes' => [$cA => 'x']] + $diffSheet, 'note' => 'Bad value test']);
    check(count_where('SELECT COUNT(*) FROM result_reviews WHERE id > ' . $start['result_reviews']) === 1, 'non-numeric sheet value is refused');
    $disc = $admin->post('results/review.php', ['csrf_token' => $tokAdmin, 'intent' => 'compare', 'unit_id' => (string) $unitA, 'upload_id' => (string) $uploadA, 'figures_sha256' => $sha, 'sheet' => $diffSheet, 'note' => 'TSTA reads 40 on sheet; total cast smudged']);
    $rows = db()->query('SELECT * FROM result_discrepancies WHERE id > ' . $start['result_discrepancies'] . ' ORDER BY id')->fetchAll();
    $byField = [];
    foreach ($rows as $r) {
        $byField[$r['field'] . ':' . ($r['candidate_id'] ?? '')] = $r;
    }
    check(count($rows) === 2 && (int) $byField['votes:' . $cA]['entered_value'] === 41 && (int) $byField['votes:' . $cA]['sheet_value'] === 40 && $byField['declared_total_votes:']['sheet_value'] === null, 'each differing or unreadable figure is documented with entered and sheet values');
    check(review_state(result_row($unitA))['state'] === 'discrepancy' && result_votes((int) $snapshot['id'])[$cA] === 41 && str_contains($disc['body'], 'Ward collation is blocked'), 'discrepancy recorded without changing entered figures');

    upload_sheet($agentB, $unitB, test_jpeg('b'));
    $uploadB = (int) db()->query('SELECT MAX(id) FROM result_sheet_uploads WHERE polling_unit_id = ' . $unitB)->fetchColumn();
    $page = $admin->req('results/review.php?unit=' . $unitA);
    preg_match('/name="figures_sha256" value="([a-f0-9]{64})"/', $page['body'], $m);
    $before = count_where('SELECT COUNT(*) FROM result_reviews');
    $admin->req('results/review.php', ['csrf_token' => $tokAdmin, 'intent' => 'compare', 'unit_id' => (string) $unitA, 'upload_id' => (string) $uploadB, 'figures_sha256' => $m[1], 'sheet' => $matchSheet]);
    check(count_where('SELECT COUNT(*) FROM result_reviews') === $before, 'a sheet from another polling unit cannot be used');
    $pathA = (string) sheet_stored_path(sheet_find($uploadA));
    @chmod($pathA, 0600);
    file_put_contents($pathA, $sheetBytes . 'x');
    $tamperPage = $admin->req('results/review.php?unit=' . $unitA);
    $admin->req('results/review.php', ['csrf_token' => $tokAdmin, 'intent' => 'compare', 'unit_id' => (string) $unitA, 'upload_id' => (string) $uploadA, 'figures_sha256' => $m[1], 'sheet' => $matchSheet]);
    file_put_contents($pathA, $sheetBytes);
    @chmod($pathA, 0400);
    check(count_where('SELECT COUNT(*) FROM result_reviews') === $before && str_contains($tamperPage['body'], 'withheld'), 'a sheet failing its integrity check cannot be used for comparison');

    echo "-- Phase 3: collation\n";
    $adminUser = ['id' => $ids['rr.admin'], 'role' => 'admin'];
    $wardUnits = db()->query('SELECT id FROM polling_units WHERE ward_id = ' . $wardId . ' AND is_active = 1 AND id NOT IN (' . $unitA . ',' . $unitB . ')')->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_merge([$unitB], array_map('intval', $wardUnits)) as $id) {
        $saved = result_save((int) $id, ['accredited_voters' => 50, 'rejected_ballots' => 2, 'declared_valid_votes' => 40, 'declared_total_votes' => 42, 'votes' => [$cA => 25, $cB => 15]], $adminUser, true);
        if (!$saved['ok']) {
            echo 'setup save failed for ' . $id . ': ' . $saved['message'] . PHP_EOL;
        }
    }
    $blocked = collation_sign_ward($wardId, $ids['rr.admin']);
    check(!$blocked['ok'] && str_contains($blocked['error'], 'open discrepanc') && count_where('SELECT COUNT(*) FROM ward_collations WHERE ward_id = ' . $wardId) === 0, 'ward collation blocked by an open discrepancy');
    check(str_contains(collation_sign_constituency($ids['rr.admin'])['error'], 'open result-sheet discrepanc'), 'constituency sign-off blocked by an open discrepancy');
    $officerWard = $officer->req('collation/ward.php?id=' . $wardId);
    check($officerWard['code'] === 200 && str_contains($officerWard['body'], 'open discrepanc') && str_contains($officerWard['body'], 'Provisional') && !str_contains($officerWard['body'], 'Compare with sheet') && !str_contains($officerWard['body'], 'smudged'), 'collation officer sees the block and provisional label but no sheet links or notes');
    $officerSign = $officer->req('collation/ward.php?id=' . $wardId, ['csrf_token' => token($officerWard['body']), 'intent' => 'collate', 'ward_id' => (string) $wardId]);
    check(count_where('SELECT COUNT(*) FROM ward_collations WHERE ward_id = ' . $wardId) === 0, 'signing through the page is also blocked');
    $index = $officer->req('collation/index.php');
    check(str_contains($index['body'], 'Sheet comparison') && str_contains($index['body'], 'Provisional'), 'collation index shows comparison progress');

    echo "-- Phase 3: resolving discrepancies\n";
    $openId = (int) $byField['votes:' . $cA]['id'];
    $otherId = (int) $byField['declared_total_votes:']['id'];
    $page = $admin->req('results/review.php?unit=' . $unitA);
    $admin->req('results/review.php', ['csrf_token' => $tokAdmin, 'intent' => 'resolve', 'unit_id' => (string) $unitA, 'discrepancy_id' => (string) $openId, 'resolution_note' => 'short']);
    $admin->req('results/review.php', ['csrf_token' => $tokAdmin, 'intent' => 'resolve', 'unit_id' => (string) $unitB, 'discrepancy_id' => (string) $openId, 'resolution_note' => 'Wrong unit for this discrepancy']);
    $admin->req('results/review.php', ['csrf_token' => $tokAdmin, 'intent' => 'resolve', 'unit_id' => (string) $unitA, 'discrepancy_id' => '999999', 'resolution_note' => 'Forged discrepancy identifier']);
    $agentA->req('results/review.php', ['csrf_token' => $tokA, 'intent' => 'resolve', 'unit_id' => (string) $unitA, 'discrepancy_id' => (string) $openId, 'resolution_note' => 'Agent tries to resolve']);
    check(count_where("SELECT COUNT(*) FROM result_discrepancies WHERE status = 'open' AND id > " . $start['result_discrepancies']) === 2, 'short note, wrong unit, forged id, and agent attempts are refused');
    $admin->req('results/review.php', ['csrf_token' => $tokAdmin, 'intent' => 'resolve', 'unit_id' => (string) $unitA, 'discrepancy_id' => (string) $openId, 'resolution_note' => 'Field photo confirms 41; sheet photo is blurred']);
    $admin->req('results/review.php', ['csrf_token' => $tokAdmin, 'intent' => 'resolve', 'unit_id' => (string) $unitA, 'discrepancy_id' => (string) $otherId, 'resolution_note' => 'Total cast confirmed by agent call: 75']);
    $resolved = db()->query('SELECT * FROM result_discrepancies WHERE id = ' . $openId)->fetch();
    check($resolved['status'] === 'resolved' && (int) $resolved['resolved_by'] === $ids['rr.admin'] && $resolved['resolved_at_utc'] !== null && (int) $resolved['entered_value'] === 41 && (int) $resolved['sheet_value'] === 40, 'resolution recorded with reviewer and time; original values kept');
    $again = $admin->post('results/review.php', ['csrf_token' => $tokAdmin, 'intent' => 'resolve', 'unit_id' => (string) $unitA, 'discrepancy_id' => (string) $openId, 'resolution_note' => 'Resolving a second time']);
    check(str_contains($again['body'], 'already resolved') && db()->query('SELECT resolution_note FROM result_discrepancies WHERE id = ' . $openId)->fetchColumn() === 'Field photo confirms 41; sheet photo is blurred', 'a resolved discrepancy cannot be overwritten');
    check(review_state(result_row($unitA))['state'] === 'resolved' && count_where('SELECT COUNT(*) FROM result_reviews WHERE id > ' . $start['result_reviews']) === 2 && count_where('SELECT COUNT(*) FROM result_discrepancies WHERE id > ' . $start['result_discrepancies']) === 2, 'review history and discrepancies are preserved');
    $signed = collation_sign_ward($wardId, $ids['rr.admin']);
    $expectedA = 41 + 25 * (1 + count($wardUnits));
    $snapshotVotes = collation_snapshot_votes((int) db()->query('SELECT id FROM ward_collations WHERE ward_id = ' . $wardId)->fetchColumn());
    check($signed['ok'] && ($snapshotVotes[$cA] ?? -1) === $expectedA, 'after resolution the ward collates with provisional totals from submitted figures');
    $queue = $admin->req('results/reviews.php?state=resolved');
    check($queue['code'] === 200 && str_contains($queue['body'], (string) $units[0]['unit_code']) && str_contains($admin->req('results/reviews.php?state=unreviewed')['body'], (string) $units[1]['unit_code']), 'review queue filters by state');
} finally {
    $rows = db()->query('SELECT * FROM result_sheet_uploads WHERE id > ' . $start['result_sheet_uploads'])->fetchAll();
    foreach ($rows as $row) {
        $path = sheet_stored_path($row);
        if ($path !== null && is_file($path)) {
            @chmod($path, 0600);
            @unlink($path);
        }
    }
    db()->exec('DELETE FROM result_discrepancies WHERE id > ' . $start['result_discrepancies']);
    db()->exec('DELETE FROM result_reviews WHERE id > ' . $start['result_reviews']);
    db()->exec('DELETE FROM ward_collations WHERE id > ' . $start['ward_collations']);
    db()->exec('DELETE FROM polling_unit_results WHERE id > ' . $start['polling_unit_results']);
    db()->exec('DELETE FROM result_sheet_incidents WHERE id > ' . $start['result_sheet_incidents']);
    db()->exec('DELETE FROM result_sheet_events WHERE id > ' . $start['result_sheet_events']);
    db()->exec('DELETE FROM result_sheet_uploads WHERE id > ' . $start['result_sheet_uploads']);
    db()->exec('DELETE FROM audit_log WHERE id > ' . $start['audit_log']);
    db()->exec('DELETE FROM candidates WHERE id > ' . $start['candidates'] . " AND party IN ('TSTA', 'TSTB')");
    foreach ($units as $unit) {
        db()->prepare('UPDATE polling_units SET agent_user_id = NULL WHERE id = :id')->execute(['id' => (int) $unit['id']]);
    }
    if ($ids !== []) {
        $idList = implode(',', array_map('intval', $ids));
        if (scope_schema_ready()) {
            db()->exec('DELETE FROM user_ward_scopes WHERE user_id IN (' . $idList . ')');
            db()->exec('DELETE FROM user_unit_scopes WHERE user_id IN (' . $idList . ')');
        }
        db()->exec('DELETE FROM users WHERE id IN (' . $idList . ')');
    }
    check(count_where("SELECT COUNT(*) FROM users WHERE username LIKE 'rr.%'") === 0 && count_where('SELECT COUNT(*) FROM polling_unit_results') === 0 && count_where("SELECT COUNT(*) FROM candidates WHERE party IN ('TSTA','TSTB')") === 0, 'test users, candidates, and results removed');
}

echo $failures === 0 ? 'ALL PASS' . PHP_EOL : 'FAILURES ' . $failures . PHP_EOL;
exit($failures === 0 ? 0 : 1);
