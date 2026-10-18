<?php
declare(strict_types=1);

// Phase E / B checks: polling-agent assignment emails, password-setup links, and retries.
// Uses temporary an.* accounts and unassigned polling units that are restored afterwards.
// The web flow runs in the local capture mode from config/env.php; in-process checks use a
// memory transport or an unreachable SMTP port. No real email is sent.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/polling_units.php';
require_once dirname(__DIR__) . '/includes/agent_notifications.php';

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
    public function req(string $path, ?array $post = null): array
    {
        curl_setopt($this->ch, CURLOPT_URL, BASE . str_replace('.php', '', $path));
        if ($post === null) {
            curl_setopt($this->ch, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($this->ch, CURLOPT_POST, true);
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, http_build_query($post));
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
            return $this->req(self::local($first['location'])) + ['first' => 302, 'first_location' => $first['location']];
        }

        return $first + ['first' => $first['code'], 'first_location' => ''];
    }

    public static function local(string $location): string
    {
        return (string) preg_replace('#^(https?://[^/]+)?/election-monitor-app/#', '', $location);
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

function count_where(string $sql, array $params = []): int
{
    $s = db()->prepare($sql);
    $s->execute($params);

    return (int) $s->fetchColumn();
}

function flash(array $response): string
{
    return preg_match('/<p class="pu-alert pu-alert--(\w+)"[^>]*>(.*?)<\/p>/s', $response['body'], $m)
        ? $m[1] . ': ' . html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')
        : '';
}

function notes(int $userId): array
{
    $s = db()->prepare('SELECT * FROM agent_notifications WHERE user_id = :u ORDER BY id');
    $s->execute(['u' => $userId]);

    return $s->fetchAll();
}

function unit_agent(int $unitId): int
{
    return count_where('SELECT COALESCE(agent_user_id, 0) FROM polling_units WHERE id = :id', ['id' => $unitId]);
}

/** @return list<string> */
function captures(): array
{
    $dir = rtrim(app_config()['mail_capture_dir'], '\\/');
    $files = is_dir($dir) ? glob($dir . DIRECTORY_SEPARATOR . '*.eml') : [];

    return $files === false ? [] : $files;
}

/** @return list<string> Captured messages written since the previous call. */
function new_captures(): array
{
    global $seen, $created;
    $out = [];
    foreach (captures() as $file) {
        if (!isset($seen[$file])) {
            $seen[$file] = true;
            $created[] = $file;
            $out[] = (string) file_get_contents($file);
        }
    }

    return $out;
}

function decoded(string $raw): string
{
    return quoted_printable_decode($raw);
}

function html_part(string $raw): string
{
    return preg_match('/Content-Type: text\/html.*?\r?\n\r?\n(.*?)\r?\n--/s', $raw, $m) ? quoted_printable_decode($m[1]) : '';
}

function header_line(string $raw, string $name): string
{
    return preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $raw, $m) ? trim($m[1]) : '';
}

if (app_config()['mail_transport'] !== 'capture') {
    fwrite(STDERR, "Refusing to run: config/env.php must use 'mail_transport' => 'capture' so the web-flow checks cannot send real email.\n");
    exit(1);
}

