<?php
declare(strict_types=1);

/*
 * Idempotent result-sheet upload API for polling agents (online or after offline queue).
 * client_upload_id is a UUID v4 chosen on the device. Retries with the same ID and the same
 * content replay the original acknowledgement. Different content under the same ID is refused.
 * Authorization and integrity are always re-checked on the server; the browser never signs evidence.
 */

require_once __DIR__ . '/result_submissions.php';
require_once __DIR__ . '/result_sheets.php';

const SHEET_UPLOAD_API_UUID = SUBMISSION_UUID_PATTERN;
const SHEET_UPLOAD_NOTICE = 'Independent monitoring result sheet (provisional). Server receipt does not mean the sheet has been reviewed, approved, or confirmed as genuine.';

function sheet_upload_api_schema_ready(): bool
{
    try {
        db()->query('SELECT client_upload_id, result_sheet_upload_id FROM result_sheet_client_uploads LIMIT 0');

        return sheet_schema_ready() && sheet_signing_available();
    } catch (Throwable $exception) {
        return false;
    }
}

/**
 * @return array{id: int, client_upload_id: string, user_id: int, polling_unit_id: int, content_sha256: string, upload_kind: string, upload_reason: string, original_name: string, mime: string, byte_size: int, result_sheet_upload_id: int, received_at_utc: string}|null
 */
