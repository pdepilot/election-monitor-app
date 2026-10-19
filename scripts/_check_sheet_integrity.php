<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/result_sheets.php';

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

    /** @return array{code: int, body: string, location: string, headers: string} */
    public function req(string $path, ?array $post = null, bool $multipart = false): array
    {
        curl_setopt($this->ch, CURLOPT_URL, str_starts_with($path, 'http') ? $path : BASE . str_replace('.php', '', $path));
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
            'headers' => $headers,
        ];
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

function header_value(array $response, string $name): string
{
    return preg_match('/^' . preg_quote($name, '/') . ':\s*(.+?)\r?$/mi', $response['headers'], $m) ? trim($m[1]) : '';
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

function upload(Client $c, int $unitId, string $bytes, string $kind = '', string $reason = ''): array
{
    $page = $c->req('results/entry.php?unit=' . $unitId);
    $tmp = tempnam(sys_get_temp_dir(), 'sheet');
    file_put_contents($tmp, $bytes);
    $post = [
        'csrf_token' => token($page['body']),
        'intent' => 'upload_sheet',
        'unit_id' => (string) $unitId,
        'result_sheet' => new CURLFile($tmp, 'image/jpeg', 'my sheet.jpg'),
    ];
    if ($kind !== '') {
        $post['upload_kind'] = $kind;
        $post['upload_reason'] = $reason;
    }
    $response = $c->req('results/entry.php?unit=' . $unitId, $post, true);
    @unlink($tmp);

    return $response;
}

function sheet_row(int $id): array
{
    $s = db()->prepare('SELECT * FROM result_sheet_uploads WHERE id = :id');
    $s->execute(['id' => $id]);

    return $s->fetch() ?: [];
}

function latest_sheet(int $unitId): array
{
    $s = db()->prepare('SELECT * FROM result_sheet_uploads WHERE polling_unit_id = :u ORDER BY id DESC LIMIT 1');
    $s->execute(['u' => $unitId]);

    return $s->fetch() ?: [];
}

function count_where(string $sql, array $params): int
{
    $s = db()->prepare($sql);
    $s->execute($params);

    return (int) $s->fetchColumn();
}

function incident(int $id): array
{
    $s = db()->prepare('SELECT * FROM result_sheet_incidents WHERE id = :id');
    $s->execute(['id' => $id]);

    return $s->fetch() ?: [];
}

function incident_event_count(int $id): int
{
    return count_where("SELECT COUNT(*) FROM result_sheet_events WHERE event_type LIKE 'incident\\_%' AND detail LIKE :p", ['p' => 'Incident #' . $id . ' %']);
}

function writable_put(string $path, string $bytes): void
{
    @chmod($path, 0600);
    file_put_contents($path, $bytes);
    @chmod($path, 0400);
}

$keyFile = app_config()['sheet_key_file'];
$keyBackup = (string) file_get_contents($keyFile);
// Key-outage checks temporarily move and replace the active HMAC key file. They run only on
// explicit request, never in routine test runs.
$keyOutage = in_array('--with-key-outage', $argv ?? [], true);
$startUpload = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM result_sheet_uploads')->fetchColumn();
$startEvent = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM result_sheet_events')->fetchColumn();
$startIncident = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM result_sheet_incidents')->fetchColumn();
$startAudit = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();
$legacyDirExisted = is_dir(sheet_legacy_storage_dir());
$users = ['si.agenta' => 'polling_agent', 'si.agentb' => 'polling_agent', 'si.admin' => 'admin', 'si.viewer' => 'viewer', 'si.officer' => 'collation_officer'];
$pass = [];
$ids = [];
$units = [];
$legacyPath = '';

try {
    $insert = db()->prepare('INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (:u, :e, :h, :n, :r, 1)');
    foreach ($users as $name => $role) {
        $pass[$name] = bin2hex(random_bytes(10));
        $insert->execute(['u' => $name, 'e' => $name . '@ohaji-egbema.local', 'h' => password_hash($pass[$name], PASSWORD_DEFAULT), 'n' => 'Integrity Test ' . $name, 'r' => $role]);
        $ids[$name] = (int) db()->lastInsertId();
    }
    $units = db()->query('SELECT id, unit_code FROM polling_units WHERE is_active = 1 AND agent_user_id IS NULL ORDER BY id LIMIT 2')->fetchAll();
    [$unitA, $unitB] = [(int) $units[0]['id'], (int) $units[1]['id']];
    $assign = db()->prepare('UPDATE polling_units SET agent_user_id = :agent WHERE id = :unit');
    $assign->execute(['agent' => $ids['si.agenta'], 'unit' => $unitA]);
    $assign->execute(['agent' => $ids['si.agentb'], 'unit' => $unitB]);

    $agentA = new Client();
    $agentB = new Client();
    $admin = new Client();
    $viewer = new Client();
    $officer = new Client();
    $routeA = login($agentA, 'si.agenta', $pass['si.agenta']);
    login($agentB, 'si.agentb', $pass['si.agentb']);
    $routeAdmin = login($admin, 'si.admin', $pass['si.admin']);
    $routeViewer = login($viewer, 'si.viewer', $pass['si.viewer']);
    $routeOfficer = login($officer, 'si.officer', $pass['si.officer']);

    echo "-- 11. existing routing and pages\n";
    check(str_contains($routeA['location'], '/agent/dashboard'), 'agent routed to agent dashboard');
    check(str_contains($routeAdmin['location'], '/admin/dashboard'), 'admin routed to admin dashboard');
    check(str_contains($routeViewer['location'], '/viewer/dashboard'), 'viewer routed to viewer dashboard');
    check(str_contains($routeOfficer['location'], '/agents/dashboard'), 'collation officer routed to field workspace');
    check($agentA->req('agent/dashboard')['code'] === 200 && $admin->req('admin/dashboard')['code'] === 200, 'dashboards still load');
    $entryA = $agentA->req('results/entry.php?unit=' . $unitA);
    check($entryA['code'] === 200 && (str_contains($entryA['body'], 'Submit result sheet only') || str_contains($entryA['body'], 'Upload result sheet')) && str_contains($entryA['body'], 'name="result_sheet"'), 'agent result entry page loads with upload form');
    if ($entryA['code'] !== 200 || !str_contains($entryA['body'], 'name="result_sheet"')) {
        echo 'DEBUG entry code ' . $entryA['code'] . ' location ' . $entryA['location'] . PHP_EOL;
        echo 'DEBUG ' . substr(strip_tags((string) preg_replace('#<(style|script)[^>]*>.*?</\1>#s', '', $entryA['body'])), -1500) . PHP_EOL;
        throw new RuntimeException('stop');
    }

    echo "-- 1. upload and verification\n";
    $bytesV1 = test_jpeg('v1');
    $up1 = upload($agentA, $unitA, $bytesV1);
    $v1 = latest_sheet($unitA);
    check($up1['code'] === 302 && $v1 !== [], 'agent upload stored');
    $v1Id = (int) ($v1['id'] ?? 0);
    $v1Path = sheet_stored_path($v1);
    check(($v1['sha256'] ?? '') === hash('sha256', $bytesV1), 'recorded SHA-256 equals hash of the exact uploaded bytes');
    check($v1Path !== null && is_file($v1Path) && file_get_contents($v1Path) === $bytesV1, 'stored original is byte-for-byte identical (not re-encoded)');
    check(($v1['storage_area'] ?? '') === 'private' && !str_starts_with(strtolower((string) realpath((string) $v1Path)), strtolower((string) realpath(dirname(__DIR__, 2)))), 'original stored outside the web root');
    check((string) $v1['original_name'] === 'my sheet.jpg' && (string) $v1['stored_name'] !== 'my sheet.jpg' && preg_match(SHEET_STORED_NAME_PATTERN, (string) $v1['stored_name']) === 1, 'random storage name; user filename is display-only');
    check((int) $v1['version_no'] === 1 && (string) $v1['upload_kind'] === 'initial' && (int) $v1['uploaded_by'] === $ids['si.agenta'] && (string) $v1['uploaded_at_utc'] !== '', 'version, uploader, and UTC time recorded');
    check(sheet_row_signature($v1) === 'valid', 'metadata HMAC verifies');
    check(count_where("SELECT COUNT(*) FROM result_sheet_events WHERE upload_id = :id AND event_type = 'upload' AND event_hmac IS NOT NULL", ['id' => $v1Id]) === 1, 'signed upload event recorded');
    $file1 = $agentA->req('results/sheet-file.php?id=' . $v1Id);
    check($file1['code'] === 200 && $file1['body'] === $bytesV1 && header_value($file1, 'X-Sheet-Integrity') === 'VERIFIED', 'unchanged file passes verification and is served exactly');

    echo "-- 5. replacement creates a new version\n";
    $noReason = upload($agentA, $unitA, test_jpeg('noreason'), 'replacement', '');
    check($noReason['code'] === 200 && str_contains($noReason['body'], 'Explain in 5 to 255 characters') && latest_sheet($unitA)['id'] === $v1Id, 'replacement without a reason is refused');
    $bytesV2 = test_jpeg('v2');
    $up2 = upload($agentA, $unitA, $bytesV2, 'replacement', 'Clearer photo of the same sheet');
    $v2 = latest_sheet($unitA);
    $v2Id = (int) $v2['id'];
    check($up2['code'] === 302 && $v2Id !== $v1Id && (int) $v2['version_no'] === 2 && (int) $v2['supersedes_id'] === $v1Id && (string) $v2['upload_kind'] === 'replacement', 'replacement stored as version 2 referencing version 1');
    $v1After = sheet_row($v1Id);
    check($v1After['sha256'] === $v1['sha256'] && $v1After['meta_hmac'] === $v1['meta_hmac'] && file_get_contents((string) $v1Path) === $bytesV1, 'version 1 record and file are untouched');
    check(count_where("SELECT COUNT(*) FROM result_sheet_events WHERE event_type = 'replacement' AND previous_upload_id = :p AND new_upload_id = :n", ['p' => $v1Id, 'n' => $v2Id]) === 1, 'replacement event links previous and new versions');

    echo "-- 4. agents cannot overwrite or delete originals\n";
    $live = $agentA->req('results/live.php');
    check($live['code'] === 403, 'agent cannot open live sheets (hide control)');
    $hide = $agentA->req('results/live.php', ['csrf_token' => token($entryA['body']), 'intent' => 'hide', 'sheet_id' => (string) $v1Id]);
    check($hide['code'] === 403 && (int) sheet_row($v1Id)['is_active'] === 1, 'agent hide/delete POST refused');
    $review = $agentA->req('results/sheet.php?id=' . $v1Id, ['csrf_token' => token($entryA['body']), 'intent' => 'review', 'decision' => 'rejected', 'note' => 'agent tries', 'sheet_id' => (string) $v1Id]);
    check($review['code'] === 403 && (string) sheet_row($v1Id)['review_status'] === 'pending', 'agent review POST refused');
    check(@fopen((string) $v1Path, 'xb') === false, 'exclusive-create storage cannot overwrite an existing original');

    echo "-- 6. cross-agent access\n";
    $cross = $agentB->req('results/sheet-file.php?id=' . $v1Id);
    check($cross['code'] === 403 && !str_contains($cross['body'], substr($bytesV1, 0, 32)), 'agent B cannot fetch agent A sheet by changing id');
    check(count_where("SELECT COUNT(*) FROM result_sheet_events WHERE event_type = 'unauthorized_access' AND actor_user_id = :a AND upload_id = :u", ['a' => $ids['si.agentb'], 'u' => $v1Id]) >= 1, 'unauthorized access attempt recorded');
    $entryB = $agentB->req('results/entry.php?unit=' . $unitB);
    $tmp = tempnam(sys_get_temp_dir(), 'sheet');
    file_put_contents($tmp, test_jpeg('cross'));
    $crossUpload = $agentB->req('results/entry.php?unit=' . $unitA, ['csrf_token' => token($entryB['body']), 'intent' => 'upload_sheet', 'unit_id' => (string) $unitA, 'result_sheet' => new CURLFile($tmp, 'image/jpeg', 'x.jpg')], true);
    @unlink($tmp);
    check($crossUpload['code'] === 403 && latest_sheet($unitA)['id'] === $v2Id, 'agent B cannot upload into agent A unit via URL or POST');
    check($agentB->req('results/sheet.php?id=' . $v1Id)['code'] === 403 && $viewer->req('results/sheet.php?id=' . $v1Id)['code'] === 403, 'agents and viewers cannot open the review page');

    echo "-- 7. direct public URLs\n";
    $guest = new Client();
    check($guest->req('results/sheet-file.php?id=' . $v1Id)['code'] === 401, 'guest gets 401 from the file endpoint');
    check(in_array($guest->req('storage/result-sheets/' . $v1['stored_name'])['code'], [403, 404], true), 'legacy storage URL is blocked');
    check($guest->req('http://localhost/election-monitor-private/result-sheets/' . $v1['stored_name'])['code'] === 404, 'private storage has no URL');
    check($guest->req('http://localhost/election-monitor-private/keys/sheet-hmac.json')['code'] === 404, 'key file has no URL');

    echo "-- 2. one modified byte\n";
    $bytesB = test_jpeg('b1');
    upload($agentB, $unitB, $bytesB);
    $b1 = latest_sheet($unitB);
    $b1Id = (int) $b1['id'];
    $b1Path = (string) sheet_stored_path($b1);
    $flipped = $bytesB;
    $flipped[intdiv(strlen($flipped), 2)] = chr(ord($flipped[intdiv(strlen($flipped), 2)]) ^ 0x01);
    writable_put($b1Path, $flipped);
    $tampered = $admin->req('results/sheet-file.php?id=' . $b1Id);
    check($tampered['code'] === 409 && $tampered['body'] !== $flipped && header_value($tampered, 'X-Sheet-Integrity') === 'INTEGRITY CHECK FAILED', 'one-byte change blocks delivery');
    check(!str_contains($tampered['body'], 'election-monitor-private') && !str_contains($tampered['body'], $b1['stored_name']), 'failure response exposes no path or storage name');
    check(count_where("SELECT COUNT(*) FROM result_sheet_incidents WHERE upload_id = :u AND incident_type = 'content_mismatch' AND status = 'open' AND observed_sha256 = :h", ['u' => $b1Id, 'h' => hash('sha256', $flipped)]) === 1, 'content incident opened with observed hash');
    $detail = $admin->req('results/sheet.php?id=' . $b1Id);
    check($detail['code'] === 200 && str_contains($detail['body'], 'INTEGRITY CHECK FAILED') && str_contains($detail['body'], 'Unresolved integrity incidents') && !str_contains($detail['body'], 'election-monitor-private'), 'reviewer sees failure warning and incident');
    check(file_get_contents($b1Path) === $flipped && sheet_row($b1Id)['sha256'] === hash('sha256', $bytesB), 'suspect file kept and expected hash not updated');
    writable_put($b1Path, $bytesB);
    check(count_where("SELECT COUNT(*) FROM result_sheet_incidents WHERE upload_id = :u AND status = 'open'", ['u' => $b1Id]) === 1, 'incident stays open after the file is restored');

    echo "-- 3. missing file\n";
    rename($b1Path, $b1Path . '.moved');
    $missing = $admin->req('results/sheet-file.php?id=' . $b1Id);
    $missingDetail = $admin->req('results/sheet.php?id=' . $b1Id);
    rename($b1Path . '.moved', $b1Path);
    check($missing['code'] === 409 && header_value($missing, 'X-Sheet-Integrity') === 'MISSING FILE' && str_contains($missingDetail['body'], 'MISSING FILE'), 'missing file is flagged');
    check(count_where("SELECT COUNT(*) FROM result_sheet_incidents WHERE upload_id = :u AND incident_type = 'missing_file'", ['u' => $b1Id]) === 1, 'missing-file incident recorded');

    echo "-- 8. database tampering\n";
    $orig = sheet_row($v2Id);
    db()->prepare('UPDATE result_sheet_uploads SET sha256 = :h WHERE id = :id')->execute(['h' => hash('sha256', 'forged'), 'id' => $v2Id]);
    $forgedHash = $admin->req('results/sheet-file.php?id=' . $v2Id);
    db()->prepare('UPDATE result_sheet_uploads SET sha256 = :h, uploaded_by = :by WHERE id = :id')->execute(['h' => $orig['sha256'], 'by' => $ids['si.agentb'], 'id' => $v2Id]);
    $forgedUploader = $admin->req('results/sheet-file.php?id=' . $v2Id);
    db()->prepare('UPDATE result_sheet_uploads SET uploaded_by = :by, sha256 = NULL WHERE id = :id')->execute(['by' => $orig['uploaded_by'], 'id' => $v2Id]);
    $downgrade = $admin->req('results/sheet-file.php?id=' . $v2Id);
    db()->prepare('UPDATE result_sheet_uploads SET sha256 = :h WHERE id = :id')->execute(['h' => $orig['sha256'], 'id' => $v2Id]);
    check($forgedHash['code'] === 409 && header_value($forgedHash, 'X-Sheet-Integrity') === 'INTEGRITY CHECK FAILED', 'edited hash in the database fails authentication');
    check($forgedUploader['code'] === 409, 'edited uploader in the database fails authentication');
    check($downgrade['code'] === 409 && header_value($downgrade, 'X-Sheet-Integrity') === 'INTEGRITY CHECK FAILED', 'clearing the hash does not downgrade to legacy');
    check(count_where("SELECT COUNT(*) FROM result_sheet_incidents WHERE upload_id = :u AND incident_type = 'metadata_mismatch'", ['u' => $v2Id]) === 1, 'metadata incident recorded once');
    $restored = $admin->req('results/sheet-file.php?id=' . $v2Id);
    check($restored['code'] === 200 && $restored['body'] === $bytesV2, 'restored record verifies again');
    db()->prepare("UPDATE result_sheet_uploads SET review_status = 'approved' WHERE id = :id")->execute(['id' => $v1Id]);
    $reviewForge = $admin->req('results/sheet.php?id=' . $v1Id);
    db()->prepare("UPDATE result_sheet_uploads SET review_status = 'pending' WHERE id = :id")->execute(['id' => $v1Id]);
    check(str_contains($reviewForge['body'], 'does not match the signed event trail'), 'review status edited in the database is flagged');
    $chainBefore = sheet_verify_event_chain();
    $victim = (int) db()->query('SELECT MIN(id) FROM result_sheet_events WHERE id > ' . $startEvent)->fetchColumn();
    $oldDetail = (string) db()->query('SELECT detail FROM result_sheet_events WHERE id = ' . $victim)->fetchColumn();
    db()->prepare('UPDATE result_sheet_events SET detail = :d WHERE id = :id')->execute(['d' => $oldDetail . ' edited', 'id' => $victim]);
    $chainEdited = sheet_verify_event_chain();
    db()->prepare('UPDATE result_sheet_events SET detail = :d WHERE id = :id')->execute(['d' => $oldDetail, 'id' => $victim]);
    check($chainBefore['problems'] === [] && $chainEdited['problems'] !== [], 'edited audit event breaks event verification');
    $hidden = (int) db()->query('SELECT id FROM result_sheet_events WHERE id > ' . $startEvent . ' ORDER BY id LIMIT 1 OFFSET 1')->fetchColumn();
    $saved = db()->query('SELECT * FROM result_sheet_events WHERE id = ' . $hidden)->fetch();
    db()->exec('DELETE FROM result_sheet_events WHERE id = ' . $hidden);
    $chainDeleted = sheet_verify_event_chain();
    $cols = array_keys($saved);
    db()->prepare('INSERT INTO result_sheet_events (' . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ')')->execute($saved);
    check($chainDeleted['problems'] !== [] && sheet_verify_event_chain()['problems'] === [], 'deleted audit event breaks the chain');

    echo "-- 9. missing or invalid key\n";
    if (!$keyOutage) {
        echo "SKIP key-outage checks (run with --with-key-outage on a disposable setup to include them)\n";
    } else {
    rename($keyFile, $keyFile . '.away');
    $noKey = $admin->req('results/sheet-file.php?id=' . $v2Id);
    $noKeyUpload = upload($agentA, $unitA, test_jpeg('nokey'), 'supplement', 'Second page of the sheet');
    $noKeyDetail = $admin->req('results/sheet.php?id=' . $v2Id);
    rename($keyFile . '.away', $keyFile);
    check($noKey['code'] === 409 && header_value($noKey, 'X-Sheet-Integrity') === 'CANNOT VERIFY: KEY UNAVAILABLE', 'missing key: file withheld, not certified');
    check(str_contains($noKeyUpload['body'], 'uploads are paused') && latest_sheet($unitA)['id'] === $v2Id, 'missing key: uploads refused');
    check(str_contains($noKeyDetail['body'], 'CANNOT VERIFY') && !str_contains($noKeyDetail['body'], '>VERIFIED<'), 'missing key: review page shows it cannot verify');
    file_put_contents($keyFile, '{"current":"k1","keys":{"k1":"short"}}');
    $badKey = $admin->req('results/sheet-file.php?id=' . $v2Id);
    file_put_contents($keyFile, json_encode(['current' => (string) $v2['hmac_key_id'], 'keys' => [(string) $v2['hmac_key_id'] => base64_encode(random_bytes(32))]]));
    $wrongKey = $admin->req('results/sheet-file.php?id=' . $v2Id);
    file_put_contents($keyFile, $keyBackup);
    check($badKey['code'] === 409 && header_value($badKey, 'X-Sheet-Integrity') === 'CANNOT VERIFY: KEY UNAVAILABLE', 'invalid key file: fails safely');
    check($wrongKey['code'] === 409 && header_value($wrongKey, 'X-Sheet-Integrity') === 'INTEGRITY CHECK FAILED', 'substituted key with the same id: authentication fails');
    check($admin->req('results/sheet-file.php?id=' . $v2Id)['code'] === 200, 'real key restored: verification works again');
    }

    echo "-- 10. legacy uploads\n";
    $legacyBytes = test_jpeg('legacy');
    $legacyName = bin2hex(random_bytes(16)) . '.jpg';
    if (!is_dir(sheet_legacy_storage_dir())) {
        mkdir(sheet_legacy_storage_dir(), 0700, true);
    }
    $legacyPath = sheet_legacy_storage_dir() . DIRECTORY_SEPARATOR . $legacyName;
    file_put_contents($legacyPath, $legacyBytes);
    db()->prepare('INSERT INTO result_sheet_uploads (polling_unit_id, stored_name, original_name, mime, byte_size, uploaded_by) VALUES (:u, :s, :o, :m, :b, :by)')
        ->execute(['u' => $unitB, 's' => $legacyName, 'o' => 'old.jpg', 'm' => 'image/jpeg', 'b' => strlen($legacyBytes), 'by' => $ids['si.agentb']]);
    $legacyId = (int) db()->lastInsertId();
    $legacyFile = $admin->req('results/sheet-file.php?id=' . $legacyId);
    $legacyDetail = $admin->req('results/sheet.php?id=' . $legacyId);
    check($legacyFile['code'] === 200 && header_value($legacyFile, 'X-Sheet-Integrity') === 'UNVERIFIED LEGACY', 'legacy file served only with UNVERIFIED LEGACY status');
    check(str_contains($legacyDetail['body'], 'UNVERIFIED LEGACY') && str_contains($legacyDetail['body'], 'before integrity protection existed') && !str_contains($legacyDetail['body'], 'Approve version'), 'legacy shown as unverified with warning and no approval');
    $approveLegacy = $admin->req('results/sheet.php?id=' . $legacyId, ['csrf_token' => token($legacyDetail['body']), 'intent' => 'review', 'decision' => 'approved', 'note' => '', 'sheet_id' => (string) $legacyId]);
    check((string) sheet_row($legacyId)['review_status'] === 'pending', 'legacy approval POST refused');
    $cli = (string) shell_exec('"' . PHP_BINARY . '" ' . escapeshellarg(__DIR__ . '/sheet_integrity.php') . ' baseline --id=' . $legacyId . ' --confirm 2>&1');
    $afterBaseline = $admin->req('results/sheet.php?id=' . $legacyId);
    check(str_contains($cli, 'remains UNVERIFIED LEGACY') && sheet_row($legacyId)['legacy_baseline_at_utc'] !== null && str_contains($afterBaseline['body'], 'UNVERIFIED LEGACY') && str_contains($afterBaseline['body'], 'A baseline fingerprint was recorded'), 'baseline recorded with time; still UNVERIFIED LEGACY');
    file_put_contents($legacyPath, $legacyBytes . 'x');
    check($admin->req('results/sheet-file.php?id=' . $legacyId)['code'] === 409, 'legacy file changed after baseline is blocked');
    file_put_contents($legacyPath, $legacyBytes);

    echo "-- review and live views\n";
    $page = $admin->req('results/sheet.php?id=' . $v2Id);
    $rejectNoNote = $admin->req('results/sheet.php?id=' . $v2Id, ['csrf_token' => token($page['body']), 'intent' => 'review', 'decision' => 'rejected', 'note' => '', 'sheet_id' => (string) $v2Id]);
    check((string) sheet_row($v2Id)['review_status'] === 'pending', 'rejection without a reason refused');
    $approve = $admin->req('results/sheet.php?id=' . $v2Id, ['csrf_token' => token($page['body']), 'intent' => 'review', 'decision' => 'approved', 'note' => 'Matches unit', 'sheet_id' => (string) $v2Id]);
    $afterApprove = $admin->req('results/sheet.php?id=' . $v2Id);
    check((string) sheet_row($v2Id)['review_status'] === 'approved' && (int) sheet_row($v2Id)['reviewed_by'] === $ids['si.admin'] && !str_contains($afterApprove['body'], 'does not match the signed event trail'), 'verified version approved and recorded by reviewer');
    check(count_where("SELECT COUNT(*) FROM result_sheet_events WHERE upload_id = :u AND event_type = 'approved' AND actor_user_id = :a AND event_hmac IS NOT NULL", ['u' => $v2Id, 'a' => $ids['si.admin']]) === 1, 'approval event signed');
    $liveAdmin = $admin->req('results/live.php');
    check($liveAdmin['code'] === 200 && str_contains($liveAdmin['body'], 'VERIFIED') && str_contains($liveAdmin['body'], (string) $v2['sha256']) && !str_contains($liveAdmin['body'], 'election-monitor-private'), 'live page shows integrity status and fingerprint');
    $feed = $admin->req('results/live-feed.php?after=' . $startUpload);
    $json = json_decode($feed['body'], true);
    check($feed['code'] === 200 && is_array($json['items'] ?? null) && ($json['items'][0]['integrity'] ?? '') !== '' && !str_contains($feed['body'], 'stored_name') && !str_contains($feed['body'], 'hmac'), 'live feed has integrity fields and no internals');
    check($viewer->req('results/live-feed.php')['code'] === 403 && $viewer->req('results/sheet-file.php?id=' . $v2Id)['code'] === 403, 'viewer still cannot reach sheets');
    $entryAgain = $agentA->req('results/entry.php?unit=' . $unitA);
    check(
        (str_contains($entryAgain['body'], 'Upload new version') || str_contains($entryAgain['body'], 'Submit result sheet only (new version)'))
        && str_contains($entryAgain['body'], 'Version 2')
        && str_contains($entryAgain['body'], 'Version 1'),
        'agent sees own version history and new-version form'
    );

    echo "-- 12. integrity incident management\n";
    $incC = (int) db()->query("SELECT id FROM result_sheet_incidents WHERE upload_id = " . $b1Id . " AND incident_type = 'content_mismatch' ORDER BY id LIMIT 1")->fetchColumn();
    $incK = (int) db()->query("SELECT id FROM result_sheet_incidents WHERE upload_id = " . $v2Id . " AND incident_type = 'key_unavailable' ORDER BY id LIMIT 1")->fetchColumn();
    check($incC > 0 && ($incK > 0 || !$keyOutage), 'incidents from earlier detections exist');
    check(count_where("SELECT COUNT(*) FROM result_sheet_events WHERE event_type = 'integrity_failure' AND detail = :d", ['d' => sheet_incident_opening_detail($incC, 'content_mismatch', hash('sha256', $flipped))]) === 1, 'opening event names the incident id and observed hash');
    $unitBCode = (string) $units[1]['unit_code'];

    $denied = true;
    foreach (['agent A' => $agentA, 'agent B' => $agentB, 'viewer' => $viewer, 'officer' => $officer] as $who => $client) {
        $list = $client->req('results/incidents.php');
        $one = $client->req('results/incident.php?id=' . $incC);
        $denied = $denied && $list['code'] === 403 && $one['code'] === 403
            && !str_contains($list['body'] . $one['body'], $unitBCode) && !str_contains($list['body'] . $one['body'], hash('sha256', $flipped))
            && !str_contains($list['body'] . $one['body'], 'Integrity incidents');
    }
    check($denied, 'agents, viewer, and officer get 403 with no incident data or nav link');
    check(in_array($guest->req('results/incident.php?id=' . $incC)['code'], [302, 303], true) && in_array($guest->req('results/incidents.php')['code'], [302, 303], true), 'guests are sent to login');
    $eventsBefore = (int) db()->query('SELECT COUNT(*) FROM result_sheet_events WHERE event_type LIKE \'incident\\_%\'')->fetchColumn();
    $agentPost = $agentB->req('results/incident.php', ['csrf_token' => token($entryB['body']), 'incident_id' => (string) $incC, 'action' => 'close', 'reason' => 'Agent tries to close it']);
    $viewerPage = $viewer->req('viewer/dashboard');
    $viewerPost = $viewer->req('results/incident.php', ['csrf_token' => token($viewerPage['body']), 'incident_id' => (string) $incC, 'action' => 'acknowledge', 'reason' => 'Viewer tries']);
    check($agentPost['code'] === 403 && $viewerPost['code'] === 403 && incident($incC)['status'] === 'open' && incident_event_count($incC) === 0, 'unauthorized POST with a valid session token is refused server-side');
    check(count_where("SELECT COUNT(*) FROM result_sheet_events WHERE event_type = 'unauthorized_access' AND actor_user_id = :a AND detail LIKE '%incident%'", ['a' => $ids['si.agentb']]) >= 1, 'denied incident access is recorded');

    $listAdmin = $admin->req('results/incidents.php');
    check($listAdmin['code'] === 200 && str_contains($listAdmin['body'], 'Incident #' . $incC) && str_contains($listAdmin['body'], $unitBCode) && str_contains($listAdmin['body'], 'AUTHENTICATED'), 'admin incident list shows unit, id, and authentication');
    $page = $admin->req('results/incident.php?id=' . $incC);
    check($page['code'] === 200 && str_contains($page['body'], 'content_mismatch') && str_contains($page['body'], 'Version 1') && str_contains($page['body'], 'UTC')
        && str_contains($page['body'], 'Verification findings') && str_contains($page['body'], hash('sha256', $flipped)) && str_contains($page['body'], (string) $b1['sha256'])
        && !str_contains($page['body'], 'election-monitor-private'), 'detail shows type, sheet version, detection time, status, and findings');
    $adminToken = token($page['body']);

    $forgedCodes = [];
    foreach (['999999', 'abc', '-1', '0', '1e3', $incC . 'x', '99999999999'] as $forged) {
        $forgedCodes[] = $admin->req('results/incident.php?id=' . rawurlencode($forged))['code'];
    }
    $forgedPost = $admin->req('results/incident.php', ['csrf_token' => $adminToken, 'incident_id' => '999999', 'action' => 'acknowledge', 'reason' => 'Forged identifier']);
    $forgedPost2 = $admin->req('results/incident.php', ['csrf_token' => $adminToken, 'incident_id' => $incC . ' OR 1=1', 'action' => 'acknowledge', 'reason' => 'Forged identifier']);
    check(array_unique($forgedCodes) === [404] && $forgedPost['code'] === 404 && $forgedPost2['code'] === 404 && incident($incC)['status'] === 'open', 'forged incident identifiers get 404 and change nothing');

    $badCsrf = $admin->req('results/incident.php', ['csrf_token' => str_repeat('ab', 32), 'incident_id' => (string) $incC, 'action' => 'acknowledge', 'reason' => 'Valid reason text']);
    $noCsrf = $admin->req('results/incident.php', ['incident_id' => (string) $incC, 'action' => 'close', 'reason' => 'Valid reason text here']);
    check($badCsrf['code'] === 302 && $noCsrf['code'] === 302 && incident($incC)['status'] === 'open' && incident_event_count($incC) === 0, 'invalid or missing CSRF token changes nothing');
    $getAction = $admin->req('results/incident.php?id=' . $incC . '&action=close&reason=closing+via+get+request');
    check($getAction['code'] === 200 && incident($incC)['status'] === 'open', 'GET cannot change incident state');

    $noReason = $admin->req('results/incident.php', ['csrf_token' => $adminToken, 'incident_id' => (string) $incC, 'action' => 'acknowledge', 'reason' => '   ']);
    $shortClose = $admin->req('results/incident.php', ['csrf_token' => $adminToken, 'incident_id' => (string) $incC, 'action' => 'close', 'reason' => 'ok fine']);
    $longReason = $admin->req('results/incident.php', ['csrf_token' => $adminToken, 'incident_id' => (string) $incC, 'action' => 'close', 'reason' => str_repeat('x', 141)]);
    $badAction = $admin->req('results/incident.php', ['csrf_token' => $adminToken, 'incident_id' => (string) $incC, 'action' => 'reopen', 'reason' => 'Valid reason text']);
    check(incident($incC)['status'] === 'open' && incident_event_count($incC) === 0, 'missing, short, or overlong reasons and unknown actions are refused');
    writable_put($b1Path, $flipped);
    check($admin->req('results/sheet-file.php?id=' . $b1Id)['code'] === 409, 'sheet tampered again for the closure test');
    $historyBefore = db()->query('SELECT id, detail, event_hmac, prev_chain FROM result_sheet_events ORDER BY id')->fetchAll();
    $admin->req('results/incident.php', ['csrf_token' => $adminToken, 'incident_id' => (string) $incC, 'action' => 'acknowledge', 'reason' => 'Seen; checking storage logs']);
    $ack = db()->query("SELECT * FROM result_sheet_events WHERE event_type = 'incident_acknowledged' AND detail LIKE 'Incident #" . $incC . " %'")->fetch() ?: [];
    check(incident($incC)['status'] === 'acknowledged' && $ack !== [] && (int) $ack['actor_user_id'] === $ids['si.admin'] && (string) $ack['occurred_at_utc'] !== ''
        && str_contains((string) $ack['detail'], '(open -> acknowledged)') && str_contains((string) $ack['detail'], 'Reason: Seen; checking storage logs')
        && str_contains((string) $ack['detail'], 'integrity now INTEGRITY CHECK FAILED') && sheet_event_signature($ack) === 'valid', 'acknowledge records actor, UTC time, reason, incident, resulting state; event signed');
    $admin->req('results/incident.php', ['csrf_token' => $adminToken, 'incident_id' => (string) $incC, 'action' => 'acknowledge', 'reason' => 'Acknowledge twice']);
    check(incident_event_count($incC) === 1, 'an action cannot be repeated from the wrong state');
    $admin->req('results/incident.php', ['csrf_token' => $adminToken, 'incident_id' => (string) $incC, 'action' => 'investigate', 'reason' => 'Comparing with field photo']);
    check(incident($incC)['status'] === 'investigating', 'incident moved to investigating');
    $admin->req('results/incident.php', ['csrf_token' => $adminToken, 'incident_id' => (string) $incC, 'action' => 'close', 'reason' => 'Server disk fault confirmed; file stays withheld']);
    $closed = incident($incC);
    $closedPage = $admin->req('results/incident.php?id=' . $incC);
    check($closed['status'] === 'closed' && (int) $closed['resolved_by'] === $ids['si.admin'] && (string) $closed['resolved_at_utc'] !== '' && $closed['resolution_note'] === 'Server disk fault confirmed; file stays withheld', 'close records reviewer, UTC time, and explanation');
    check(str_contains($closedPage['body'], 'still withheld') && !str_contains($closedPage['body'], 'name="action"'), 'closed incident page warns the sheet is withheld and offers no actions');
    $stillBlocked = $admin->req('results/sheet-file.php?id=' . $b1Id);
    check($stillBlocked['code'] === 409 && header_value($stillBlocked, 'X-Sheet-Integrity') === 'INTEGRITY CHECK FAILED' && sheet_row($b1Id)['sha256'] === hash('sha256', $bytesB) && file_get_contents($b1Path) === $flipped, 'closing does not change integrity status, expected hash, or the file');
    $reopened = (int) db()->query("SELECT MAX(id) FROM result_sheet_incidents WHERE upload_id = " . $b1Id . " AND incident_type = 'content_mismatch' AND status = 'open'")->fetchColumn();
    check($reopened > $incC && incident($incC)['status'] === 'closed', 'persisting failure opens a new incident; the closed one is preserved');
    $reAct = $admin->req('results/incident.php', ['csrf_token' => $adminToken, 'incident_id' => (string) $incC, 'action' => 'investigate', 'reason' => 'Trying to reopen']);
    check($reAct['code'] === 302 && incident($incC)['status'] === 'closed' && incident_event_count($incC) === 3, 'closed incident refuses further actions');
    $historyAfter = db()->query('SELECT id, detail, event_hmac, prev_chain FROM result_sheet_events WHERE id <= ' . (int) end($historyBefore)['id'] . ' ORDER BY id')->fetchAll();
    check($historyAfter === $historyBefore, 'historical events are unchanged by incident actions');
    check(count_where('SELECT COUNT(*) FROM result_sheet_incidents WHERE id > ' . $startIncident, []) >= 4, 'incident rows are never deleted');

    $admin->req('results/incident.php', ['csrf_token' => $adminToken, 'incident_id' => (string) $reopened, 'action' => 'acknowledge', 'reason' => 'Second detection noted']);
    $ackNew = (int) db()->query("SELECT id FROM result_sheet_events WHERE event_type = 'incident_acknowledged' AND detail LIKE 'Incident #" . $reopened . " %'")->fetchColumn();
    $ackDetail = (string) db()->query('SELECT detail FROM result_sheet_events WHERE id = ' . $ackNew)->fetchColumn();
    db()->prepare('UPDATE result_sheet_events SET detail = :d WHERE id = :id')->execute(['d' => str_replace('Second detection noted', 'Nothing wrong here', $ackDetail), 'id' => $ackNew]);
    $sigPage = $admin->req('results/incident.php?id=' . $reopened);
    $sigPost = $admin->req('results/incident.php', ['csrf_token' => $adminToken, 'incident_id' => (string) $reopened, 'action' => 'close', 'reason' => 'Close a tampered history']);
    db()->prepare('UPDATE result_sheet_events SET detail = :d WHERE id = :id')->execute(['d' => $ackDetail, 'id' => $ackNew]);
    check(str_contains($sigPage['body'], 'SIGNATURE FAILED') && !str_contains($sigPage['body'], 'name="action"') && incident($reopened)['status'] === 'acknowledged', 'edited incident event fails signature verification and blocks actions');
    db()->prepare("UPDATE result_sheet_incidents SET status = 'investigating' WHERE id = :id")->execute(['id' => $reopened]);
    $rowPage = $admin->req('results/incident.php?id=' . $reopened);
    $rowPost = $admin->req('results/incident.php', ['csrf_token' => $adminToken, 'incident_id' => (string) $reopened, 'action' => 'close', 'reason' => 'Close a tampered status']);
    check(str_contains($rowPage['body'], 'STATUS DOES NOT MATCH SIGNED HISTORY') && incident($reopened)['status'] === 'investigating' && incident_event_count($reopened) === 1, 'status edited in the database is flagged and actions refused');
    db()->prepare("UPDATE result_sheet_incidents SET status = 'closed' WHERE id = :id")->execute(['id' => $reopened]);
    check(str_contains($admin->req('results/incident.php?id=' . $reopened)['body'], 'STATUS DOES NOT MATCH SIGNED HISTORY'), 'silently closing an incident in the database is flagged');
    db()->prepare("UPDATE result_sheet_incidents SET status = 'acknowledged' WHERE id = :id")->execute(['id' => $reopened]);
    db()->prepare("INSERT INTO result_sheet_incidents (upload_id, incident_type, observed_sha256, detected_by, detected_at_utc) VALUES (:u, 'missing_file', NULL, NULL, :t)")->execute(['u' => $b1Id, 't' => sheet_utc_now()]);
    $fakeInc = (int) db()->lastInsertId();
    $fakePage = $admin->req('results/incident.php?id=' . $fakeInc);
    $fakePost = $admin->req('results/incident.php', ['csrf_token' => $adminToken, 'incident_id' => (string) $fakeInc, 'action' => 'close', 'reason' => 'Close a forged row']);
    check(str_contains($fakePage['body'], 'NO MATCHING OPENING RECORD') && !str_contains($fakePage['body'], '>AUTHENTICATED<') && incident($fakeInc)['status'] === 'open', 'incident row without a signed opening event is not authenticated');

    if ($keyOutage) {
    $kPage = $admin->req('results/incident.php?id=' . $incK);
    check(str_contains($kPage['body'], 'OPENING RECORD UNSIGNED') && !str_contains($kPage['body'], '>AUTHENTICATED<'), 'incident opened during a key outage is labelled unsigned, never authenticated');

    rename($keyFile, $keyFile . '.away');
    $noKeyPage = $admin->req('results/incident.php?id=' . $reopened);
    $noKeyList = $admin->req('results/incidents.php');
    $noKeyPost = $admin->req('results/incident.php', ['csrf_token' => $adminToken, 'incident_id' => (string) $reopened, 'action' => 'close', 'reason' => 'Close without the key']);
    rename($keyFile . '.away', $keyFile);
    check(str_contains($noKeyPage['body'], 'KEY UNAVAILABLE') && !str_contains($noKeyPage['body'], '>AUTHENTICATED<') && !str_contains($noKeyList['body'], '>AUTHENTICATED<'), 'missing key: incidents shown as not authenticated');
    check(incident($reopened)['status'] === 'acknowledged' && incident_event_count($reopened) === 1, 'missing key: incident actions refused');
    }
    $sheetPage = $admin->req('results/sheet.php?id=' . $b1Id);
    check(str_contains($sheetPage['body'], 'results/incident?id=' . $reopened) || str_contains($sheetPage['body'], 'results/incident.php?id=' . $reopened), 'sheet page links to its unresolved incident');
    writable_put($b1Path, $bytesB);

    echo "-- CLI verify\n";
    $verify = (string) shell_exec('"' . PHP_BINARY . '" ' . escapeshellarg(__DIR__ . '/sheet_integrity.php') . ' verify 2>&1');
    check(!preg_match('/^Record \d+:/m', $verify) && str_contains($verify, 'VERIFIED: ') && str_contains($verify, 'UNVERIFIED LEGACY: 1'), 'CLI verify: every restored upload passes, legacy counted separately');
    if ($keyOutage) {
        check(str_contains($verify, 'signature unsigned') && str_contains($verify, 'INTEGRITY PROBLEMS FOUND'), 'CLI verify flags the unsigned events written during the key outage');
    } else {
        check(str_contains($verify, 'RESULT: no integrity problems found'), 'CLI verify: event chain and restored uploads report no integrity problems');
    }
    $checkpointRaw = (string) shell_exec('"' . PHP_BINARY . '" ' . escapeshellarg(__DIR__ . '/sheet_integrity.php') . ' checkpoint 2>&1');
    $checkpoint = json_decode($checkpointRaw, true);
    $realKeys = json_decode($keyBackup, true)['keys'] ?? [];
    $leak = false;
    foreach ($realKeys as $material) {
        $leak = $leak || str_contains($checkpointRaw, (string) $material);
    }
    check(is_array($checkpoint) && strlen((string) $checkpoint['hmac']) === 64 && $realKeys !== [] && !$leak, 'signed checkpoint produced without exposing key material');
} finally {
    if ((string) @file_get_contents($keyFile) !== $keyBackup) {
        if (is_file($keyFile . '.away')) {
            @unlink($keyFile);
            rename($keyFile . '.away', $keyFile);
        }
        file_put_contents($keyFile, $keyBackup);
    }
    $rows = db()->query('SELECT * FROM result_sheet_uploads WHERE id > ' . $startUpload)->fetchAll();
    foreach ($rows as $row) {
        $path = sheet_stored_path($row);
        foreach ([$path, $path . '.moved'] as $candidate) {
            if ($candidate !== null && is_file($candidate)) {
                @chmod($candidate, 0600);
                @unlink($candidate);
            }
        }
    }
    db()->exec('DELETE FROM result_sheet_incidents WHERE id > ' . $startIncident);
    db()->exec('DELETE FROM result_sheet_events WHERE id > ' . $startEvent);
    db()->exec('DELETE FROM result_sheet_uploads WHERE id > ' . $startUpload);
    db()->exec('DELETE FROM audit_log WHERE id > ' . $startAudit);
    foreach ($units as $unit) {
        db()->prepare('UPDATE polling_units SET agent_user_id = NULL WHERE id = :id')->execute(['id' => (int) $unit['id']]);
    }
    if ($ids !== []) {
        db()->exec('DELETE FROM users WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
    }
    if (!$legacyDirExisted && is_dir(sheet_legacy_storage_dir())) {
        @rmdir(sheet_legacy_storage_dir());
    }
    check(count_where("SELECT COUNT(*) FROM users WHERE username LIKE 'si.%'", []) === 0 && count_where('SELECT COUNT(*) FROM result_sheet_uploads WHERE id > ' . $startUpload, []) === 0, 'test accounts and test uploads removed');
}

echo $failures === 0 ? 'ALL PASS' . PHP_EOL : 'FAILURES ' . $failures . PHP_EOL;
exit($failures === 0 ? 0 : 1);
