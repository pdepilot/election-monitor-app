<?php
declare(strict_types=1);

/*
 * Integrity incident management. The incident row is a convenience index; its authority is the
 * signed event trail. Every action appends a signed event and moves the status forward only.
 * Nothing here touches uploads, files, fingerprints, or record signatures, so closing an
 * incident never makes a failing sheet deliverable.
 */

require_once __DIR__ . '/result_sheets.php';

const SHEET_INCIDENT_STATES = ['open', 'acknowledged', 'investigating', 'closed'];
const SHEET_INCIDENT_ACTIONS = [
    'acknowledge' => ['to' => 'acknowledged', 'from' => ['open'], 'min' => 5],
    'investigate' => ['to' => 'investigating', 'from' => ['open', 'acknowledged'], 'min' => 5],
    'close' => ['to' => 'closed', 'from' => ['open', 'acknowledged', 'investigating'], 'min' => 10],
];
const SHEET_INCIDENT_REASON_MAX = 140;
const SHEET_INCIDENT_EVENT_TYPES = ['integrity_failure', 'incident_acknowledged', 'incident_investigating', 'incident_closed'];

function sheet_incident_type_label(string $type): string
{
    return match ($type) {
        'content_mismatch' => 'File content does not match its fingerprint',
        'metadata_mismatch' => 'Record does not match its signature',
        'missing_file' => 'Stored file is missing',
        'key_unavailable' => 'Signing key unavailable during check',
        default => $type,
    };
}

/**
 * @return array<string, mixed>|null
 */