function sheet_upload_api_find(string $clientUploadId): ?array
{
    $statement = db()->prepare(
        'SELECT id, client_upload_id, user_id, polling_unit_id, content_sha256, upload_kind, upload_reason,
                original_name, mime, byte_size, result_sheet_upload_id, received_at_utc
         FROM result_sheet_client_uploads
         WHERE client_upload_id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $clientUploadId]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

/**
 * @param array{id: int, role: string} $user
 * @return array{ok: bool, code: string, message: string, unit?: array<string, mixed>}
 */
function sheet_upload_api_resolve_unit(array $user, int $unitId): array
{
    if ($unitId < 1) {
        return ['ok' => false, 'code' => 'bad_request', 'message' => 'A polling unit is required.'];
    }
    $unit = result_unit($unitId);
    if (!is_array($unit)) {
        return ['ok' => false, 'code' => 'unit_not_assigned', 'message' => 'That polling unit is not assigned to your account.'];
    }
    if ((int) ($unit['is_active'] ?? 0) !== 1) {
        return ['ok' => false, 'code' => 'unit_inactive', 'message' => 'That polling unit is inactive. The sheet stays queued until the unit is active again.'];
    }
    if ((int) ($unit['agent_user_id'] ?? 0) !== (int) $user['id']) {
        return ['ok' => false, 'code' => 'unit_not_assigned', 'message' => 'That polling unit is not assigned to your account.'];
    }
    if (!sheet_can_upload($user, $unit)) {
        return ['ok' => false, 'code' => 'unit_not_assigned', 'message' => 'You are not allowed to upload a result sheet for that polling unit.'];
    }

    return ['ok' => true, 'code' => '', 'message' => '', 'unit' => $unit];
}

/**
 * @return array{ok: true, json: array<string, mixed>, status: int}|array{ok: false, status: int, code: string, message: string, extra?: array<string, mixed>}
 */
function sheet_upload_api_process(array $user, array $fields, array $file): array
{
    $clientId = strtolower(trim((string) ($fields['client_upload_id'] ?? '')));
    if ($clientId === '' || preg_match(SHEET_UPLOAD_API_UUID, $clientId) !== 1) {
        return ['ok' => false, 'status' => 400, 'code' => 'bad_request', 'message' => 'A valid client upload ID is required.'];
    }
    $unitId = filter_var($fields['polling_unit_id'] ?? '', FILTER_VALIDATE_INT);
    $unitId = is_int($unitId) ? $unitId : 0;
    $kind = trim((string) ($fields['upload_kind'] ?? ''));
    $reason = trim(str_replace(["\r", "\n"], ' ', (string) ($fields['upload_reason'] ?? '')));
    $unitCheck = sheet_upload_api_resolve_unit($user, $unitId);
    if (!$unitCheck['ok']) {
        return [
            'ok' => false,
            'status' => $unitCheck['code'] === 'unit_inactive' ? 409 : 403,
            'code' => $unitCheck['code'],
            'message' => $unitCheck['message'],
        ];
    }

    $existing = sheet_upload_api_find($clientId);
    $tmp = (string) ($file['tmp_name'] ?? '');
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE || $tmp === '' || !is_uploaded_file($tmp)) {
        if (is_array($existing)) {
            return sheet_upload_api_replay($existing, true);
        }

        return ['ok' => false, 'status' => 400, 'code' => 'file_missing', 'message' => 'Choose the result sheet photo or PDF.'];
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'status' => 413, 'code' => 'file_too_large', 'message' => 'That file is larger than the upload limit.'];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'status' => 400, 'code' => 'file_invalid', 'message' => 'The result sheet could not be read.'];
    }
    $bytes = file_get_contents($tmp);
    $max = sheet_max_bytes();
    if (!is_string($bytes) || $bytes === '' || strlen($bytes) > $max) {
        return [
            'ok' => false,
            'status' => 413,
            'code' => 'file_too_large',
            'message' => 'The result sheet must be a photo or PDF no larger than ' . (int) floor($max / 1048576) . ' MB.',
        ];
    }
    $mime = sheet_validate_bytes($bytes);
    if ($mime === null) {
        return ['ok' => false, 'status' => 422, 'code' => 'file_type', 'message' => 'Upload a JPEG, PNG, WebP, or PDF result sheet.'];
    }
    $sha256 = hash('sha256', $bytes);
    $original = sheet_original_name((string) ($file['name'] ?? 'result-sheet'));

    if (is_array($existing)) {
        $same = (string) $existing['content_sha256'] === $sha256
            && (int) $existing['polling_unit_id'] === $unitId
            && (int) $existing['user_id'] === (int) $user['id']
            && (string) $existing['mime'] === $mime
            && (int) $existing['byte_size'] === strlen($bytes);
        if ($kind !== '' && (string) $existing['upload_kind'] !== $kind) {
            $same = false;
        }
        if ($reason !== '' && (string) $existing['upload_reason'] !== $reason) {
            $same = false;
        }
        if (!$same) {
            return [
                'ok' => false,
                'status' => 409,
                'code' => 'upload_id_reused',
                'message' => 'That upload ID was already used with different file content or details. Keep the original queued sheet or queue a new upload.',
            ];
        }

        return sheet_upload_api_replay($existing, true);
    }

    // First version for the unit: kind is forced to initial by sheet_store_upload.
    $hasVersions = sheet_versions_for_unit($unitId) !== [];
    if ($hasVersions) {
        if (!isset(SHEET_UPLOAD_KINDS[$kind])) {
            return ['ok' => false, 'status' => 422, 'code' => 'validation_failed', 'message' => 'Choose whether this upload replaces or supplements the earlier version.'];
        }
        if (strlen($reason) < 5 || strlen($reason) > 255) {
            return ['ok' => false, 'status' => 422, 'code' => 'validation_failed', 'message' => 'Explain in 5 to 255 characters why this version is being added.'];
        }
    } else {
        $kind = 'initial';
        $reason = $reason === '' ? 'First upload for this polling unit.' : $reason;
    }

    $stored = sheet_store_upload($unitId, $file, (int) $user['id'], $kind === 'initial' ? '' : $kind, $reason);
    if (!$stored['ok']) {
        $code = str_contains($stored['error'], 'larger') ? 'file_too_large'
            : (str_contains($stored['error'], 'JPEG') ? 'file_type' : 'upload_failed');
        $status = $code === 'file_too_large' ? 413 : ($code === 'file_type' ? 422 : 400);

        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $stored['error']];
    }

    $receivedAt = submission_utc_now();
    try {
        $insert = db()->prepare(
            'INSERT INTO result_sheet_client_uploads
                (client_upload_id, user_id, polling_unit_id, content_sha256, upload_kind, upload_reason,
                 original_name, mime, byte_size, result_sheet_upload_id, received_at_utc)
             VALUES
                (:client_upload_id, :user_id, :polling_unit_id, :content_sha256, :upload_kind, :upload_reason,
                 :original_name, :mime, :byte_size, :result_sheet_upload_id, :received_at_utc)'
        );
        $insert->execute([
            'client_upload_id' => $clientId,
            'user_id' => (int) $user['id'],
            'polling_unit_id' => $unitId,
            'content_sha256' => $sha256,
            'upload_kind' => $kind === '' ? 'initial' : $kind,
            'upload_reason' => $reason,
            'original_name' => $original,
            'mime' => $mime,
            'byte_size' => strlen($bytes),
            'result_sheet_upload_id' => (int) $stored['id'],
            'received_at_utc' => $receivedAt,
        ]);
    } catch (PDOException $exception) {
        if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
            $race = sheet_upload_api_find($clientId);
            if (is_array($race)) {
                return sheet_upload_api_replay($race, true);
            }
        }
        throw $exception;
    }
    result_audit(
        (int) $user['id'],
        'offline_sheet_upload',
        (int) $stored['id'],
        'Received device sheet upload ' . $clientId . ' for polling unit ' . $unitId . '.'
    );
    $row = sheet_upload_api_find($clientId);
    if (!is_array($row)) {
        return ['ok' => false, 'status' => 500, 'code' => 'server_error', 'message' => 'The sheet was stored but the receipt could not be read.'];
    }

    return sheet_upload_api_replay($row, false);
}

/**
 * @param array<string, mixed> $row
 * @return array{ok: true, json: array<string, mixed>, status: int}
 */
function sheet_upload_api_replay(array $row, bool $replayed): array
{
    $received = (string) $row['received_at_utc'];
    if (!str_contains($received, 'T')) {
        $received = str_replace(' ', 'T', $received) . 'Z';
    } elseif (!str_ends_with($received, 'Z')) {
        $received .= 'Z';
    }

    return [
        'ok' => true,
        'status' => $replayed ? 200 : 201,
        'json' => [
            'ok' => true,
            'code' => 'received',
            'message' => $replayed
                ? 'This result sheet was already received. The original acknowledgement is returned.'
                : 'Result sheet received by the server and stored for review.',
            'client_upload_id' => (string) $row['client_upload_id'],
            'upload_id' => (int) $row['result_sheet_upload_id'],
            'polling_unit_id' => (int) $row['polling_unit_id'],
            'received_at' => $received,
            'replayed' => $replayed,
            'sha256' => (string) $row['content_sha256'],
            'notice' => SHEET_UPLOAD_NOTICE,
            'review_status' => 'pending',
        ],
    ];
}
