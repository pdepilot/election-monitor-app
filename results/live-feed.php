<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/result_sheets.php';

auth_boot();
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$user = auth_user();
if ($user === null || !in_array($user['role'], SHEET_READERS, true) || !sheet_schema_ready()) {
    http_response_code($user === null ? 401 : 403);
    echo '{"items":[]}';
    exit;
}

$after = filter_input(INPUT_GET, 'after', FILTER_VALIDATE_INT);
if (!is_int($after) || $after < 0) {
    $after = 0;
}

try {
    $items = [];
    foreach (sheet_since($after) as $row) {
        $items[] = sheet_public_row($row, (int) $user['id']);
    }
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    echo '{"items":[]}';
    exit;
}

echo json_encode(['items' => $items], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
