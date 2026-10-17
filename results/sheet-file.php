<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/result_sheets.php';

auth_boot();
$user = auth_user();
if ($user === null) {
    http_response_code(401);
    exit;
}
if (!sheet_schema_ready()) {
    http_response_code(404);
    exit;
}
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$upload = is_int($id) && $id > 0 ? sheet_find($id) : null;
if ($upload === null) {
    http_response_code(404);
    exit;
}
if (!sheet_can_view($user, $upload)) {
    sheet_log_denied($user, (int) $upload['id'], (int) $upload['polling_unit_id'], 'result-sheet file');
    http_response_code(403);
    exit;
}
try {
    sheet_send($upload, (int) $user['id']);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    exit;
}
