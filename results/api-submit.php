<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/result_submissions.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        submission_error(405, 'method_not_allowed', 'Use POST to submit results.', [], ['Allow' => 'POST']);
    }
    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        submission_error(415, 'unsupported_media_type', 'Submissions must be sent as JSON.');
    }
    $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declared > SUBMISSION_MAX_BODY) {
        submission_error(413, 'payload_too_large', 'The submission is too large.');
    }
    $user = submission_require_agent('submit', true);
    $raw = (string) file_get_contents('php://input', false, null, 0, SUBMISSION_MAX_BODY + 1);
    if (strlen($raw) > SUBMISSION_MAX_BODY) {
        submission_error(413, 'payload_too_large', 'The submission is too large.');
    }
    try {
        $json = json_decode($raw, true, 6, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        submission_error(400, 'bad_request', 'The submission is not valid JSON.');
    }
    submission_process($user, submission_parse($json));
} catch (Throwable $exception) {
    error_log('Submission API error: ' . $exception::class . ': ' . $exception->getMessage());
    submission_error(500, 'server_error', 'The server could not process the submission. It stays queued on this device.', [], ['Retry-After' => '30']);
}
