<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/auth.php';

$options = getopt('', ['login:', 'help']);
if ($options === false || isset($options['help']) || !isset($options['login'])) {
    fwrite(STDOUT, <<<TXT
Unlock an election portal account that was locked after failed sign-in attempts.

Usage:
  php scripts/unlock_user.php --login=portal.admin

--login accepts a username or an email address.

TXT);
    exit(isset($options['help']) ? 0 : 1);
}

$login = strtolower(trim((string) $options['login']));
try {
    $find = db()->prepare('SELECT id, username, locked_at FROM users WHERE username = :username OR email = :email LIMIT 1');
    $find->execute(['username' => $login, 'email' => $login]);
    $row = $find->fetch();
    if (!is_array($row)) {
        fwrite(STDERR, "No account matches that username or email.\n");
        exit(1);
    }
    if ($row['locked_at'] === null) {
        fwrite(STDOUT, "Account {$row['username']} is not locked.\n");
        exit(0);
    }
    auth_unlock_account((int) $row['id'], null);
} catch (PDOException $e) {
    error_log('Account unlock failed: ' . $e->getMessage());
    fwrite(STDERR, "The account could not be unlocked.\n");
    exit(1);
}

fwrite(STDOUT, "Account {$row['username']} unlocked.\n");