function sheet_incident_find(int $id): ?array
{
    $statement = db()->prepare(
        'SELECT i.*, s.polling_unit_id, s.version_no, s.upload_kind, s.sha256 AS recorded_sha256,
                pu.unit_code, pu.name AS unit_name, w.name AS ward_name,
                d.full_name AS detected_by_name, r.full_name AS resolved_by_name
         FROM result_sheet_incidents i
         INNER JOIN result_sheet_uploads s ON s.id = i.upload_id
         INNER JOIN polling_units pu ON pu.id = s.polling_unit_id
         INNER JOIN wards w ON w.id = pu.ward_id
         LEFT JOIN users d ON d.id = i.detected_by
         LEFT JOIN users r ON r.id = i.resolved_by
         WHERE i.id = :id
         LIMIT 1'
    );
    $statement->bindValue(':id', $id, PDO::PARAM_INT);
    $statement->execute();
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

/**
 * @return list<array<string, mixed>>
 */
function sheet_incident_list(string $filter, int $limit = 200): array
{
    $where = match ($filter) {
        'closed' => "WHERE i.status = 'closed'",
        'all' => '',
        default => "WHERE i.status <> 'closed'",
    };
    $limit = max(1, min(500, $limit));
    $statement = db()->query(
        'SELECT i.*, s.polling_unit_id, s.version_no, pu.unit_code, pu.name AS unit_name
         FROM result_sheet_incidents i
         INNER JOIN result_sheet_uploads s ON s.id = i.upload_id
         INNER JOIN polling_units pu ON pu.id = s.polling_unit_id
         ' . $where . '
         ORDER BY i.id DESC
         LIMIT ' . $limit
    );

    return $statement->fetchAll();
}

/**
 * Signed events that belong to one incident, oldest first.
 *
 * @param array<string, mixed> $incident
 * @return list<array<string, mixed>>
 */
function sheet_incident_events(array $incident): array
{
    $placeholders = implode(',', array_map(static fn (int $i): string => ':t' . $i, array_keys(SHEET_INCIDENT_EVENT_TYPES)));
    $statement = db()->prepare(
        'SELECT e.*, u.full_name AS actor_name
         FROM result_sheet_events e
         LEFT JOIN users u ON u.id = e.actor_user_id
         WHERE e.upload_id = :upload AND e.event_type IN (' . $placeholders . ') AND e.detail LIKE :prefix
         ORDER BY e.id ASC'
    );
    $statement->bindValue(':upload', (int) $incident['upload_id'], PDO::PARAM_INT);
    foreach (SHEET_INCIDENT_EVENT_TYPES as $index => $type) {
        $statement->bindValue(':t' . $index, $type, PDO::PARAM_STR);
    }
    $statement->bindValue(':prefix', 'Incident #' . (int) $incident['id'] . ' %', PDO::PARAM_STR);
    $statement->execute();

    return $statement->fetchAll();
}

/**
 * Establishes what the signed trail says about an incident and whether the database row agrees.
 * Only 'authenticated' means every relevant event verified with an available key.
 *
 * @param array<string, mixed> $incident
 * @param list<array<string, mixed>> $events
 * @return array{state: string, label: string, message: string, derived: string, actionable: bool, signatures: array<int, string>}
 */
function sheet_incident_auth(array $incident, array $events): array
{
    $signatures = [];
    foreach ($events as $event) {
        $signatures[(int) $event['id']] = sheet_event_signature($event);
    }
    $result = static fn (string $state, string $label, string $message, string $derived, bool $actionable): array => [
        'state' => $state, 'label' => $label, 'message' => $message, 'derived' => $derived, 'actionable' => $actionable, 'signatures' => $signatures,
    ];
    if (!sheet_signing_available()) {
        return $result('unavailable', 'CANNOT AUTHENTICATE: KEY UNAVAILABLE', 'The signing key is missing or invalid, so this incident and its history cannot be authenticated. Actions are disabled until the key is restored.', '', false);
    }

    $expectedOpening = sheet_incident_opening_detail((int) $incident['id'], (string) $incident['incident_type'], $incident['observed_sha256'] !== null ? (string) $incident['observed_sha256'] : null);
    $opening = null;
    foreach ($events as $event) {
        if ((string) $event['event_type'] === 'integrity_failure' && (string) $event['detail'] === $expectedOpening) {
            $opening = $event;
            break;
        }
    }
    if ($opening === null) {
        return $result('no_origin', 'NOT AUTHENTICATED: NO MATCHING OPENING RECORD', 'No event in the signed trail opened this incident with these details. The row may have been inserted or edited directly in the database.', '', false);
    }
    if (in_array('invalid', $signatures, true)) {
        return $result('invalid', 'SIGNATURE FAILED', 'At least one event in this incident history does not match its signature. Treat the history as tampered.', '', false);
    }
    if (in_array('unavailable', $signatures, true)) {
        return $result('unavailable', 'CANNOT AUTHENTICATE: KEY UNAVAILABLE', 'An event in this history was signed with a key that is no longer in the key file.', '', false);
    }

    $derived = 'open';
    foreach ($events as $event) {
        if ((string) $event['event_type'] === 'integrity_failure') {
            continue;
        }
        if (!preg_match('/\(([a-z]+) -> ([a-z]+)\)/', (string) $event['detail'], $match)
            || $match[1] !== $derived
            || 'incident_' . $match[2] !== (string) $event['event_type']) {
            return $result('mismatch', 'HISTORY INCONSISTENT', 'The signed history contains a status change that does not follow from the previous status.', $derived, false);
        }
        $derived = $match[2];
    }
    if ($derived !== (string) $incident['status']) {
        return $result('mismatch', 'STATUS DOES NOT MATCH SIGNED HISTORY', 'The status stored in the database differs from the status established by the signed history ("' . $derived . '").', $derived, false);
    }
    if ($signatures[(int) $opening['id']] === 'unsigned') {
        return $result('unsigned', 'OPENING RECORD UNSIGNED', 'This incident was opened while the signing key was unavailable. Its origin cannot be authenticated; later actions are signed.', $derived, true);
    }

    return $result('authenticated', 'AUTHENTICATED', '', $derived, true);
}

function sheet_incident_clean_reason(string $reason): string
{
    $reason = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $reason) ?? '';

    return trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
}

/**
 * Applies one action. Status only moves forward; the upload, its file, and its fingerprint are
 * never touched, and the integrity status at the time of the action is recorded in the event.
 *
 * @param array{id: int, role: string} $user
 * @return array{ok: bool, message: string, code: int}
 */
