<?php
declare(strict_types=1);

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Session has not been started.');
    }

    $token = $_SESSION['csrf_token'] ?? '';
    if (!is_string($token) || strlen($token) !== 64) {
        $token = csrf_rotate();
    }

    return $token;
}

/** Issue a fresh CSRF token (login, password change, privilege boundary). */
function csrf_rotate(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Session has not been started.');
    }
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;

    return $token;
}

function csrf_verify(?string $token): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!is_string($token) || !is_string($sessionToken) || strlen($sessionToken) !== 64 || strlen($token) !== 64) {
        return false;
    }

    return hash_equals($sessionToken, $token);
}
