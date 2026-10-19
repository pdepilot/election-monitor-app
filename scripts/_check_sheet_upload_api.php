<?php
declare(strict_types=1);

// Phase E: idempotent result-sheet-only upload API for polling agents.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/sheet_upload_api.php';

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

final class SuClient
{
    public CurlHandle $ch;
    public string $csrf = '';

    public function __construct()
    {
        $this->ch = curl_init();
        curl_setopt_array($this->ch, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false]);
    }

    public function req(string $path, $body = null, array $headers = []): array
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
            'json' => json_decode(substr($raw, $size), true),
        ];
    }

    public function login(string $login, string $password): void
    {
        $page = $this->req('portal/login');
        preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $page['body'], $m);
        $this->req('portal/login', http_build_query(['csrf_token' => $m[1] ?? '', 'login' => $login, 'password' => $password]));
        $status = $this->req('results/api-status');
        $this->csrf = (string) ($status['json']['csrf_token'] ?? '');
    }

    public function upload(array $fields, string $bytes, string $name = 'sheet.jpg'): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'su');
        file_put_contents($tmp, $bytes);
        $post = $fields + [
            'result_sheet' => new CURLFile($tmp, 'image/jpeg', $name),
        ];
        $response = $this->req('results/api-sheet-upload', $post, ['X-CSRF-Token: ' . $this->csrf]);
        @unlink($tmp);

        return $response;
    }
}

function uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function test_jpeg(string $seed): string
{
    $im = imagecreatetruecolor(32, 32);
    $color = imagecolorallocate($im, (ord($seed[0]) * 7) % 255, 40, 120);
    imagefilledrectangle($im, 0, 0, 31, 31, $color);
    ob_start();
    imagejpeg($im, null, 90);
    imagedestroy($im);

    return (string) ob_get_clean();
}

if (!sheet_upload_api_schema_ready()) {
    echo "FAIL sheet upload API schema not ready — apply database/migration_sheet_client_uploads.sql\n";
    exit(1);
}

$assignmentFingerprint = static function (): string {
    return hash('sha256', (string) db()->query(
        'SELECT GROUP_CONCAT(CONCAT(id, ":", IFNULL(agent_user_id, "n"), ":", is_active) ORDER BY id SEPARATOR ",") FROM polling_units'
    )->fetchColumn());
};
$unitCountBefore = (int) db()->query('SELECT COUNT(*) FROM polling_units')->fetchColumn();
$fingerprintBefore = $assignmentFingerprint();
$startUpload = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM result_sheet_uploads')->fetchColumn();
$startClient = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM result_sheet_client_uploads')->fetchColumn();
$startEvents = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM result_sheet_events')->fetchColumn();

$users = ['su.agenta' => 'polling_agent', 'su.agentb' => 'polling_agent', 'su.admin' => 'admin', 'su.viewer' => 'viewer'];
$pass = [];
$ids = [];
$borrowed = [];

