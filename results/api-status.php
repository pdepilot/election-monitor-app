<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/result_submissions.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        submission_error(405, 'method_not_allowed', 'Use GET for status.', [], ['Allow' => 'GET']);
    }
    $user = submission_require_agent('status', false);
    $raw = (string) ($_GET['ids'] ?? '');
    $ids = $raw === '' ? [] : array_values(array_unique(explode(',', $raw)));
    if (count($ids) > 50 || array_filter($ids, static fn (string $id): bool => !preg_match(SUBMISSION_UUID_PATTERN, $id)) !== []) {
        submission_error(400, 'bad_request', 'Ask for at most 50 valid submission IDs.');
    }
    submission_respond(200, submission_snapshot($user, $ids));
} catch (Throwable $exception) {
    error_log('Submission status error: ' . $exception::class . ': ' . $exception->getMessage());
    submission_error(500, 'server_error', 'Status is unavailable right now.', [], ['Retry-After' => '30']);
}
