<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

const MAILER_TRANSPORTS = ['disabled', 'capture', 'smtp'];
const MAILER_TIMEOUT_SECONDS = 15;
const MAILER_CONNECT_ATTEMPTS = 2;
const MAILER_RECONNECT_DELAY_SECONDS = 2;
const MAILER_CAPTURE_PLACEHOLDER = '[password withheld from the local capture copy]';

function mailer_library_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $ready = is_file($autoload) && (require_once $autoload) !== false && class_exists(PHPMailer::class);
    }

    return $ready;
}

/**
 * @return array<string, mixed>
 */
function &mailer_overrides(): array
{
    static $overrides = [];

    return $overrides;
}

/**
 * Command-line tests may replace mail settings for their own process.
 * The 'memory' transport keeps messages in this process only.
 *
 * @param array<string, mixed> $settings
 */
function mailer_override(array $settings): void
{
    if (PHP_SAPI !== 'cli') {
        throw new LogicException('Mail settings can only be overridden from the command line.');
    }
    $overrides = &mailer_overrides();
    $overrides = $settings;
}

/**
 * @return list<array<string, mixed>>
 */
function &mailer_memory_outbox(): array
{
    static $outbox = [];

    return $outbox;
}

/**
 * @return array{app_url: string, mail_transport: string, mail_from: string, mail_from_name: string, mail_capture_dir: string, smtp_host: string, smtp_port: int, smtp_encryption: string, smtp_username: string, smtp_password: string}
 */
function mailer_config(): array
{
    $config = app_config();
    $settings = [];
    foreach (['app_url', 'mail_transport', 'mail_from', 'mail_from_name', 'mail_capture_dir', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password'] as $key) {
        $settings[$key] = $config[$key];
    }
    if (PHP_SAPI === 'cli') {
        $settings = array_merge($settings, mailer_overrides());
    }

    return $settings;
}

function mailer_is_local_host(string $host): bool
{
    return in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
}

/**
 * Canonical application address for links in email, or null when it is not configured safely.
 */
function mailer_app_url(): ?string
{
    $url = rtrim((string) mailer_config()['app_url'], '/');
    $parts = $url === '' ? false : parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])) {
        return null;
    }
    $scheme = strtolower((string) $parts['scheme']);
    if ($scheme !== 'https' && !($scheme === 'http' && mailer_is_local_host((string) $parts['host']))) {
        return null;
    }

    return $url;
}

function mailer_sender(): string
{
    $config = mailer_config();
    $from = trim((string) $config['mail_from']);
    if ($from === '' && $config['mail_transport'] !== 'smtp') {
        $from = 'no-reply@election-monitor.invalid';
    }

    return $from;
}

/**
 * @return array{ready: bool, transport: string, reason: string}
 */
function mailer_status(): array
{
    $config = mailer_config();
    $transport = (string) $config['mail_transport'];
    $result = static fn (bool $ready, string $reason): array => ['ready' => $ready, 'transport' => $transport, 'reason' => $reason];

    if ($transport === 'memory' && PHP_SAPI === 'cli') {
        return $result(mailer_app_url() !== null, mailer_app_url() !== null ? '' : 'app_url_missing');
    }
    if (!in_array($transport, MAILER_TRANSPORTS, true)) {
        return $result(false, 'unknown_transport');
    }
    if ($transport === 'disabled') {
        return $result(false, 'disabled');
    }
    if (!mailer_library_ready()) {
        return $result(false, 'library_missing');
    }
    if (mailer_app_url() === null) {
        return $result(false, 'app_url_missing');
    }
    if (!PHPMailer::validateAddress(mailer_sender())) {
        return $result(false, 'sender_missing');
    }
    if ($transport === 'smtp') {
        $host = trim((string) $config['smtp_host']);
        $port = (int) $config['smtp_port'];
        $encryption = (string) $config['smtp_encryption'];
        if ($host === '' || $port < 1 || $port > 65535) {
            return $result(false, 'smtp_host_missing');
        }
        if (!in_array($encryption, ['tls', 'ssl'], true) && !($encryption === 'none' && mailer_is_local_host($host))) {
            return $result(false, 'insecure_transport');
        }
    }

    return $result(true, '');
}

/**
 * @param array{to_email: string, to_name: string, subject: string, html: string, text: string, inline?: array<string, array{path: string, mime: string, name: string}>, redact?: list<string>} $message
 * @return array{status: string, transport: string, error: ?string, reference: ?string}
 */