try {
    $insert = db()->prepare('INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (:u, :e, :h, :n, :r, 1)');
    foreach ($users as $name => $role) {
        $pass[$name] = bin2hex(random_bytes(10));
        $insert->execute([
            'u' => $name,
            'e' => $name . '@ohaji-egbema.local',
            'h' => password_hash($pass[$name], PASSWORD_DEFAULT),
            'n' => 'Sheet API ' . $name,
            'r' => $role,
        ]);
        $ids[$name] = (int) db()->lastInsertId();
    }
    $units = db()->query(
        'SELECT id, unit_code, agent_user_id, is_active, updated_at FROM polling_units
         WHERE is_active = 1 AND agent_user_id IS NULL ORDER BY id DESC LIMIT 2'
    )->fetchAll();
    if (count($units) < 2) {
        throw new RuntimeException('Need two active unassigned units.');
    }
    foreach ($units as $unit) {
        $borrowed[(int) $unit['id']] = $unit;
    }
    $uA = (int) $units[0]['id'];
    $uB = (int) $units[1]['id'];
    db()->prepare('UPDATE polling_units SET agent_user_id = :a, updated_at = updated_at WHERE id = :u')->execute(['a' => $ids['su.agenta'], 'u' => $uA]);
    db()->prepare('UPDATE polling_units SET agent_user_id = :a, updated_at = updated_at WHERE id = :u')->execute(['a' => $ids['su.agentb'], 'u' => $uB]);

    $a = new SuClient();
    $b = new SuClient();
    $admin = new SuClient();
    $viewer = new SuClient();
    $a->login('su.agenta', $pass['su.agenta']);
    $b->login('su.agentb', $pass['su.agentb']);
    $admin->login('su.admin', $pass['su.admin']);
    $viewer->login('su.viewer', $pass['su.viewer']);

    $bytes = test_jpeg('a');
    $cid = uuid();

    echo "-- gates\n";
    check($a->req('results/api-sheet-upload')['code'] === 405, 'GET refused');
    check($admin->upload(['client_upload_id' => uuid(), 'polling_unit_id' => (string) $uA], $bytes)['code'] === 403, 'admin cannot use agent sheet API');
    check($viewer->upload(['client_upload_id' => uuid(), 'polling_unit_id' => (string) $uA], $bytes)['code'] === 403, 'viewer cannot use agent sheet API');
    $noCsrf = $a->req('results/api-sheet-upload', [
        'client_upload_id' => uuid(),
        'polling_unit_id' => (string) $uA,
        'result_sheet' => new CURLFile(tempnam(sys_get_temp_dir(), 'x'), 'image/jpeg', 'x.jpg'),
    ]);
    check($noCsrf['code'] === 403, 'missing CSRF refused');

    echo "-- sheet-only create (no numerical result)\n";
    $beforeResults = (int) db()->query('SELECT COUNT(*) FROM polling_unit_results WHERE polling_unit_id = ' . $uA)->fetchColumn();
    $first = $a->upload([
        'client_upload_id' => $cid,
        'polling_unit_id' => (string) $uA,
    ], $bytes);
    check($first['code'] === 201 && ($first['json']['ok'] ?? false) === true && ($first['json']['code'] ?? '') === 'received', 'first sheet-only upload received');
    check(($first['json']['client_upload_id'] ?? '') === $cid && (int) ($first['json']['upload_id'] ?? 0) > 0, 'ack returns client and server upload ids');
    check(str_contains((string) ($first['json']['notice'] ?? ''), 'not mean the sheet has been reviewed'), 'ack warns receipt is not approval');
    $afterResults = (int) db()->query('SELECT COUNT(*) FROM polling_unit_results WHERE polling_unit_id = ' . $uA)->fetchColumn();
    check($afterResults === $beforeResults, 'no numerical result row created');
    $uploadId = (int) $first['json']['upload_id'];
    $row = db()->query('SELECT review_status, sha256 FROM result_sheet_uploads WHERE id = ' . $uploadId)->fetch();
    check(is_array($row) && (string) $row['review_status'] === 'pending' && (string) $row['sha256'] === hash('sha256', $bytes), 'sheet stored pending review with correct hash');

    echo "-- idempotency\n";
    $replay = $a->upload(['client_upload_id' => $cid, 'polling_unit_id' => (string) $uA], $bytes);
    check($replay['code'] === 200 && ($replay['json']['replayed'] ?? false) === true && (int) ($replay['json']['upload_id'] ?? 0) === $uploadId, 'retry with same ID and bytes replays');
    $count = (int) db()->query('SELECT COUNT(*) FROM result_sheet_uploads WHERE polling_unit_id = ' . $uA . ' AND id > ' . $startUpload)->fetchColumn();
    check($count === 1, 'exactly one upload version after retry');
    $reuse = $a->upload(['client_upload_id' => $cid, 'polling_unit_id' => (string) $uA], test_jpeg('z'));
    check($reuse['code'] === 409 && ($reuse['json']['code'] ?? '') === 'upload_id_reused', 'same ID with different bytes refused');
    check((int) db()->query('SELECT COUNT(*) FROM result_sheet_uploads WHERE polling_unit_id = ' . $uA . ' AND id > ' . $startUpload)->fetchColumn() === 1, 'reuse attempt creates no second version');

    echo "-- authorization\n";
    $cross = $b->upload(['client_upload_id' => uuid(), 'polling_unit_id' => (string) $uA], test_jpeg('b'));
    check($cross['code'] === 403 && ($cross['json']['code'] ?? '') === 'unit_not_assigned', 'agent B cannot upload to agent A unit');
    db()->prepare('UPDATE polling_units SET agent_user_id = NULL, updated_at = updated_at WHERE id = :u')->execute(['u' => $uA]);
    $revoked = $a->upload(['client_upload_id' => uuid(), 'polling_unit_id' => (string) $uA], test_jpeg('r'));
    check($revoked['code'] === 403 && ($revoked['json']['code'] ?? '') === 'unit_not_assigned', 'revoked assignment refused on upload');
    db()->prepare('UPDATE polling_units SET agent_user_id = :a, updated_at = updated_at WHERE id = :u')->execute(['a' => $ids['su.agenta'], 'u' => $uA]);
    db()->prepare('UPDATE polling_units SET is_active = 0, updated_at = updated_at WHERE id = :u')->execute(['u' => $uA]);
    $inactive = $a->upload(['client_upload_id' => uuid(), 'polling_unit_id' => (string) $uA], test_jpeg('i'));
    check($inactive['code'] === 409 && ($inactive['json']['code'] ?? '') === 'unit_inactive', 'inactive unit refused');
    db()->prepare('UPDATE polling_units SET is_active = 1, updated_at = updated_at WHERE id = :u')->execute(['u' => $uA]);

    echo "-- validation\n";
    $badType = $a->upload(['client_upload_id' => uuid(), 'polling_unit_id' => (string) $uA], 'not-an-image');
    check(in_array($badType['code'], [422, 400], true), 'invalid file bytes refused');
} catch (Throwable $e) {
    echo 'FAIL setup: ' . $e->getMessage() . PHP_EOL;
    $failures++;
}

