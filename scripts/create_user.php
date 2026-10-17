<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/auth.php';

function create_user_usage(): void
{
    $roles = implode(', ', array_keys(AUTH_ROLES));
    fwrite(STDOUT, <<<TXT
Create an election portal account.

Usage:
  php scripts/create_user.php --username=portal.admin --email=name@example.com --name="Full Name" --role=super_admin --password="a long passphrase"

Roles: {$roles}

The password must be 12 to 128 characters. It is stored with password_hash() and is not written to the database as plain text.

TXT);
}

$options = getopt('', ['username:', 'email:', 'name:', 'role:', 'password:', 'help']);
if ($options === false) {
    create_user_usage();
    exit(1);
}
if (isset($options['help'])) {
    create_user_usage();
    exit(0);
}
if (!isset($options['username'], $options['email'], $options['name'], $options['role'], $options['password'])) {
    create_user_usage();
    exit(1);
}

$username = strtolower(trim((string) ($options['username'] ?? '')));
$email = strtolower(trim((string) ($options['email'] ?? '')));
$name = trim((string) ($options['name'] ?? ''));
$role = (string) ($options['role'] ?? '');
$password = (string) ($options['password'] ?? '');

if (!preg_match('/^[a-z0-9._-]{3,64}$/', $username)) {
    fwrite(STDERR, "Username must be 3-64 characters and use only letters, numbers, dots, underscores, or hyphens.\n");
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
    fwrite(STDERR, "Enter a valid email address.\n");
    exit(1);
}

if ($name === '' || strlen($name) > 150) {
    fwrite(STDERR, "Full name is required and must be 150 characters or fewer.\n");
    exit(1);
}

if (!isset(AUTH_ROLES[$role])) {
    fwrite(STDERR, "Unknown role.\n");
    exit(1);
}

if (strlen($password) < 12 || strlen($password) > 128) {
    fwrite(STDERR, "Password must be 12 to 128 characters.\n");
    exit(1);
}

try {
    $stmt = db()->prepare(
        'INSERT INTO users (username, email, password_hash, full_name, role, is_active)
         VALUES (:username, :email, :password_hash, :full_name, :role, 1)'
    );
    $stmt->execute([
        'username' => $username,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'full_name' => $name,
        'role' => $role,
    ]);
} catch (PDOException $e) {
    $sqlState = $e->errorInfo[0] ?? '';
    if ($sqlState === '23000') {
        fwrite(STDERR, "That username or email is already in use.\n");
        exit(1);
    }
    error_log('Account creation failed: ' . $e->getMessage());
    fwrite(STDERR, "The account could not be created.\n");
    exit(1);
}

fwrite(STDOUT, "Account created for {$username} with role {$role}.\n");