$max = static fn (string $table): int => (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM ' . $table)->fetchColumn();
$start = [];
foreach (['users', 'audit_log', 'agent_notifications', 'login_attempts', 'remember_tokens'] as $table) {
    $start[$table] = $max($table);
}
$logFile = dirname(__DIR__) . '/storage/logs/php-error.log';
$logStart = is_file($logFile) ? filesize($logFile) : 0;
$seen = array_fill_keys(captures(), true);
$created = [];
$adminBefore = db()->query("SELECT password_hash, must_change_password, failed_login_count FROM users WHERE username = 'portal.admin'")->fetch();

$units = db()->query(
    'SELECT pu.id, pu.unit_code, pu.name, pu.lga_name, pu.agent_user_id, pu.assigned_by, pu.assigned_at, pu.updated_at, w.name AS ward_name
     FROM polling_units pu INNER JOIN wards w ON w.id = pu.ward_id
     WHERE pu.is_active = 1 AND pu.agent_user_id IS NULL
     ORDER BY pu.id LIMIT 4'
)->fetchAll();
if (count($units) < 4) {
    fwrite(STDERR, "Need four active, unassigned polling units.\n");
    exit(1);
}
[$u1, $u2, $u3, $u4] = $units;
$ids = [];
$pass = [];
$secrets = [];
$smtpSecret = 'smtp-' . bin2hex(random_bytes(8));
$secrets[] = $smtpSecret;

try {
    $insert = db()->prepare('INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (:u, :e, :h, :n, :r, 1)');
    foreach (['an.admin' => ['admin', 'an.admin@example.test'], 'an.viewer' => ['supervisor', 'an.viewer@example.test'], 'an.agentx' => ['polling_agent', 'an.agentx@example.test'], 'an.badmail' => ['polling_agent', 'not-an-email']] as $name => [$role, $email]) {
        $pass[$name] = 'Pw-' . bin2hex(random_bytes(8));
        $secrets[] = $pass[$name];
        $insert->execute(['u' => $name, 'e' => $email, 'h' => password_hash($pass[$name], PASSWORD_DEFAULT), 'n' => 'Notify Test ' . $name, 'r' => $role]);
        $ids[$name] = (int) db()->lastInsertId();
    }

    echo "-- create agent and assign (web flow, capture mode)\n";
    check(mailer_status()['ready'] && mailer_status()['transport'] === 'capture', 'local environment uses capture mode, so nothing leaves this machine');
    check(auth_password_setup_schema_ready(), 'password_setup_tokens migration is applied');
    $admin = new Client();
    check(login($admin, 'an.admin', $pass['an.admin'])['code'] === 302, 'administrator signs in');
    $agentName = 'Ada <b>"O\'Neil"</b> & Co';
    $page = $admin->req('admin/polling-unit.php?id=' . $u1['id'] . '&mode=assign');
    $tok = token($page['body']);
    check(str_contains($page['body'], 'Test mode: emails are captured') && !str_contains($page['body'], 'name="agent_password"'), 'creation form says email is in test mode and has no password fields');
    $created1 = $admin->post('admin/polling-unit.php', ['csrf_token' => $tok, 'intent' => 'create_agent', 'id' => (string) $u1['id'], 'agent_name' => $agentName, 'agent_username' => 'an.newagent', 'agent_email' => 'AN.NewAgent@Example.test']);
    $newId = count_where("SELECT id FROM users WHERE username = 'an.newagent'");
    $ids['an.newagent'] = $newId;
    $msg = flash($created1);
    check($newId > 0 && count_where("SELECT COUNT(*) FROM users WHERE username = 'an.newagent'") === 1 && unit_agent((int) $u1['id']) === $newId, 'agent account created once and assigned to the unit');
    check(str_starts_with($msg, 'success: Polling agent registered and assigned') && str_contains($msg, 'Email captured locally for an.newagent@example.test (test mode). Nothing was sent.'), 'administrator sees a truthful status: captured, not "delivered"');
    $agentFlags = db()->query('SELECT must_change_password, password_changed_at FROM users WHERE id = ' . $newId)->fetch();
    check((int) $agentFlags['must_change_password'] === 0 && $agentFlags['password_changed_at'] === null, 'new agent has no password set yet and is not forced through change-password');
    $setupRow = db()->prepare('SELECT selector, token_hash, used_at, expires_at FROM password_setup_tokens WHERE user_id = :id AND used_at IS NULL ORDER BY id DESC LIMIT 1');
    $setupRow->execute(['id' => $newId]);
    $setupRow = $setupRow->fetch();
    check(is_array($setupRow) && preg_match('/\A[a-f0-9]{24}\z/', (string) $setupRow['selector']) === 1 && strlen((string) $setupRow['token_hash']) === 64, 'a hashed single-use setup token was stored for the new agent');
    $n = notes($newId);
    check(count($n) === 1 && $n[0]['kind'] === 'account_created' && $n[0]['status'] === 'captured' && (int) $n[0]['includes_password'] === 1 && $n[0]['recipient_email'] === 'an.newagent@example.test' && $n[0]['unit_codes'] === $u1['unit_code'] && preg_match('/\A[0-9a-f-]{36}\z/', $n[0]['notification_id']) === 1, 'notification recorded with a unique ID, the agent\'s saved address, and the unit');
    $mails = new_captures();
    check(count($mails) === 1, 'exactly one message captured');
    $raw = $mails[0] ?? '';
    $body = decoded($raw);
    check(str_contains(header_line($raw, 'To'), 'an.newagent@example.test') && !str_contains(header_line($raw, 'To'), 'an.admin'), 'recipient is the agent\'s saved email address, not the administrator\'s');
    check(header_line($raw, 'Subject') === 'Your Polling Agent Assignment and Account Details', 'professional subject line');
    check(str_contains($body, e($u1['name'])) && str_contains($body, $u1['unit_code']) && str_contains($body, e($u1['ward_name'])) && str_contains($body, e($u1['lga_name'])) && str_contains($body, 'Imo State'), 'polling-unit name, ID, ward, LGA and state are in the email');
    check(str_contains($body, 'an.newagent') && str_contains($body, 'http://localhost/election-monitor-app/portal/login') && str_contains($body, 'Choose your own password'), 'username, canonical sign-in URL and setup steps are included');
    $html = html_part($raw);
    check(str_contains($html, 'Dear Ada &lt;b&gt;&quot;O&#039;Neil&quot;&lt;/b&gt; &amp; Co,') && !str_contains($html, '<b>"O\'Neil"</b>'), 'agent name is HTML-escaped in the HTML part');
    check(str_contains($raw, 'Content-Type: text/plain') && str_contains($raw, 'Content-Type: text/html') && str_contains($body, 'Dear Ada <b>"O\'Neil"</b> & Co,'), 'plain-text alternative is present');
    check(!str_contains($raw, (string) $setupRow['selector']) && str_contains($body, MAILER_CAPTURE_PLACEHOLDER) && !str_contains($body, 'Temporary password'), 'the local capture copy withholds the setup link and never emails a temporary password');
    $logo = (string) file_get_contents(dirname(__DIR__) . '/' . AN_LOGO_FILE);
    $embedded = preg_match('/Content-ID: <' . preg_quote(AN_LOGO_CID, '/') . '>.*?\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+)/s', $raw, $lm) ? base64_decode(preg_replace('/\s+/', '', $lm[1])) : '';
    check(str_contains($body, 'src="cid:' . AN_LOGO_CID . '"') && $embedded === $logo && str_contains($raw, AN_LOGO_MIME) && !preg_match('#src="(file:|[A-Za-z]:\\\\|/)#', $body), 'the campaign poster is embedded as a CID inline image (no filesystem path)');
    check(str_contains($body, 'not an official INEC results platform') && str_contains($body, 'Never share your username, password, or setup link'), 'footer says independent monitoring, not INEC; security reminder present');

    echo "-- duplicates and invalid input\n";
    $tok = token($admin->req('admin/polling-unit.php?id=' . $u2['id'] . '&mode=assign')['body']);
    $dup = $admin->req('admin/polling-unit.php', ['csrf_token' => $tok, 'intent' => 'create_agent', 'id' => (string) $u2['id'], 'agent_name' => 'Duplicate', 'agent_username' => 'an.newagent', 'agent_email' => 'other@example.test']);
    check(str_contains($dup['body'], 'already in use') && count_where("SELECT COUNT(*) FROM users WHERE username = 'an.newagent'") === 1 && unit_agent((int) $u2['id']) === 0 && count(notes($newId)) === 1 && new_captures() === [], 'repeating the username creates nothing and sends nothing');
    $bad = $admin->req('admin/polling-unit.php', ['csrf_token' => $tok, 'intent' => 'create_agent', 'id' => (string) $u2['id'], 'agent_name' => 'Bad Mail', 'agent_username' => 'an.badform', 'agent_email' => 'bad@']);
    check(str_contains($bad['body'], 'Enter a valid email address.') && count_where("SELECT COUNT(*) FROM users WHERE username = 'an.badform'") === 0 && new_captures() === [], 'an invalid email address is rejected before any account or email');

    echo "-- assignment changes\n";
    $multi = $admin->post('admin/polling-unit.php', ['csrf_token' => $tok, 'intent' => 'assign', 'id' => (string) $u2['id'], 'agent_user_id' => (string) $newId]);
    $n = notes($newId);
    $last = end($n);
    $mails = new_captures();
    $body = decoded($mails[0] ?? '');
    check(unit_agent((int) $u2['id']) === $newId && str_contains(flash($multi), 'Polling agent assigned. Email captured locally'), 'second unit assigned; administrator told the update email was captured');
    check($last['kind'] === 'assignment_changed' && (int) $last['includes_password'] === 0 && str_contains((string) $last['unit_codes'], $u1['unit_code']) && str_contains((string) $last['unit_codes'], $u2['unit_code']), 'update notification lists every authorized unit');
    check(count($mails) === 1 && str_contains($body, $u1['unit_code']) && str_contains($body, $u2['unit_code']) && str_contains($body, 'Assigned polling units (2)') && header_line($mails[0], 'Subject') === 'Your Polling Agent Assignment Has Been Updated', 'multiple-unit email lists both units');
    check(!str_contains($body, 'Temporary password') && !str_contains($body, MAILER_CAPTURE_PLACEHOLDER) && str_contains($body, 'Your password has not changed and is not included'), 'assignment updates never resend credentials');

    $reassign = $admin->post('admin/polling-unit.php', ['csrf_token' => $tok, 'intent' => 'assign', 'id' => (string) $u2['id'], 'agent_user_id' => (string) $ids['an.agentx']]);
    $mails = new_captures();
    $byRecipient = [];
    foreach ($mails as $mail) {
        $byRecipient[header_line($mail, 'To')] = decoded($mail);
    }
    $toX = '';
    $toNew = '';
    foreach ($byRecipient as $to => $content) {
        if (str_contains($to, 'an.agentx@example.test')) {
            $toX = $content;
        } elseif (str_contains($to, 'an.newagent@example.test')) {
            $toNew = $content;
        }
    }
    check(unit_agent((int) $u2['id']) === $ids['an.agentx'] && count($mails) === 2, 'reassignment notifies both the new and the previous agent');
    check(str_contains($toX, $u2['unit_code']) && !str_contains($toX, $u1['unit_code']) && str_contains($toNew, $u1['unit_code']) && !str_contains($toNew, $u2['unit_code']), 'each agent\'s email lists only the units they now hold');
    $before = count_where('SELECT COUNT(*) FROM agent_notifications');
    $same = $admin->post('admin/polling-unit.php', ['csrf_token' => $tok, 'intent' => 'assign', 'id' => (string) $u2['id'], 'agent_user_id' => (string) $ids['an.agentx']]);
    check(str_contains(flash($same), 'already held the unit, so no email was sent') && count_where('SELECT COUNT(*) FROM agent_notifications') === $before && new_captures() === [], 'assigning the same agent again sends no email');
    $clear = $admin->post('admin/polling-unit.php', ['csrf_token' => $tok, 'intent' => 'unassign', 'id' => (string) $u2['id'], 'confirm' => '1']);
    $mails = new_captures();
    check(unit_agent((int) $u2['id']) === 0 && count($mails) === 1 && str_contains(header_line($mails[0], 'To'), 'an.agentx@example.test') && str_contains(decoded($mails[0]), 'You currently have no polling units assigned') && str_contains(flash($clear), 'The assigned agent was cleared. Email captured'), 'clearing an assignment tells the agent they no longer hold the unit');

    echo "-- invalid saved address, retry, authorization\n";
    $badAssign = $admin->post('admin/polling-unit.php', ['csrf_token' => $tok, 'intent' => 'assign', 'id' => (string) $u3['id'], 'agent_user_id' => (string) $ids['an.badmail']]);
    $n = notes($ids['an.badmail']);
    check(unit_agent((int) $u3['id']) === $ids['an.badmail'] && str_starts_with(flash($badAssign), 'warn: Polling agent assigned. Email delivery failed: the agent’s saved email address is not valid') && count($n) === 1 && $n[0]['status'] === 'failed' && $n[0]['last_error'] === 'invalid_recipient' && new_captures() === [], 'an invalid saved address fails visibly and keeps the assignment');
    check(str_contains($badAssign['body'], 'Retry email') && str_contains($badAssign['body'], $n[0]['notification_id']), 'unit page offers a retry for the failed email');
    $retryPost = ['intent' => 'retry_notification', 'id' => (string) $u3['id'], 'notification_id' => $n[0]['notification_id']];

    $viewer = new Client();
    login($viewer, 'an.viewer', $pass['an.viewer']);
    $vTok = token($viewer->req('agent/dashboard')['body']) ?: token($viewer->req('supervisor/dashboard')['body']);
    $denied = $viewer->req('admin/polling-unit.php', $retryPost + ['csrf_token' => $vTok]);
    $anon = (new Client())->req('admin/polling-unit.php', $retryPost + ['csrf_token' => str_repeat('a', 64)]);
    $agentX = new Client();
    login($agentX, 'an.agentx', $pass['an.agentx']);
    $aTok = token($agentX->req('agent/dashboard')['body']);
    $agentDenied = $agentX->req('admin/polling-unit.php', $retryPost + ['csrf_token' => $aTok]);
    $noCsrf = $admin->req('admin/polling-unit.php', $retryPost + ['csrf_token' => str_repeat('0', 64)]);
    check($denied['code'] === 403 && $agentDenied['code'] === 403 && $anon['code'] === 302 && $noCsrf['code'] === 403 && (int) notes($ids['an.badmail'])[0]['attempts'] === 1, 'supervisors, agents, signed-out users and forged requests cannot retry');

    $retry1 = $admin->post('admin/polling-unit.php', $retryPost + ['csrf_token' => $tok]);
    check(str_contains(flash($retry1), 'Retry: Email delivery failed') && (int) notes($ids['an.badmail'])[0]['attempts'] === 2 && new_captures() === [], 'retrying with the address still invalid fails again, truthfully');
    db()->prepare('UPDATE users SET email = :e WHERE id = :id')->execute(['e' => 'an.badmail@example.test', 'id' => $ids['an.badmail']]);
    $retry2 = $admin->post('admin/polling-unit.php', $retryPost + ['csrf_token' => $tok]);
    $n = notes($ids['an.badmail']);
    $mails = new_captures();
    check(str_contains(flash($retry2), 'Retry: Email captured locally for an.badmail@example.test') && count($n) === 1 && $n[0]['status'] === 'captured' && (int) $n[0]['attempts'] === 3 && count($mails) === 1 && str_contains(header_line($mails[0], 'To'), 'an.badmail@example.test'), 'after the address is corrected the same notification succeeds');
    check(count_where("SELECT COUNT(*) FROM users WHERE username LIKE 'an.%'") === 5 && count_where('SELECT COUNT(*) FROM polling_units WHERE agent_user_id = :a', ['a' => $ids['an.badmail']]) === 1, 'retries create no extra accounts or assignments');
    $resend = $admin->post('admin/polling-unit.php', $retryPost + ['csrf_token' => $tok]);
    check(str_contains($resend['body'], 'Send email now') && str_contains(flash($resend), 'Retry: Email captured locally') && count(new_captures()) === 1 && count(notes($ids['an.badmail'])) === 1, 'a captured (never sent) email can be sent again once real email is set up');
    db()->prepare("UPDATE agent_notifications SET status = 'sent' WHERE notification_id = :id")->execute(['id' => $n[0]['notification_id']]);
    $again = $admin->req('admin/polling-unit.php', $retryPost + ['csrf_token' => $tok]);
    check(str_contains($again['body'], 'This email is not waiting for a retry.') && new_captures() === [], 'an email handed to the mail server cannot be retried into a duplicate');

    echo "-- SMTP outage and insecure settings (in-process)\n";
    $smtpDown = ['mail_transport' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => 1, 'smtp_encryption' => 'tls', 'smtp_username' => 'an-smtp-user', 'smtp_password' => $smtpSecret, 'mail_from' => 'no-reply@example.test', 'app_url' => 'https://monitor.example.test'];
    mailer_override($smtpDown);
    $queued = null;
    $reg = pu_register_agent((int) $u4['id'], ['agent_name' => 'Outage Agent', 'agent_username' => 'an.outage', 'agent_email' => 'an.outage@example.test'], $ids['an.admin'], static function (PDO $pdo, int $agentId) use (&$queued, $ids): void {
        $queued = an_queue($agentId, 'account_created', $ids['an.admin'], true);
    });
    $ids['an.outage'] = (int) ($reg['agent_id'] ?? 0);
    $sent = an_send_account_email($queued);
    $row = an_find((string) $queued);
    check($reg['ok'] && !$sent['ok'] && str_contains($sent['text'], 'Email delivery failed: the mail server could not be reached. The assignment is saved') && $row['status'] === 'failed' && $row['last_error'] === 'smtp_unavailable', 'SMTP outage: failure reported, no false delivery claim');
    check(count_where("SELECT COUNT(*) FROM users WHERE username = 'an.outage'") === 1 && unit_agent((int) $u4['id']) === $ids['an.outage'], 'SMTP outage does not undo or duplicate the account and assignment');
    check((int) an_find((string) $queued)['attempts'] === 1, 'failed account email remains retryable without requiring a new password from the administrator');
    mailer_override(['mail_transport' => 'memory', 'app_url' => 'https://monitor.example.test']);
    $outbox = &mailer_memory_outbox();
    $outbox = [];
    $retried = an_retry((string) $queued, $ids['an.admin']);
    check($retried['ok'] && $retried['status'] === 'sent' && count($outbox) === 1 && count_where("SELECT COUNT(*) FROM users WHERE username = 'an.outage'") === 1, 'retry issues a setup link and sends once, same account');
    $setupLink = '';
    if (preg_match('#https://monitor\.example\.test/portal/setup-password\?t=[a-f0-9.]{80,}#', (string) ($outbox[0]['text'] ?? ''), $m)) {
        $setupLink = $m[0];
        $secrets[] = $setupLink;
    }
    check($setupLink !== '' && str_contains($outbox[0]['html'] ?? '', e($setupLink)) && str_contains($outbox[0]['html'] ?? '', 'https://monitor.example.test/portal/login') && !str_contains($outbox[0]['text'] ?? '', 'Temporary password'), 'the real message carries a canonical HTTPS setup link once and no temporary password');

    mailer_override(['mail_transport' => 'smtp', 'smtp_host' => 'smtp.example.test', 'smtp_port' => 25, 'smtp_encryption' => 'none', 'mail_from' => 'no-reply@example.test', 'app_url' => 'https://monitor.example.test']);
    $insecure = mailer_status();
    $id = an_queue($ids['an.agentx'], 'assignment_changed', $ids['an.admin']);
    $res = an_deliver((string) $id);
    check(!$insecure['ready'] && $insecure['reason'] === 'insecure_transport' && $res['status'] === 'not_configured' && new_captures() === [], 'unencrypted SMTP to a remote host is refused, with no fallback transport');
    mailer_override(['mail_transport' => 'disabled']);
    $id2 = an_queue($ids['an.agentx'], 'assignment_changed', $ids['an.admin']);
    $res2 = an_deliver((string) $id2);
    check($res2['status'] === 'not_configured' && str_contains(an_status_text($res2['status'], $res2['error'], $res2['email']), 'Email is not configured in this environment') && an_find((string) $id)['status'] === 'superseded', 'email turned off: "not configured" status; older unsent update superseded');
    mailer_override(['mail_transport' => 'smtp', 'smtp_host' => 'smtp.example.test', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'mail_from' => 'no-reply@example.test', 'app_url' => 'http://monitor.example.test']);
    check(mailer_status()['reason'] === 'app_url_missing', 'plain-http app_url is accepted only for localhost');

    echo "-- escaping (in-process)\n";
    mailer_override(['mail_transport' => 'memory', 'app_url' => 'https://monitor.example.test']);
    $evilLink = 'https://monitor.example.test/portal/setup-password?t=aaaaaaaaaaaaaaaaaaaaaaaa.bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    $message = an_compose(['full_name' => '<script>alert(1)</script>', 'username' => 'an.x', 'email' => 'x@example.test'], [['code' => 'PU<1>&"', 'name' => 'Unit "A" & <B>', 'ward' => "Ward 'W'", 'lga' => 'Ohaji/Egbema']], 'account_created', $evilLink);
    check(!str_contains($message['html'], '<script>') && str_contains($message['html'], '&lt;script&gt;alert(1)&lt;/script&gt;') && str_contains($message['html'], 'PU&lt;1&gt;&amp;&quot;') && str_contains($message['html'], 'Unit &quot;A&quot; &amp; &lt;B&gt;') && str_contains($message['html'], 'Ward &#039;W&#039;') && str_contains($message['html'], e($evilLink)), 'agent names, unit values and the setup URL are escaped in HTML');
    check(str_contains($message['text'], 'Unit "A" & <B>') && !str_contains($message['text'], '&lt;') && $message['inline'][AN_LOGO_CID]['path'] !== '' && is_file($message['inline'][AN_LOGO_CID]['path']), 'plain text is unescaped and the logo attachment exists');

    echo "-- password setup link (web)\n";
    check(login(new Client(), 'an.newagent', 'not-the-password-12345')['code'] === 200, 'agent cannot sign in before completing the setup link');
    $apiAnon = (new Client())->req('results/api-status');
    check($apiAnon['code'] === 401 && str_contains($apiAnon['body'], '"auth_required"'), 'APIs answer 401 so queued offline results stay on the device');
    mailer_override(['mail_transport' => 'memory', 'app_url' => 'http://localhost/election-monitor-app']);
    $outbox = &mailer_memory_outbox();
    $outbox = [];
    db()->prepare("UPDATE agent_notifications SET status = 'failed' WHERE user_id = :id AND kind = 'account_created'")->execute(['id' => $newId]);
    $reissue = an_retry((string) notes($newId)[0]['notification_id'], $ids['an.admin']);
    $setupUrl = '';
    if (preg_match('#http://localhost/election-monitor-app/portal/setup-password\?t=([a-f0-9.]+)#', (string) ($outbox[0]['text'] ?? ''), $m)) {
        $setupUrl = $m[0];
        $rawSetupToken = $m[1];
        $secrets[] = $setupUrl;
        $secrets[] = $rawSetupToken;
    } else {
        $rawSetupToken = '';
    }
    check($reissue['ok'] && $setupUrl !== '' && $rawSetupToken !== '', 'retry reissues a setup link for the new agent');
    $expired = auth_complete_password_setup('deadbeefdeadbeefdeadbeef.' . str_repeat('a', 64), 'Brand-new-pass-123', 'Brand-new-pass-123');
    check($expired['ok'] === false, 'forged setup tokens are refused');
    $short = auth_complete_password_setup($rawSetupToken, 'short', 'short');
    check($short['ok'] === false && ($short['field'] ?? '') === 'new_password', 'short passwords are refused on setup');
    $ownPass = 'Own-' . bin2hex(random_bytes(9));
    $secrets[] = $ownPass;
    $setupClient = new Client();
    $form = $setupClient->req('portal/setup-password.php?t=' . rawurlencode($rawSetupToken));
    $cTok = token($form['body']);
    check($form['code'] === 200 && str_contains($form['body'], 'an.newagent') && $cTok !== '', 'setup page opens for a valid token without signing the agent in yet');
    $done = $setupClient->req('portal/setup-password.php', ['csrf_token' => $cTok, 't' => $rawSetupToken, 'new_password' => $ownPass, 'new_password_confirm' => $ownPass]);
    $flags = db()->query('SELECT must_change_password, password_changed_at, failed_login_count, locked_at, role FROM users WHERE id = ' . $newId)->fetch();
    $used = db()->query('SELECT used_at FROM password_setup_tokens WHERE user_id = ' . $newId . ' ORDER BY id DESC LIMIT 1')->fetch();
    check($done['code'] === 302 && str_contains($done['location'], 'setup=1') && (int) $flags['must_change_password'] === 0 && $flags['password_changed_at'] !== null && $flags['locked_at'] === null && $flags['role'] === 'polling_agent' && $used['used_at'] !== null, 'after setup the password is saved, the token is consumed, and the role is unchanged');
    $reuse = auth_complete_password_setup($rawSetupToken, 'Another-pass-1234', 'Another-pass-1234');
    check($reuse['ok'] === false, 'setup tokens are single-use');
    $agent = new Client();
    check(login($agent, 'an.newagent', $ownPass)['code'] === 302 && $agent->req('agent/dashboard')['code'] === 200, 'after setup the agent signs in and reaches the dashboard');
    db()->prepare("UPDATE agent_notifications SET status = 'failed' WHERE user_id = :id AND kind = 'account_created'")->execute(['id' => $newId]);
    $after = an_retry((string) notes($newId)[0]['notification_id'], $ids['an.admin']);
    check(!$after['ok'] && str_contains($after['error'], 'already set their own password') && password_verify($ownPass, (string) db()->query('SELECT password_hash FROM users WHERE id = ' . $newId)->fetchColumn()), 'setup links are not re-sent once the agent has chosen a password');

    echo "-- no secrets in logs or records\n";
    $log = is_file($logFile) ? (string) file_get_contents($logFile, false, null, $logStart) : '';
    $audit = implode("\n", db()->query('SELECT summary FROM audit_log WHERE id > ' . $start['audit_log'])->fetchAll(PDO::FETCH_COLUMN));
    $rows = json_encode(db()->query('SELECT * FROM agent_notifications WHERE id > ' . $start['agent_notifications'])->fetchAll());
    $captured = implode("\n", array_map(static fn (string $f): string => (string) file_get_contents($f), $created));
    $leaks = [];
    foreach (['error log' => $log, 'audit log' => $audit, 'notification rows' => $rows, 'captured files' => $captured] as $where => $text) {
        foreach ($secrets as $secret) {
            if (str_contains($text, $secret) || str_contains($text, e($secret))) {
                $leaks[] = $where;
            }
        }
    }
    check($leaks === [], 'no passwords or SMTP credentials in the error log, audit log, notification rows, or captured files' . ($leaks === [] ? '' : ' (found in ' . implode(', ', array_unique($leaks)) . ')'));
    check(str_contains($log, 'Mail delivery failed (smtp_unavailable)') && str_contains($audit, 'delivery failed (smtp_unavailable)') && str_contains($audit, 'captured locally'), 'delivery diagnostics and outcomes are logged without secrets');
    $adminAfter = db()->query("SELECT password_hash, must_change_password, failed_login_count FROM users WHERE username = 'portal.admin'")->fetch();
    check($adminAfter == $adminBefore, 'portal.admin untouched');
} finally {
    db()->exec('DELETE FROM audit_log WHERE id > ' . $start['audit_log']);
    db()->exec('DELETE FROM login_attempts WHERE id > ' . $start['login_attempts']);
    $restore = db()->prepare('UPDATE polling_units SET agent_user_id = :a, assigned_by = :b, assigned_at = :t, updated_at = :u WHERE id = :id');
    foreach ($units as $unit) {
        $restore->execute(['a' => $unit['agent_user_id'], 'b' => $unit['assigned_by'], 't' => $unit['assigned_at'], 'u' => $unit['updated_at'], 'id' => $unit['id']]);
    }
    $testIds = array_map('intval', array_values(array_filter($ids)));
    if ($testIds !== []) {
        db()->exec('DELETE FROM agent_notifications WHERE user_id IN (' . implode(',', $testIds) . ')');
        db()->exec('DELETE FROM remember_tokens WHERE user_id IN (' . implode(',', $testIds) . ')');
        db()->exec('DELETE FROM users WHERE id IN (' . implode(',', $testIds) . ") AND username LIKE 'an.%'");
    }
    foreach ($created as $file) {
        @unlink($file);
    }
    $restored = true;
    foreach ($units as $unit) {
        $now = db()->query('SELECT agent_user_id, assigned_by, assigned_at, updated_at FROM polling_units WHERE id = ' . (int) $unit['id'])->fetch();
        $restored = $restored && $now['agent_user_id'] === $unit['agent_user_id'] && $now['assigned_at'] === $unit['assigned_at'] && $now['updated_at'] === $unit['updated_at'];
    }
    check($restored && count_where("SELECT COUNT(*) FROM users WHERE username LIKE 'an.%'") === 0 && count_where('SELECT COUNT(*) FROM agent_notifications WHERE id > ' . $start['agent_notifications']) === 0, 'test accounts, notifications and captured files removed; polling units restored');
}

echo $failures === 0 ? 'ALL PASS' . PHP_EOL : 'FAILURES ' . $failures . PHP_EOL;
exit($failures === 0 ? 0 : 1);
