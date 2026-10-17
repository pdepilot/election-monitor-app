<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

const AN_KINDS = ['account_created', 'assignment_changed'];
// A captured message only exists on this server, so it can be sent once real email is configured.
const AN_RETRYABLE = ['failed', 'not_configured', 'captured'];
const AN_STALE_SENDING_MINUTES = 10;
const AN_LOGO_CID = 'email-poster';
const AN_LOGO_FILE = 'assets/brand/email-poster.jpg';
const AN_LOGO_MIME = 'image/jpeg';
const AN_LOGO_WIDTH = 280;
const AN_LOGO_HEIGHT = 390;
const AN_LOGO_ALT = 'Perfect Alliance, Choice beyond party: Hon. Uju Kingsley Chima giving a thumbs up in front of the Action Peoples Party logo.';
const AN_STATE = 'Imo State';
const AN_FOOTER = 'This message comes from an independent election monitoring system for Ohaji/Egbema. It is not an official INEC results platform. Results recorded in it are provisional monitoring records, not official INEC results.';

function an_schema_ready(): bool
{
    try {
        db()->query('SELECT 1 FROM agent_notifications LIMIT 0');
        db()->query('SELECT must_change_password FROM users LIMIT 0');

        return true;
    } catch (Throwable $exception) {
        return false;
    }
}

