<?php
declare(strict_types=1);

/**
 * Copy this file to config/env.php and set the local credentials.
 * Environment variables override these values when they are set:
 * DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS.
 * DB_USER and DB_PASS (or db_user / db_pass below) are required. There is no silent
 * fallback to MySQL root with an empty password.
 * DB_PASS overrides this file even when it is an empty string (local XAMPP).
 *
 * Deployment: create a dedicated MySQL user with rights only on election_monitor_app, e.g.
 *   CREATE USER 'em_app'@'localhost' IDENTIFIED BY 'a-long-random-password';
 *   GRANT SELECT, INSERT, UPDATE, DELETE ON election_monitor_app.* TO 'em_app'@'localhost';
 *   FLUSH PRIVILEGES;
 * Do not use the MySQL root account on a shared or production host.
 *
 * SMTP: if a mailbox password was ever exposed (chat, screenshots, shared env files), rotate it
 * in the provider control panel and update smtp_password / SMTP_PASSWORD here. The application
 * cannot rotate provider credentials itself. Treat C2 as open until that rotation is done.
 *
 * Web root: production deploys should exclude .git (and never publish config/env.php). The
 * application .htaccess also denies .git, .env, dumps, and private paths as a second layer.
 *
 * Password-setup links: apply database/migration_password_setup_tokens.sql before creating
 * new polling agents. Account emails use a single-use HTTPS setup link instead of a temporary password.
 *
 * private_dir (env EM_PRIVATE_DIR) holds result-sheet originals and must be outside the web root
 * and outside this application directory. Default: sibling of htdocs, e.g. C:\xampp\election-monitor-private.
 * sheet_key_file (env EM_SHEET_KEY_FILE) is the HMAC key file. Create it with
 * php scripts/sheet_keys.php init. Never commit it, copy it into the database, or place it under htdocs
 * or under election-monitor-app/.
 *
 * trusted_proxies [TRUSTED_PROXIES]  Comma-separated IPs of reverse proxies allowed to set
 *   X-Forwarded-Proto / X-Forwarded-SSL. Empty means those headers are ignored (safe default).
 * force_https [FORCE_HTTPS]         When true, plain-HTTP requests are redirected (enable only when
 *   the public site already serves HTTPS correctly).
 * enable_hsts [ENABLE_HSTS]         When true and the request is HTTPS, send Strict-Transport-Security.
 *   Enable only after HTTPS works for the production domain and any required subdomains.
 *
 * Authorization note: supervisor, collation_officer, and viewer roles can read provisional typed
 * results for all units by design. Narrowing that scope is a product decision, not a silent default.
 *
 * Assignment emails (env names in brackets override this file):
 * app_url [APP_URL]            Canonical address used in email links, e.g. https://monitor.example.org.
 *                              Plain http is accepted only for localhost.
 * mail_transport [MAIL_TRANSPORT]
 *   disabled  (default) nothing is sent; administrators are told email is not configured.
 *   capture   local testing: each message is written as an .eml file to mail_capture_dir with any
 *             secret replaced by a placeholder. Nothing leaves the machine.
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
    // 'trusted_proxies' => '127.0.0.1',
    // 'force_https' => false,
    // 'enable_hsts' => false,
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
