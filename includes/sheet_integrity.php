<?php
declare(strict_types=1);

/*
 * Result-sheet integrity: SHA-256 fingerprints of the exact uploaded bytes,
 * HMAC-SHA-256 over canonical metadata, and a chained, signed event trail.
 *
 * SHA-256 is a fingerprint, not encryption, and it says nothing about whether a photo was
 * genuine before upload. The HMAC detects database-only edits; it does not help against
 * someone who controls both the application server and the key file.
 */

require_once dirname(__DIR__) . '/config/database.php';

const SHEET_STATUS_VERIFIED = 'verified';
const SHEET_STATUS_FAILED = 'failed';
const SHEET_STATUS_MISSING = 'missing';
const SHEET_STATUS_LEGACY = 'legacy';
const SHEET_STATUS_UNVERIFIABLE = 'unverifiable';
const SHEET_CHAIN_GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';
const SHEET_STORED_NAME_PATTERN = '/\A[a-f0-9]{32}\.(jpg|png|webp|pdf)\z/';

function sheet_status_label(string $status): string
{
    return match ($status) {
        SHEET_STATUS_VERIFIED => 'VERIFIED',
        SHEET_STATUS_FAILED => 'INTEGRITY CHECK FAILED',
        SHEET_STATUS_MISSING => 'MISSING FILE',
        SHEET_STATUS_LEGACY => 'UNVERIFIED LEGACY',
        default => 'CANNOT VERIFY: KEY UNAVAILABLE',
    };
}

function sheet_status_badge(string $status): string
{
    return match ($status) {
        SHEET_STATUS_VERIFIED => 'cms-badge--ok',
        SHEET_STATUS_LEGACY => 'cms-badge--warn',
        default => 'cms-badge--off',
    };
}

function sheet_status_warning(string $status): string
{
    return match ($status) {
        SHEET_STATUS_VERIFIED => '',
        SHEET_STATUS_FAILED => 'The stored file or its record no longer matches what was recorded at upload. The file is withheld and an incident was logged.',
        SHEET_STATUS_MISSING => 'The stored file cannot be found. An incident was logged.',
        SHEET_STATUS_LEGACY => 'This sheet was uploaded before integrity protection existed. Its history before protection cannot be established.',
        default => 'The integrity key is unavailable, so this record cannot be authenticated. The file is withheld.',
    };
}

/** Status values that allow normal delivery of the bytes. */
function sheet_status_deliverable(string $status): bool
{
    return $status === SHEET_STATUS_VERIFIED || $status === SHEET_STATUS_LEGACY;
}

function sheet_utc_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function sheet_private_dir(): string
{
    return app_config()['private_dir'];
}

function sheet_private_storage_dir(bool $create): string
{
    $dir = sheet_private_dir() . DIRECTORY_SEPARATOR . 'result-sheets';
    if ($create && !is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Private result-sheet storage is not available.');
    }

    return $dir;
}

function sheet_legacy_storage_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'result-sheets';
}

/**
 * @param array<string, mixed> $row
 */
function sheet_stored_path(array $row): ?string
{
    $name = (string) ($row['stored_name'] ?? '');
    if (!preg_match(SHEET_STORED_NAME_PATTERN, $name)) {
        return null;
    }
    $dir = (string) ($row['storage_area'] ?? 'legacy') === 'private'
        ? sheet_private_storage_dir(false)
        : sheet_legacy_storage_dir();

    return $dir . DIRECTORY_SEPARATOR . $name;
}

/* ---------- Keys ---------- */

/**
 * Key file format (JSON, kept outside the web root, the project, and the database):
 * {"current": "k20261015a", "keys": {"k20261015a": "<base64 of >= 32 random bytes>"}}
 * Older keys stay in the file after rotation so older records still verify.
 *
 * @return array{current: ?string, keys: array<string, string>, error: string}
 */