function sheet_incident_act(array $user, int $incidentId, string $action, string $reason): array
{
    $fail = static fn (string $message, int $code = 422): array => ['ok' => false, 'message' => $message, 'code' => $code];
    if (!sheet_is_reviewer($user)) {
        return $fail('Your account cannot manage integrity incidents.', 403);
    }
    if (!isset(SHEET_INCIDENT_ACTIONS[$action])) {
        return $fail('That action is not available.');
    }
    $rule = SHEET_INCIDENT_ACTIONS[$action];
    $reason = sheet_incident_clean_reason($reason);
    $length = function_exists('mb_strlen') ? mb_strlen($reason) : strlen($reason);
    if ($length < $rule['min'] || $length > SHEET_INCIDENT_REASON_MAX) {
        return $fail($action === 'close'
            ? 'Closing an incident needs an explanation of ' . $rule['min'] . ' to ' . SHEET_INCIDENT_REASON_MAX . ' characters.'
            : 'Give a reason of ' . $rule['min'] . ' to ' . SHEET_INCIDENT_REASON_MAX . ' characters.');
    }
    $incident = sheet_incident_find($incidentId);
    if ($incident === null) {
        return $fail('That incident was not found.', 404);
    }
    $auth = sheet_incident_auth($incident, sheet_incident_events($incident));
    if (!$auth['actionable']) {
        return $fail('This incident cannot be changed: ' . $auth['label'] . '.', 409);
    }
    $from = (string) $incident['status'];
    if (!in_array($from, $rule['from'], true)) {
        return $fail('An incident that is ' . $from . ' cannot be moved to ' . $rule['to'] . '.', 409);
    }
    $upload = sheet_find((int) $incident['upload_id']);
    $integrity = $upload !== null ? sheet_status_label(sheet_verify($upload, (int) $user['id'])['status']) : 'UPLOAD NOT FOUND';
    $detail = 'Incident #' . $incidentId . ' ' . $rule['to'] . ': (' . $from . ' -> ' . $rule['to'] . '); integrity now ' . $integrity . '. Reason: ' . $reason;
    if ((function_exists('mb_strlen') ? mb_strlen($detail) : strlen($detail)) > 255) {
        return $fail('That reason is too long to record in full. Shorten it.');
    }

    $changed = sheet_with_chain_lock(static function () use ($incident, $incidentId, $rule, $from, $user, $reason, $detail): bool {
        if (!sheet_signing_available()) {
            return false;
        }
        $sql = $rule['to'] === 'closed'
            ? 'UPDATE result_sheet_incidents SET status = :to, resolved_by = :by, resolved_at_utc = :at, resolution_note = :note WHERE id = :id AND status = :from'
            : 'UPDATE result_sheet_incidents SET status = :to WHERE id = :id AND status = :from';
        $update = db()->prepare($sql);
        $update->bindValue(':to', $rule['to'], PDO::PARAM_STR);
        $update->bindValue(':id', $incidentId, PDO::PARAM_INT);
        $update->bindValue(':from', $from, PDO::PARAM_STR);
        if ($rule['to'] === 'closed') {
            $update->bindValue(':by', (int) $user['id'], PDO::PARAM_INT);
            $update->bindValue(':at', sheet_utc_now(), PDO::PARAM_STR);
            $update->bindValue(':note', $reason, PDO::PARAM_STR);
        }
        $update->execute();
        if ($update->rowCount() !== 1) {
            return false;
        }
        sheet_event('incident_' . $rule['to'], [
            'upload_id' => (int) $incident['upload_id'],
            'polling_unit_id' => (int) $incident['polling_unit_id'],
            'actor_user_id' => (int) $user['id'],
            'detail' => $detail,
        ]);

        return true;
    });
    if (!$changed) {
        return $fail('The incident changed or the signing key became unavailable. Reload and try again.', 409);
    }

    return ['ok' => true, 'message' => 'Incident ' . $rule['to'] . '. The result sheet integrity status is unchanged: ' . $integrity . '.', 'code' => 200];
}