function mailer_send(array $message): array
{
    $status = mailer_status();
    $transport = $status['transport'];
    $outcome = static fn (string $state, ?string $error = null, ?string $reference = null): array => [
        'status' => $state,
        'transport' => $transport,
        'error' => $error,
        'reference' => $reference,
    ];
    if (!$status['ready']) {
        return $outcome('not_configured', $status['reason']);
    }

    if ($transport === 'memory') {
        $outbox = &mailer_memory_outbox();
        $reference = bin2hex(random_bytes(8));
        $outbox[] = $message + ['reference' => $reference, 'from' => mailer_sender()];

        return $outcome('sent', null, $reference);
    }

    $config = mailer_config();
    $redact = array_values(array_filter($message['redact'] ?? [], static fn ($value): bool => is_string($value) && $value !== ''));
    if ($transport === 'capture') {
        $message['html'] = str_replace($redact, MAILER_CAPTURE_PLACEHOLDER, $message['html']);
        $message['text'] = str_replace($redact, MAILER_CAPTURE_PLACEHOLDER, $message['text']);
    }

    $mail = new PHPMailer(true);
    try {
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
        $mail->XMailer = ' ';
        $mail->Timeout = MAILER_TIMEOUT_SECONDS;
        $mail->setFrom(mailer_sender(), (string) $config['mail_from_name'], false);
        if (!PHPMailer::validateAddress($message['to_email'])) {
            return $outcome('failed', 'invalid_recipient');
        }
        $mail->addAddress($message['to_email'], $message['to_name']);
        $mail->Subject = $message['subject'];
        $mail->isHTML(true);
        $mail->Body = $message['html'];
        $mail->AltBody = $message['text'];
        foreach ($message['inline'] ?? [] as $cid => $image) {
            if (!is_file($image['path']) || !$mail->addEmbeddedImage($image['path'], $cid, $image['name'], PHPMailer::ENCODING_BASE64, $image['mime'])) {
                return $outcome('failed', 'logo_missing');
            }
        }

        if ($transport === 'capture') {
            // SMTP mode keeps the Subject and To headers in the stored copy; nothing is connected.
            $mail->isSMTP();
            $mail->preSend();
            $dir = rtrim((string) $config['mail_capture_dir'], '\\/');
            if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                return $outcome('failed', 'capture_unwritable');
            }
            $reference = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6));
            if (file_put_contents($dir . DIRECTORY_SEPARATOR . $reference . '.eml', $mail->getSentMIMEMessage(), LOCK_EX) === false) {
                return $outcome('failed', 'capture_unwritable');
            }

            return $outcome('captured', null, $reference);
        }

        $mail->isSMTP();
        $mail->SMTPDebug = 0;
        $mail->Host = (string) $config['smtp_host'];
        $mail->Port = (int) $config['smtp_port'];
        $mail->SMTPAutoTLS = false;
        $mail->SMTPSecure = match ((string) $config['smtp_encryption']) {
            'ssl' => PHPMailer::ENCRYPTION_SMTPS,
            'tls' => PHPMailer::ENCRYPTION_STARTTLS,
            default => '',
        };
        $username = (string) $config['smtp_username'];
        if ($username !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = (string) $config['smtp_password'];
        }
        for ($attempt = 1; ; $attempt++) {
            try {
                $mail->send();
                break;
            } catch (MailerException $exception) {
                // A failed connection means nothing was handed over, so one reconnect cannot duplicate the message.
                if ($attempt >= MAILER_CONNECT_ATTEMPTS || mailer_error_code($mail->ErrorInfo) !== 'smtp_unavailable') {
                    throw $exception;
                }
                $mail->smtpClose();
                sleep(MAILER_RECONNECT_DELAY_SECONDS);
            }
        }

        return $outcome('sent', null, $mail->getLastMessageID());
    } catch (MailerException $exception) {
        $detail = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $exception->getMessage();
        $code = mailer_error_code($detail);
        $secrets = array_merge($redact, array_filter([(string) $config['smtp_password'], (string) $config['smtp_username']]));
        error_log('Mail delivery failed (' . $code . '): ' . substr(str_replace($secrets, '[redacted]', str_replace(["\r", "\n"], ' ', $detail)), 0, 300));

        return $outcome('failed', $code);
    }
}

function mailer_error_code(string $detail): string
{
    $detail = strtolower($detail);

    return match (true) {
        str_contains($detail, 'could not authenticate') => 'smtp_auth_failed',
        str_contains($detail, 'connect') => 'smtp_unavailable',
        str_contains($detail, 'recipients failed') => 'recipient_rejected',
        str_contains($detail, 'invalid address') => 'invalid_recipient',
        str_contains($detail, 'starttls'), str_contains($detail, 'tls') => 'tls_failed',
        default => 'send_failed',
    };
}
