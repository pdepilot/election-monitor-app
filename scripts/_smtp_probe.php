<?php
declare(strict_types=1);

// Connects and signs in to the configured SMTP server, then disconnects. Sends nothing.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/mailer.php';

use PHPMailer\PHPMailer\PHPMailer;

$status = mailer_status();
if (!$status['ready'] || $status['transport'] !== 'smtp') {
    fwrite(STDOUT, 'SMTP is not the active, ready transport (' . $status['transport'] . ', ' . ($status['reason'] ?: 'ok') . ").\n");
    exit(1);
}
$config = mailer_config();
$mail = new PHPMailer(true);
$mail->isSMTP();
$mail->Host = (string) $config['smtp_host'];
$mail->Port = (int) $config['smtp_port'];
$mail->SMTPAutoTLS = false;
$mail->SMTPSecure = $config['smtp_encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : ($config['smtp_encryption'] === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
$mail->Timeout = MAILER_TIMEOUT_SECONDS;
if ((string) $config['smtp_username'] !== '') {
    $mail->SMTPAuth = true;
    $mail->Username = (string) $config['smtp_username'];
    $mail->Password = (string) $config['smtp_password'];
}
try {
    $ok = $mail->smtpConnect();
    $mail->smtpClose();
    fwrite(STDOUT, $ok ? "Connected and signed in to {$mail->Host}:{$mail->Port}. No message was sent.\n" : "Could not connect.\n");
    exit($ok ? 0 : 1);
} catch (Throwable $exception) {
    $detail = str_replace([(string) $config['smtp_password']], '[redacted]', $mail->ErrorInfo ?: $exception->getMessage());
    fwrite(STDOUT, 'Failed (' . mailer_error_code($detail) . '): ' . $detail . "\n");
    exit(1);
}