echo "-- cleanup\n";
try {
    $rows = db()->query('SELECT * FROM result_sheet_uploads WHERE id > ' . $startUpload)->fetchAll();
    foreach ($rows as $row) {
        $path = sheet_stored_path($row);
        if ($path !== null && is_file($path)) {
            @chmod($path, 0600);
            @unlink($path);
        }
    }
    db()->exec('DELETE FROM result_sheet_client_uploads WHERE id > ' . $startClient);
    db()->exec('DELETE FROM result_sheet_events WHERE id > ' . $startEvents);
    db()->exec('DELETE FROM result_sheet_incidents WHERE upload_id > ' . $startUpload);
    db()->exec('DELETE FROM result_sheet_uploads WHERE id > ' . $startUpload);
    foreach ($borrowed as $id => $unit) {
        db()->prepare('UPDATE polling_units SET agent_user_id = :a, is_active = :active, updated_at = :u WHERE id = :id')->execute([
            'a' => $unit['agent_user_id'],
            'active' => (int) $unit['is_active'],
            'u' => $unit['updated_at'],
            'id' => $id,
        ]);
    }
    if ($ids !== []) {
        $list = implode(',', array_map('intval', $ids));
        db()->exec('DELETE FROM users WHERE id IN (' . $list . ')');
    }
} catch (Throwable $e) {
    echo 'FAIL cleanup: ' . $e->getMessage() . PHP_EOL;
    $failures++;
}

$unitCountAfter = (int) db()->query('SELECT COUNT(*) FROM polling_units')->fetchColumn();
check($unitCountAfter === $unitCountBefore, 'polling unit count unchanged (' . $unitCountBefore . ')');
check($assignmentFingerprint() === $fingerprintBefore, 'agent assignment fingerprint restored');
check((int) db()->query("SELECT COUNT(*) FROM users WHERE username LIKE 'su.%'")->fetchColumn() === 0, 'test users removed');

echo ($failures === 0 ? "ALL PASS\n" : "FAILURES {$failures}\n");
exit($failures === 0 ? 0 : 1);