function sheet_keys(bool $reload = false): array
{
    static $cache = null;
    if ($cache !== null && !$reload) {
        return $cache;
    }
    $cache = ['current' => null, 'keys' => [], 'error' => ''];
    $file = app_config()['sheet_key_file'];
    if (!is_file($file) || !is_readable($file)) {
        $cache['error'] = 'missing';
        error_log('Result-sheet integrity key file is missing or unreadable.');

        return $cache;
    }
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data) || !is_array($data['keys'] ?? null) || !is_string($data['current'] ?? null)) {
        $cache['error'] = 'invalid';
        error_log('Result-sheet integrity key file is not valid.');

        return $cache;
    }
    foreach ($data['keys'] as $id => $encoded) {
        if (!is_string($id) || !preg_match('/\A[a-z0-9-]{1,32}\z/', $id) || !is_string($encoded)) {
            continue;
        }
        $raw = base64_decode($encoded, true);
        if (is_string($raw) && strlen($raw) >= 32) {
            $cache['keys'][$id] = $raw;
        }
    }
    if (!isset($cache['keys'][$data['current']])) {
        $cache['keys'] = [];
        $cache['error'] = 'invalid';
        error_log('Result-sheet integrity key file has no usable current key.');

        return $cache;
    }
    $cache['current'] = $data['current'];

    return $cache;
}

function sheet_signing_available(): bool
{
    return sheet_keys()['current'] !== null;
}

/**
 * @param array<string, mixed> $row
 */