function an_new_id(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

/**
 * Records an email to send. Call inside the transaction that changes the account or assignment,
 * or right after it commits; deliver only after the commit.
 */
function an_queue(int $userId, string $kind, ?int $actorId, bool $includesPassword = false): ?string
{
    if (!in_array($kind, AN_KINDS, true)) {
        throw new InvalidArgumentException('Unknown notification kind.');
    }
    $agent = db()->prepare("SELECT email FROM users WHERE id = :id AND role = 'polling_agent' LIMIT 1");
    $agent->execute(['id' => $userId]);
    $email = $agent->fetchColumn();
    if ($email === false) {
        return null;
    }
    if ($kind === 'assignment_changed') {
        $supersede = db()->prepare(
            "UPDATE agent_notifications SET status = 'superseded'
             WHERE user_id = :user AND kind = 'assignment_changed' AND status IN ('queued', 'failed', 'not_configured')"
        );
        $supersede->execute(['user' => $userId]);
    }
    $id = an_new_id();
    $insert = db()->prepare(
        'INSERT INTO agent_notifications (notification_id, user_id, kind, includes_password, recipient_email, status, created_by)
         VALUES (:id, :user, :kind, :password, :email, \'queued\', :actor)'
    );
    $insert->bindValue(':id', $id, PDO::PARAM_STR);
    $insert->bindValue(':user', $userId, PDO::PARAM_INT);
    $insert->bindValue(':kind', $kind, PDO::PARAM_STR);
    $insert->bindValue(':password', $includesPassword ? 1 : 0, PDO::PARAM_INT);
    $insert->bindValue(':email', (string) $email, PDO::PARAM_STR);
    $insert->bindValue(':actor', $actorId !== null && $actorId > 0 ? $actorId : null, $actorId !== null && $actorId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $insert->execute();

    return $id;
}

/**
 * The agent's currently authorized (active) polling units, read at send time.
 *
 * @return list<array{code: string, name: string, ward: string, lga: string}>
 */
function an_units(int $userId): array
{
    $statement = db()->prepare(
        'SELECT pu.unit_code, pu.name, pu.lga_name, w.name AS ward_name
         FROM polling_units pu
         INNER JOIN wards w ON w.id = pu.ward_id
         WHERE pu.agent_user_id = :agent AND pu.is_active = 1
         ORDER BY w.name, pu.unit_code'
    );
    $statement->bindValue(':agent', $userId, PDO::PARAM_INT);
    $statement->execute();
    $units = [];
    foreach ($statement as $row) {
        $units[] = [
            'code' => (string) $row['unit_code'],
            'name' => (string) $row['name'],
            'ward' => (string) $row['ward_name'],
            'lga' => (string) $row['lga_name'],
        ];
    }

    return $units;
}

/**
 * @return array<string, mixed>|null
 */
function an_find(string $notificationId): ?array
{
    $statement = db()->prepare(
        'SELECT n.*, u.username, u.full_name, u.email AS current_email, u.role, u.is_active,
                u.password_changed_at
         FROM agent_notifications n
         INNER JOIN users u ON u.id = n.user_id
         WHERE n.notification_id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $notificationId]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

/**
 * Sends one recorded notification. The password, when given, is used for this message only.
 *
 * @return array{status: string, error: ?string, email: string}
 */
function an_deliver(string $notificationId, ?string $password = null): array
{
    $claim = db()->prepare(
        "UPDATE agent_notifications
         SET status = 'sending', attempts = attempts + 1
         WHERE notification_id = :id
           AND (status IN ('queued', 'failed', 'not_configured', 'captured')
                OR (status = 'sending' AND updated_at < NOW() - INTERVAL " . (int) AN_STALE_SENDING_MINUTES . ' MINUTE))'
    );
    $claim->execute(['id' => $notificationId]);
    $row = an_find($notificationId);
    if ($row === null) {
        return ['status' => 'failed', 'error' => 'not_found', 'email' => ''];
    }
    if ($claim->rowCount() !== 1) {
        return ['status' => (string) $row['status'], 'error' => 'not_pending', 'email' => (string) $row['recipient_email']];
    }

    $email = strtolower(trim((string) $row['current_email']));
    $finish = static function (string $status, ?string $error, ?string $transport, array $units) use ($row, $email): array {
        $codes = substr(implode(', ', array_column($units, 'code')), 0, 1000);
        $update = db()->prepare(
            "UPDATE agent_notifications
             SET status = :status, last_error = :error, transport = :transport, recipient_email = :email,
                 unit_codes = :codes, sent_at = IF(:sent = 1, NOW(), sent_at)
             WHERE notification_id = :id AND status = 'sending'"
        );
        $update->execute([
            'status' => $status,
            'error' => $error,
            'transport' => $transport,
            'email' => $email,
            'codes' => $codes === '' ? null : $codes,
            'sent' => in_array($status, ['sent', 'captured'], true) ? 1 : 0,
            'id' => (string) $row['notification_id'],
        ]);
        an_audit($row, $status, $error, $transport, $email);

        return ['status' => $status, 'error' => $error, 'email' => $email];
    };

    if ((string) $row['role'] !== 'polling_agent' || (int) $row['is_active'] !== 1) {
        return $finish('failed', 'account_inactive', null, []);
    }
    if ((int) $row['includes_password'] === 1 && ($password === null || $password === '')) {
        return $finish('failed', 'password_required', null, []);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        return $finish('failed', 'invalid_recipient', null, []);
    }
    $status = mailer_status();
    if (!$status['ready']) {
        return $finish('not_configured', $status['reason'], $status['transport'], []);
    }

    $units = an_units((int) $row['user_id']);
    $agent = ['full_name' => (string) $row['full_name'], 'username' => (string) $row['username'], 'email' => $email];
    $message = an_compose($agent, $units, (string) $row['kind'], (int) $row['includes_password'] === 1 ? $password : null);
    $result = mailer_send($message);

    return $finish($result['status'], $result['error'], $result['transport'], $units);
}

/**
 * @param array<string, mixed> $row
 */
function an_audit(array $row, string $status, ?string $error, ?string $transport, string $email): void
{
    $what = (string) $row['kind'] === 'account_created' ? 'Account and assignment email' : 'Assignment update email';
    $outcome = match ($status) {
        'sent' => 'accepted by the mail server',
        'captured' => 'captured locally, not sent',
        'not_configured' => 'not sent, email is not configured',
        default => 'delivery failed (' . ($error ?? 'unknown') . ')',
    };
    try {
        auth_audit(
            isset($row['created_by']) ? (int) $row['created_by'] : null,
            'agent_email_' . $status,
            (int) $row['user_id'],
            $what . ' for ' . $row['username'] . ' to ' . $email . ': ' . $outcome
                . ($transport !== null ? ' via ' . $transport : '') . '. Ref ' . substr((string) $row['notification_id'], 0, 8) . '.'
        );
    } catch (Throwable $exception) {
        error_log('Notification audit failed: ' . $exception::class);
    }
}

/**
 * A failed or unconfigured email can be retried. An account email needs a new password because
 * the original one is not kept; this resets the hash and never creates a second account.
 *
 * @return array{ok: bool, error: string, status?: string, email?: string, detail?: ?string}
 */
function an_retry(string $notificationId, int $actorId, ?string $newPassword = null, ?string $confirm = null): array
{
    if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $notificationId)) {
        return ['ok' => false, 'error' => 'That notification was not found.'];
    }
    $row = an_find($notificationId);
    if ($row === null) {
        return ['ok' => false, 'error' => 'That notification was not found.'];
    }
    if (!in_array((string) $row['status'], AN_RETRYABLE, true)) {
        return ['ok' => false, 'error' => 'This email is not waiting for a retry.'];
    }

    $password = null;
    if ((int) $row['includes_password'] === 1) {
        if ($row['password_changed_at'] !== null) {
            return ['ok' => false, 'error' => 'The agent has already set their own password, so account details are not sent again.'];
        }
        $password = (string) $newPassword;
        if (strlen($password) < 12 || strlen($password) > 128) {
            return ['ok' => false, 'error' => 'Enter a new password of 12 to 128 characters. The original password is not stored, so it cannot be re-sent.'];
        }
        if (!hash_equals($password, (string) $confirm)) {
            return ['ok' => false, 'error' => 'The passwords do not match.'];
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare(
                'UPDATE users SET password_hash = :hash, must_change_password = 1
                 WHERE id = :id AND role = \'polling_agent\' AND password_changed_at IS NULL'
            );
            $update->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => (int) $row['user_id']]);
            if ($update->rowCount() !== 1) {
                $pdo->rollBack();

                return ['ok' => false, 'error' => 'The agent account could not be updated.'];
            }
            $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = :id')->execute(['id' => (int) $row['user_id']]);
            auth_audit($actorId, 'reset_agent_password', (int) $row['user_id'], 'New starting password set for ' . $row['username'] . ' to retry the account email.');
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    $touch = db()->prepare('UPDATE agent_notifications SET created_by = :actor WHERE notification_id = :id');
    $touch->execute(['actor' => $actorId, 'id' => $notificationId]);
    $result = an_deliver($notificationId, $password);

    return ['ok' => true, 'error' => '', 'status' => $result['status'], 'email' => $result['email'], 'detail' => $result['error']];
}

/**
 * Recent notifications for one agent, newest first.
 *
 * @return list<array<string, mixed>>
 */
function an_recent_for_user(int $userId, int $limit = 6): array
{
    $statement = db()->prepare(
        'SELECT notification_id, kind, includes_password, recipient_email, status, attempts, transport,
                last_error, unit_codes, created_at, updated_at, sent_at
         FROM agent_notifications
         WHERE user_id = :user
         ORDER BY id DESC
         LIMIT ' . max(1, min($limit, 20))
    );
    $statement->bindValue(':user', $userId, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function an_error_text(?string $error): string
{
    return match ($error) {
        'invalid_recipient' => 'the agent’s saved email address is not valid',
        'recipient_rejected' => 'the mail server rejected the agent’s address',
        'smtp_unavailable' => 'the mail server could not be reached',
        'smtp_auth_failed' => 'the mail server refused the configured sign-in',
        'tls_failed' => 'a secure connection to the mail server could not be made',
        'account_inactive' => 'the agent account is inactive',
        'password_required' => 'a new starting password is needed',
        'logo_missing' => 'the email logo file is missing',
        'capture_unwritable' => 'the local capture folder is not writable',
        'disabled' => 'email is turned off in this environment',
        'app_url_missing' => 'the application address (app_url) is not configured',
        'sender_missing' => 'the sender address is not configured',
        'smtp_host_missing' => 'the mail server is not configured',
        'insecure_transport' => 'the mail server settings do not use an encrypted connection',
        'library_missing' => 'the mail library is not installed',
        default => 'the message could not be sent',
    };
}

/**
 * Truthful wording for administrators. An accepted message is not proof of inbox delivery.
 */
function an_status_text(string $status, ?string $error, string $email): string
{
    return match ($status) {
        'sent' => 'Email handed to the mail server for ' . $email . '. Delivery to the inbox is not confirmed.',
        'captured' => 'Email captured locally for ' . $email . ' (test mode). Nothing was sent.',
        'not_configured' => 'Email is not configured in this environment (' . an_error_text($error) . '), so no email was sent. You can retry once email is set up.',
        'queued', 'sending' => 'Email queued for ' . $email . '.',
        'superseded' => 'Replaced by a newer assignment email.',
        default => 'Email delivery failed: ' . an_error_text($error) . '. The assignment is saved; you can retry from the unit page.',
    };
}

/**
 * Queues and immediately attempts one email per affected agent, after the change has committed.
 *
 * @param list<int> $userIds
 * @return array{ok: bool, text: string} ok is false when any email was not handed off or captured
 */
function an_notify_assignment_change(array $userIds, int $actorId): array
{
    if (!an_schema_ready()) {
        return ['ok' => false, 'text' => 'Assignment emails are unavailable until the notification migration is applied.'];
    }
    $messages = [];
    $ok = true;
    foreach (array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0))) as $userId) {
        try {
            $id = an_queue($userId, 'assignment_changed', $actorId);
            if ($id === null) {
                continue;
            }
            $result = an_deliver($id);
            $ok = $ok && in_array($result['status'], ['sent', 'captured'], true);
            $messages[] = an_status_text($result['status'], $result['error'], $result['email']);
        } catch (Throwable $exception) {
            error_log('Assignment notification error: ' . $exception::class . ': ' . $exception->getMessage());
            $ok = false;
            $messages[] = 'The assignment is saved, but the email could not be recorded. Check the server log.';
        }
    }

    return ['ok' => $ok, 'text' => implode(' ', $messages)];
}

/**
 * Records and sends the account email for a newly registered agent.
 * The password is passed through to this one message and is not stored.
 *
 * @return array{ok: bool, text: string}
 */
function an_send_account_email(?string $notificationId, string $password): array
{
    if ($notificationId === null) {
        return ['ok' => false, 'text' => 'Assignment emails are unavailable until the notification migration is applied.'];
    }
    try {
        $result = an_deliver($notificationId, $password);

        return [
            'ok' => in_array($result['status'], ['sent', 'captured'], true),
            'text' => an_status_text($result['status'], $result['error'], $result['email']),
        ];
    } catch (Throwable $exception) {
        error_log('Account notification error: ' . $exception::class . ': ' . $exception->getMessage());

        return ['ok' => false, 'text' => 'The email could not be sent. You can retry from this page.'];
    }
}

/**
 * @param array{full_name: string, username: string, email: string} $agent
 * @param list<array{code: string, name: string, ward: string, lga: string}> $units
 * @return array{to_email: string, to_name: string, subject: string, html: string, text: string, inline: array<string, array{path: string, mime: string, name: string}>, redact: list<string>}
 */
function an_compose(array $agent, array $units, string $kind, ?string $password): array
{
    $account = $kind === 'account_created';
    $subject = $account ? 'Your Polling Agent Assignment and Account Details' : 'Your Polling Agent Assignment Has Been Updated';
    $loginUrl = (string) mailer_app_url() . '/portal/login';
    $name = $agent['full_name'];
    $count = count($units);
    $intro = $account
        ? 'An administrator has created your polling agent account for the Ohaji/Egbema election monitoring portal and assigned you to the polling unit' . ($count === 1 ? '' : 's') . ' below.'
        : 'An administrator has changed your polling-unit assignment. The list below is your complete current assignment and replaces any earlier email.';
    if ($count === 0) {
        $intro = $account
            ? 'An administrator has created your polling agent account for the Ohaji/Egbema election monitoring portal. No polling unit is currently assigned to you.'
            : 'An administrator has changed your polling-unit assignment. You currently have no polling units assigned, so result entry is not available to you until an administrator assigns one.';
    }
    $steps = $account
        ? [
            'Open the portal sign-in page: ' . $loginUrl,
            'Sign in with your username and the temporary password in this email.',
            'You will be asked to choose a new password straight away (at least 12 characters). The temporary password stops working once you change it.',
            'Open your polling unit from the dashboard to record and submit results.',
        ]
        : [
            'Sign in at ' . $loginUrl . ' with your username and your own password.',
            'Your dashboard lists only the polling units shown in this email.',
        ];
    $reminder = 'Never share your username or password with anyone, including other agents or coordinators. Administrators will never ask you for your password. If you did not expect this email, contact your coordinator.';
    if ($account) {
        $reminder .= ' Delete this email after you have changed your password.';
    }

    $font = "font-family:Arial,Helvetica,sans-serif;";
    $row = static fn (string $label, string $value, bool $mono = false): string =>
        '<tr><td style="' . $font . 'padding:6px 12px 6px 0;font-size:14px;color:#52606d;white-space:nowrap;vertical-align:top;">' . e($label) . '</td>'
        . '<td style="' . ($mono ? "font-family:'Courier New',Courier,monospace;font-size:16px;letter-spacing:0.5px;" : $font . 'font-size:15px;') . 'padding:6px 0;color:#1f2933;font-weight:bold;word-break:break-all;">' . e($value) . '</td></tr>';

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="x-apple-disable-message-reformatting"><title>' . e($subject) . '</title></head>'
        . '<body style="margin:0;padding:0;background-color:#eef2f6;">'
        . '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">' . e($account ? 'Your polling agent account and assigned polling units.' : 'Your current polling-unit assignment.') . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#eef2f6;"><tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background-color:#ffffff;border:1px solid #d5dde6;border-radius:8px;">'
        . '<tr><td align="center" style="background-color:#0e2a4a;padding:28px 24px;border-radius:8px 8px 0 0;">'
        . '<img src="cid:' . AN_LOGO_CID . '" width="' . AN_LOGO_WIDTH . '" height="' . AN_LOGO_HEIGHT . '" alt="' . e(AN_LOGO_ALT) . '" style="display:block;width:' . AN_LOGO_WIDTH . 'px;max-width:100%;height:auto;border:0;border-radius:6px;outline:none;text-decoration:none;">'
        . '<p style="' . $font . 'margin:14px 0 0;font-size:19px;font-weight:bold;color:#ffffff;">Election Portal</p>'
        . '<p style="' . $font . 'margin:4px 0 0;font-size:13px;color:#c5d0dc;">Ohaji/Egbema &middot; Independent election monitoring</p>'
        . '</td></tr>'
        . '<tr><td style="' . $font . 'padding:28px 28px 4px;font-size:15px;line-height:1.6;color:#1f2933;">'
        . '<h1 style="' . $font . 'margin:0 0 14px;font-size:21px;line-height:1.3;color:#0e2a4a;">' . e($account ? 'Your polling agent account is ready' : 'Your polling-unit assignment has changed') . '</h1>'
        . '<p style="margin:0 0 12px;">Dear ' . e($name) . ',</p>'
        . '<p style="margin:0 0 16px;">' . e($intro) . '</p>'
        . '</td></tr>';

    $html .= '<tr><td style="padding:0 28px 8px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f5f8fb;border:1px solid #d5dde6;border-radius:6px;"><tr><td style="padding:14px 16px;">'
        . '<p style="' . $font . 'margin:0 0 6px;font-size:13px;font-weight:bold;text-transform:uppercase;letter-spacing:0.6px;color:#1d6b45;">Your sign-in details</p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0">'
        . $row('Username', $agent['username'], true)
        . ($account && $password !== null ? $row('Temporary password', $password, true) : '')
        . $row('Portal', $loginUrl)
        . '</table>'
        . ($account ? '' : '<p style="' . $font . 'margin:8px 0 0;font-size:13px;color:#52606d;">Your password has not changed and is not included in this email.</p>')
        . '</td></tr></table></td></tr>';

    $html .= '<tr><td style="' . $font . 'padding:16px 28px 0;"><p style="margin:0 0 8px;font-size:13px;font-weight:bold;text-transform:uppercase;letter-spacing:0.6px;color:#1d6b45;">'
        . e($count === 1 ? 'Assigned polling unit' : 'Assigned polling units (' . $count . ')') . '</p></td></tr>';
    if ($count === 0) {
        $html .= '<tr><td style="' . $font . 'padding:0 28px 8px;font-size:15px;color:#1f2933;">No polling unit is assigned to you at the moment.</td></tr>';
    }
    foreach ($units as $unit) {
        $html .= '<tr><td style="padding:0 28px 10px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #d5dde6;border-left:4px solid #1d6b45;border-radius:6px;"><tr><td style="padding:12px 16px;">'
            . '<p style="' . $font . 'margin:0 0 6px;font-size:16px;font-weight:bold;color:#0e2a4a;">' . e($unit['name']) . '</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0">'
            . $row('Polling Unit ID', $unit['code'], true)
            . $row('Ward', $unit['ward'])
            . $row('LGA', $unit['lga'])
            . $row('State', AN_STATE)
            . '</table></td></tr></table></td></tr>';
    }

    $html .= '<tr><td align="center" style="padding:18px 28px 6px;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="background-color:#1d6b45;border-radius:6px;">'
        . '<a href="' . e($loginUrl) . '" style="' . $font . 'display:inline-block;padding:12px 26px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;">Sign in to the portal</a>'
        . '</td></tr></table>'
        . '<p style="' . $font . 'margin:10px 0 0;font-size:12px;color:#52606d;word-break:break-all;">' . e($loginUrl) . '</p>'
        . '</td></tr>';

    $html .= '<tr><td style="' . $font . 'padding:16px 28px 0;font-size:15px;line-height:1.6;color:#1f2933;">'
        . '<p style="margin:0 0 6px;font-weight:bold;color:#0e2a4a;">' . e($account ? 'First sign-in' : 'What to do next') . '</p><ol style="margin:0 0 8px;padding-left:20px;">';
    foreach ($steps as $step) {
        $html .= '<li style="margin:0 0 6px;">' . e($step) . '</li>';
    }
    $html .= '</ol></td></tr>';

    $html .= '<tr><td style="padding:8px 28px 24px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#fff8e6;border:1px solid #f0d58c;border-radius:6px;"><tr><td style="' . $font . 'padding:12px 16px;font-size:14px;line-height:1.5;color:#5c4400;">'
        . '<strong>Keep your account safe.</strong> ' . e($reminder)
        . '</td></tr></table></td></tr>';

    $html .= '<tr><td style="' . $font . 'padding:16px 28px 24px;border-top:1px solid #e4e9ef;font-size:12px;line-height:1.5;color:#6b7785;">'
        . '<p style="margin:0 0 6px;">' . e(AN_FOOTER) . '</p>'
        . '<p style="margin:0;">Sent to ' . e($agent['email']) . ' because an administrator assigned you as a polling agent.</p>'
        . '</td></tr></table></td></tr></table></body></html>';

    $text = [];
    $text[] = 'ELECTION PORTAL - OHAJI/EGBEMA';
    $text[] = 'Independent election monitoring';
    $text[] = '';
    $text[] = $account ? 'Your polling agent account is ready' : 'Your polling-unit assignment has changed';
    $text[] = '';
    $text[] = 'Dear ' . an_plain($name) . ',';
    $text[] = '';
    $text[] = $intro;
    $text[] = '';
    $text[] = 'YOUR SIGN-IN DETAILS';
    $text[] = 'Username: ' . an_plain($agent['username']);
    if ($account && $password !== null) {
        $text[] = 'Temporary password: ' . $password;
    } else {
        $text[] = 'Your password has not changed and is not included in this email.';
    }
    $text[] = 'Portal: ' . $loginUrl;
    $text[] = '';
    $text[] = $count === 1 ? 'ASSIGNED POLLING UNIT' : 'ASSIGNED POLLING UNITS (' . $count . ')';
    if ($count === 0) {
        $text[] = 'No polling unit is assigned to you at the moment.';
    }
    foreach ($units as $index => $unit) {
        $text[] = ($index + 1) . '. ' . an_plain($unit['name']);
        $text[] = '   Polling Unit ID: ' . an_plain($unit['code']);
        $text[] = '   Ward: ' . an_plain($unit['ward']);
        $text[] = '   LGA: ' . an_plain($unit['lga']) . ', ' . AN_STATE;
    }
    $text[] = '';
    $text[] = $account ? 'FIRST SIGN-IN' : 'WHAT TO DO NEXT';
    foreach ($steps as $index => $step) {
        $text[] = ($index + 1) . '. ' . $step;
    }
    $text[] = '';
    $text[] = 'KEEP YOUR ACCOUNT SAFE: ' . $reminder;
    $text[] = '';
    $text[] = '--';
    $text[] = AN_FOOTER;
    $text[] = 'Sent to ' . an_plain($agent['email']) . ' because an administrator assigned you as a polling agent.';

    return [
        'to_email' => $agent['email'],
        'to_name' => an_plain($name),
        'subject' => $subject,
        'html' => $html,
        'text' => implode("\n", $text) . "\n",
        'inline' => [AN_LOGO_CID => ['path' => dirname(__DIR__) . '/' . AN_LOGO_FILE, 'mime' => AN_LOGO_MIME, 'name' => basename(AN_LOGO_FILE)]],
        'redact' => $password !== null ? [$password, e($password)] : [],
    ];
}

function an_plain(string $value): string
{
    return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
}
