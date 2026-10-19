<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/sheet_upload_api.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        submission_error(405, 'method_not_allowed', 'Use POST to upload a result sheet.', [], ['Allow' => 'POST']);
    }
    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'multipart/form-data') {
        submission_error(415, 'unsupported_media_type', 'Result-sheet uploads must be sent as multipart form data.');
    }
    $user = submission_require_agent('submit', true);
    if (!sheet_upload_api_schema_ready()) {
        submission_error(503, 'temporarily_unavailable', 'Result-sheet upload is not available on the server yet.', [], ['Retry-After' => '60']);
    }
    $fields = [
        'client_upload_id' => $_POST['client_upload_id'] ?? '',
        'polling_unit_id' => $_POST['polling_unit_id'] ?? '',
        'upload_kind' => $_POST['upload_kind'] ?? '',
        'upload_reason' => $_POST['upload_reason'] ?? '',
    ];
    $file = is_array($_FILES['result_sheet'] ?? null) ? $_FILES['result_sheet'] : [];
    $outcome = sheet_upload_api_process($user, $fields, $file);
    if ($outcome['ok']) {
        submission_respond($outcome['status'], $outcome['json']);
    }
    submission_error(
        $outcome['status'],
        $outcome['code'],
        $outcome['message'],
        is_array($outcome['extra'] ?? null) ? $outcome['extra'] : []
    );
} catch (Throwable $exception) {
    error_log('Sheet upload API error: ' . $exception::class . ': ' . $exception->getMessage());
    submission_error(500, 'server_error', 'The server could not store the result sheet. It stays queued on this device.', [], ['Retry-After' => '30']);
}
