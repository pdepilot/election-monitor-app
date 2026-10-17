<?php
declare(strict_types=1);

/**
 * Copy this file to config/env.php and set the local credentials.
 * Environment variables override these values when they are set:
 * DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS.
 * DB_PASS overrides this file even when it is an empty string.
 *
 * private_dir (env EM_PRIVATE_DIR) holds result-sheet originals and must be outside the web root.
 * Default: the folder next to htdocs, e.g. C:\xampp\election-monitor-private.
 * sheet_key_file (env EM_SHEET_KEY_FILE) is the HMAC key file. Create it with
 * php scripts/sheet_keys.php init. Never commit it, copy it into the database, or place it under htdocs.
 *
 * Assignment emails (env names in brackets override this file):
 * app_url [APP_URL]            Canonical address used in email links, e.g. https://monitor.example.org.
 *                              Plain http is accepted only for localhost.
 * mail_transport [MAIL_TRANSPORT]
 *   disabled  (default) nothing is sent; administrators are told email is not configured.
 *   capture   local testing: each message is written as an .eml file to mail_capture_dir with any
 *             password replaced by a placeholder. Nothing leaves the machine.
 *   smtp      sends through smtp_host. Requires smtp_encryption tls (STARTTLS) or ssl (SMTPS);
 *             none is accepted only for a mail catcher on localhost. There is no fallback transport.
 * smtp_password [SMTP_PASSWORD] belongs in this local file or the environment, never in source control.
 */
return [
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'election_monitor_app',
    'db_user' => 'root',
    'db_pass' => '',
    // 'private_dir' => 'C:\\xampp\\election-monitor-private',
    // 'sheet_key_file' => 'C:\\xampp\\election-monitor-private\\keys\\sheet-hmac.json',
    'app_url' => 'http://localhost/election-monitor-app',
    'mail_transport' => 'capture',
    // 'mail_capture_dir' => 'C:\\xampp\\htdocs\\election-monitor-app\\storage\\mail-capture',
    // 'mail_from' => 'no-reply@monitor.example.org',
    // 'mail_from_name' => 'Ohaji/Egbema Election Monitoring',
    // 'smtp_host' => 'smtp.example.org',
    // 'smtp_port' => 587,
    // 'smtp_encryption' => 'tls',
    // 'smtp_username' => 'no-reply@monitor.example.org',
    // 'smtp_password' => '',
];