function sheet_canonical(array $row, string $keyId): string
{
    $fields = [
        $keyId,
        (string) $row['id'],
        (string) $row['polling_unit_id'],
        (string) ($row['version_no'] ?? ''),
        (string) ($row['supersedes_id'] ?? ''),
        (string) ($row['upload_kind'] ?? ''),
        (string) ($row['upload_reason'] ?? ''),
        (string) ($row['storage_area'] ?? ''),
        (string) $row['stored_name'],
        (string) $row['original_name'],
        (string) $row['mime'],
        (string) $row['byte_size'],
        (string) ($row['sha256'] ?? ''),
        (string) ($row['uploaded_by'] ?? ''),
        (string) ($row['uploaded_at_utc'] ?? ''),
    ];

    return 'em-sheet-v1|' . json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/**
 * @param array<string, mixed> $row
 * @return array{hmac: string, key_id: string}|null
 */
function sheet_sign_row(array $row): ?array
{
    $keys = sheet_keys();
    if ($keys['current'] === null) {
        return null;
    }
    $keyId = $keys['current'];

    return ['hmac' => hash_hmac('sha256', sheet_canonical($row, $keyId), $keys['keys'][$keyId]), 'key_id' => $keyId];
}

/**
 * @param array<string, mixed> $row
 * @return 'valid'|'invalid'|'unavailable'
 */
function sheet_row_signature(array $row): string
{
    $keyId = (string) ($row['hmac_key_id'] ?? '');
    $stored = (string) ($row['meta_hmac'] ?? '');
    if ($keyId === '' || !preg_match('/\A[a-f0-9]{64}\z/', $stored)) {
        return 'invalid';
    }
    $keys = sheet_keys();
    if (!isset($keys['keys'][$keyId])) {
        return 'unavailable';
    }
    $expected = hash_hmac('sha256', sheet_canonical($row, $keyId), $keys['keys'][$keyId]);

    return hash_equals($expected, $stored) ? 'valid' : 'invalid';
}

/* ---------- Event chain ---------- */

/**
 * Runs $work inside a transaction while holding the event-chain lock, so events are appended in order.
 *
 * @template T
 * @param callable(): T $work
 * @return T
 */
function sheet_with_chain_lock(callable $work): mixed
{
    static $depth = 0;
    if ($depth > 0) {
        return $work();
    }
    $pdo = db();
    $lock = $pdo->query("SELECT GET_LOCK('em_sheet_event_chain', 10)")->fetchColumn();
    if ((int) $lock !== 1) {
        throw new RuntimeException('The integrity event log is busy. Try again.');
    }
    $depth++;
    try {
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $result;
    } finally {
        $depth--;
        $pdo->query("SELECT RELEASE_LOCK('em_sheet_event_chain')")->fetchColumn();
    }
}

/**
 * @param array<string, mixed> $event
 */
function sheet_event_canonical(array $event, string $keyId): string
{
    $fields = [
        $keyId,
        (string) $event['id'],
        (string) ($event['upload_id'] ?? ''),
        (string) ($event['polling_unit_id'] ?? ''),
        (string) ($event['actor_user_id'] ?? ''),
        (string) $event['event_type'],
        (string) ($event['previous_upload_id'] ?? ''),
        (string) ($event['new_upload_id'] ?? ''),
        (string) $event['detail'],
        (string) $event['occurred_at_utc'],
        (string) ($event['prev_chain'] ?? ''),
    ];

    return 'em-sheet-event-v1|' . json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/**
 * Appends an event. Events are still written when no key is available, but they stay unsigned
 * and are reported as such; they are never treated as authenticated.
 *
 * @param array{upload_id?: ?int, polling_unit_id?: ?int, actor_user_id?: ?int, previous_upload_id?: ?int, new_upload_id?: ?int, detail?: string} $data
 */
function sheet_event(string $type, array $data): int
{
    return sheet_with_chain_lock(static function () use ($type, $data): int {
        $pdo = db();
        $last = $pdo->query('SELECT event_hmac FROM result_sheet_events ORDER BY id DESC LIMIT 1')->fetchColumn();
        $prev = is_string($last) && $last !== '' ? $last : SHEET_CHAIN_GENESIS;
        $detail = str_replace(["\r", "\n"], ' ', (string) ($data['detail'] ?? ''));
        $detail = function_exists('mb_substr') ? mb_substr($detail, 0, 255) : substr($detail, 0, 255);
        $event = [
            'upload_id' => $data['upload_id'] ?? null,
            'polling_unit_id' => $data['polling_unit_id'] ?? null,
            'actor_user_id' => isset($data['actor_user_id']) && $data['actor_user_id'] > 0 ? $data['actor_user_id'] : null,
            'event_type' => $type,
            'previous_upload_id' => $data['previous_upload_id'] ?? null,
            'new_upload_id' => $data['new_upload_id'] ?? null,
            'detail' => $detail,
            'occurred_at_utc' => sheet_utc_now(),
            'prev_chain' => $prev,
        ];
        $insert = $pdo->prepare(
            'INSERT INTO result_sheet_events
                (upload_id, polling_unit_id, actor_user_id, event_type, previous_upload_id, new_upload_id, detail, occurred_at_utc, prev_chain)
             VALUES (:upload, :unit, :actor, :type, :prev_upload, :new_upload, :detail, :at, :prev_chain)'
        );
        foreach ([':upload' => 'upload_id', ':unit' => 'polling_unit_id', ':actor' => 'actor_user_id', ':prev_upload' => 'previous_upload_id', ':new_upload' => 'new_upload_id'] as $param => $key) {
            $insert->bindValue($param, $event[$key], $event[$key] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        }
        $insert->bindValue(':type', $type, PDO::PARAM_STR);
        $insert->bindValue(':detail', $detail, PDO::PARAM_STR);
        $insert->bindValue(':at', $event['occurred_at_utc'], PDO::PARAM_STR);
        $insert->bindValue(':prev_chain', $prev, PDO::PARAM_STR);
        $insert->execute();
        $event['id'] = (int) $pdo->lastInsertId();

        $keys = sheet_keys();
        if ($keys['current'] !== null) {
            $keyId = $keys['current'];
            $sign = $pdo->prepare('UPDATE result_sheet_events SET event_hmac = :hmac, hmac_key_id = :key_id WHERE id = :id');
            $sign->execute([
                'hmac' => hash_hmac('sha256', sheet_event_canonical($event, $keyId), $keys['keys'][$keyId]),
                'key_id' => $keyId,
                'id' => $event['id'],
            ]);
        }

        return $event['id'];
    });
}

/**
 * @param array<string, mixed> $event
 * @return 'valid'|'invalid'|'unsigned'|'unavailable'
 */
function sheet_event_signature(array $event): string
{
    $stored = (string) ($event['event_hmac'] ?? '');
    $keyId = (string) ($event['hmac_key_id'] ?? '');
    if ($stored === '' && $keyId === '') {
        return 'unsigned';
    }
    $keys = sheet_keys();
    if (!isset($keys['keys'][$keyId])) {
        return 'unavailable';
    }
    $expected = hash_hmac('sha256', sheet_event_canonical($event, $keyId), $keys['keys'][$keyId]);

    return hash_equals($expected, $stored) ? 'valid' : 'invalid';
}

/**
 * @return list<array<string, mixed>>
 */
function sheet_events_for_unit(int $unitId, int $limit = 100): array
{
    $limit = max(1, min(500, $limit));
    $statement = db()->prepare(
        'SELECT e.*, u.full_name AS actor_name
         FROM result_sheet_events e
         LEFT JOIN users u ON u.id = e.actor_user_id
         WHERE e.polling_unit_id = :unit
         ORDER BY e.id DESC
         LIMIT ' . $limit
    );
    $statement->bindValue(':unit', $unitId, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

/**
 * Walks the whole event trail in order and reports rows whose signature or chain link fails.
 *
 * @return array{total: int, problems: list<string>}
 */
function sheet_verify_event_chain(): array
{
    $problems = [];
    $total = 0;
    $previous = SHEET_CHAIN_GENESIS;
    $statement = db()->query('SELECT * FROM result_sheet_events ORDER BY id ASC');
    foreach ($statement as $event) {
        $total++;
        $id = (int) $event['id'];
        if (!hash_equals($previous, (string) ($event['prev_chain'] ?? ''))) {
            $problems[] = "event {$id}: chain link does not match the previous event";
        }
        $signature = sheet_event_signature($event);
        if ($signature !== 'valid') {
            $problems[] = "event {$id}: signature {$signature}";
        }
        $previous = (string) ($event['event_hmac'] ?? '') !== '' ? (string) $event['event_hmac'] : SHEET_CHAIN_GENESIS;
    }

    return ['total' => $total, 'problems' => $problems];
}

/* ---------- Incidents ---------- */

/**
 * The exact text of the signed event that opens an incident. It binds the incident row
 * (id, type, observed hash) to the authenticated event trail.
 */
function sheet_incident_opening_detail(int $incidentId, string $type, ?string $observed): string
{
    return 'Incident #' . $incidentId . ' opened: ' . $type
        . ($observed !== null ? '; observed SHA-256 ' . $observed : '') . '.';
}

/**
 * Opens an incident unless the same one is still unresolved (any status except closed).
 * Incidents are never closed automatically. After a closure, a problem that is still present
 * opens a new incident the next time it is detected.
 *
 * @param array<string, mixed> $row
 */
function sheet_record_incident(array $row, string $type, ?string $observed, ?int $actorId): void
{
    try {
        sheet_with_chain_lock(static function () use ($row, $type, $observed, $actorId): void {
            $find = db()->prepare(
                "SELECT id FROM result_sheet_incidents
                 WHERE upload_id = :upload AND incident_type = :type AND status <> 'closed'
                   AND observed_sha256 <=> :observed
                 LIMIT 1"
            );
            $find->bindValue(':upload', (int) $row['id'], PDO::PARAM_INT);
            $find->bindValue(':type', $type, PDO::PARAM_STR);
            $find->bindValue(':observed', $observed, $observed === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $find->execute();
            if ($find->fetchColumn() !== false) {
                return;
            }
            $insert = db()->prepare(
                'INSERT INTO result_sheet_incidents (upload_id, incident_type, observed_sha256, detected_by, detected_at_utc)
                 VALUES (:upload, :type, :observed, :actor, :at)'
            );
            $insert->bindValue(':upload', (int) $row['id'], PDO::PARAM_INT);
            $insert->bindValue(':type', $type, PDO::PARAM_STR);
            $insert->bindValue(':observed', $observed, $observed === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insert->bindValue(':actor', $actorId !== null && $actorId > 0 ? $actorId : null, $actorId !== null && $actorId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $insert->bindValue(':at', sheet_utc_now(), PDO::PARAM_STR);
            $insert->execute();
            $incidentId = (int) db()->lastInsertId();
            sheet_event('integrity_failure', [
                'upload_id' => (int) $row['id'],
                'polling_unit_id' => (int) $row['polling_unit_id'],
                'actor_user_id' => $actorId,
                'detail' => sheet_incident_opening_detail($incidentId, $type, $observed),
            ]);
        });
    } catch (Throwable $exception) {
        error_log('Integrity incident could not be recorded: ' . $exception->getMessage());
    }
}

/**
 * @return list<array<string, mixed>>
 */
function sheet_open_incidents(int $uploadId): array
{
    $statement = db()->prepare(
        "SELECT id, incident_type, observed_sha256, detected_at_utc, status
         FROM result_sheet_incidents
         WHERE upload_id = :upload AND status <> 'closed'
         ORDER BY id DESC"
    );
    $statement->bindValue(':upload', $uploadId, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

/* ---------- Verification ---------- */

/**
 * A record is legacy only when it carries none of the protection fields. Clearing just the hash
 * on a protected record is treated as tampering, not as a downgrade to legacy.
 *
 * @param array<string, mixed> $row
 */
function sheet_is_legacy(array $row): bool
{
    return (string) ($row['sha256'] ?? '') === ''
        && (string) ($row['meta_hmac'] ?? '') === ''
        && (string) ($row['hmac_key_id'] ?? '') === ''
        && (string) ($row['uploaded_at_utc'] ?? '') === ''
        && (string) ($row['storage_area'] ?? 'legacy') === 'legacy';
}

/**
 * Verifies one upload record and its stored file. With $withBytes the file is read once and the
 * same bytes that were hashed are returned for delivery, so nothing can change in between.
 *
 * @param array<string, mixed> $row
 * @return array{status: string, reason: string, bytes: ?string, observed: ?string, deliverable: bool}
 */
function sheet_verify(array $row, ?int $actorId, bool $withBytes = false): array
{
    static $cache = [];
    $id = (int) $row['id'];
    if (!$withBytes && isset($cache[$id])) {
        return $cache[$id];
    }
    $result = sheet_verify_uncached($row, $actorId, $withBytes);
    $result['deliverable'] = sheet_status_deliverable($result['status']);
    $cache[$id] = ['status' => $result['status'], 'reason' => $result['reason'], 'bytes' => null, 'observed' => $result['observed'], 'deliverable' => $result['deliverable']];

    return $result;
}

/**
 * @param array<string, mixed> $row
 * @return array{status: string, reason: string, bytes: ?string, observed: ?string}
 */
function sheet_verify_uncached(array $row, ?int $actorId, bool $withBytes): array
{
    $out = static fn (string $status, string $reason, ?string $bytes = null, ?string $observed = null): array
        => ['status' => $status, 'reason' => $reason, 'bytes' => $bytes, 'observed' => $observed];
    $expected = (string) ($row['sha256'] ?? '');
    $legacy = sheet_is_legacy($row);

    if (!$legacy) {
        $signature = sheet_row_signature($row);
        if ($signature === 'unavailable') {
            sheet_record_incident($row, 'key_unavailable', null, $actorId);

            return $out(SHEET_STATUS_UNVERIFIABLE, 'key_unavailable');
        }
        if ($signature !== 'valid') {
            sheet_record_incident($row, 'metadata_mismatch', null, $actorId);

            return $out(SHEET_STATUS_FAILED, 'metadata');
        }
    }

    $path = sheet_stored_path($row);
    if ($path === null || !is_file($path)) {
        sheet_record_incident($row, 'missing_file', null, $actorId);

        return $out(SHEET_STATUS_MISSING, 'missing');
    }
    if ($withBytes) {
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            sheet_record_incident($row, 'missing_file', null, $actorId);

            return $out(SHEET_STATUS_MISSING, 'unreadable');
        }
        $observed = hash('sha256', $bytes);
        $size = strlen($bytes);
    } else {
        $bytes = null;
        $hashed = hash_file('sha256', $path);
        $sizeRead = filesize($path);
        if (!is_string($hashed) || $sizeRead === false) {
            sheet_record_incident($row, 'missing_file', null, $actorId);

            return $out(SHEET_STATUS_MISSING, 'unreadable');
        }
        $observed = $hashed;
        $size = (int) $sizeRead;
    }

    if ($legacy) {
        $baseline = (string) ($row['legacy_baseline_sha256'] ?? '');
        if ($baseline !== '' && !hash_equals($baseline, $observed)) {
            sheet_record_incident($row, 'content_mismatch', $observed, $actorId);

            return $out(SHEET_STATUS_FAILED, 'baseline', null, $observed);
        }

        return $out(SHEET_STATUS_LEGACY, $baseline !== '' ? 'baseline_match' : 'no_baseline', $bytes, $observed);
    }

    if ($size !== (int) $row['byte_size'] || !hash_equals($expected, $observed)) {
        sheet_record_incident($row, 'content_mismatch', $observed, $actorId);

        return $out(SHEET_STATUS_FAILED, 'content', null, $observed);
    }

    return $out(SHEET_STATUS_VERIFIED, 'ok', $bytes, $observed);
}
