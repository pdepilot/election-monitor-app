<?php
declare(strict_types=1);

require_once __DIR__ . '/results.php';
require_once __DIR__ . '/sheet_integrity.php';

const SHEET_READERS = ['super_admin', 'admin'];
const SHEET_UPLOAD_KINDS = ['replacement' => 'Replacement (corrects an earlier version)', 'supplement' => 'Supplement (adds to an earlier version)'];

/**
 * @return array<string, string>
 */
function sheet_mime_map(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
}

function sheet_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        db()->query('SELECT sha256, meta_hmac, version_no FROM result_sheet_uploads LIMIT 1');
        db()->query('SELECT 1 FROM result_sheet_events LIMIT 1');
        db()->query('SELECT 1 FROM result_sheet_incidents LIMIT 1');
        $ready = true;

        return true;
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        $ready = false;

        return false;
    }
}

function sheet_max_bytes(): int
{
    $cap = 8 * 1024 * 1024;
    $limits = [sheet_ini_bytes((string) ini_get('upload_max_filesize')), sheet_ini_bytes((string) ini_get('post_max_size'))];
    foreach ($limits as $limit) {
        if ($limit > 0 && $limit < $cap) {
            $cap = $limit;
        }
    }

    return $cap;
}

function sheet_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $number = (float) $value;

    return (int) match ($unit) {
        'g' => $number * 1073741824,
        'm' => $number * 1048576,
        'k' => $number * 1024,
        default => $number,
    };
}

/**
 * @param array{id: int, role: string} $user
 * @param array<string, mixed> $unit
 */
function sheet_can_upload(array $user, array $unit): bool
{
    if ((int) ($unit['is_active'] ?? 0) !== 1) {
        return false;
    }
    if (in_array($user['role'], SHEET_READERS, true)) {
        return true;
    }

    return $user['role'] === 'polling_agent' && (int) ($unit['agent_user_id'] ?? 0) === (int) $user['id'];
}

/**
 * @param array{id: int, role: string} $user
 * @param array<string, mixed> $upload
 */
function sheet_can_view(array $user, array $upload): bool
{
    if ((int) ($upload['is_active'] ?? 0) !== 1 && !in_array($user['role'], SHEET_READERS, true)) {
        return false;
    }
    if (in_array($user['role'], SHEET_READERS, true)) {
        return true;
    }
    if ($user['role'] !== 'polling_agent') {
        return false;
    }
    $unit = result_unit((int) $upload['polling_unit_id']);

    return is_array($unit)
        && (int) ($unit['is_active'] ?? 0) === 1
        && (int) ($unit['agent_user_id'] ?? 0) === (int) $user['id'];
}

/**
 * @param array{id: int, role: string} $user
 */
function sheet_is_reviewer(array $user): bool
{
    return in_array($user['role'], SHEET_READERS, true);
}

const SHEET_ROW_COLUMNS = 's.id, s.polling_unit_id, s.version_no, s.supersedes_id, s.upload_kind, s.upload_reason,
    s.stored_name, s.storage_area, s.original_name, s.mime, s.byte_size, s.sha256, s.uploaded_by, s.uploaded_at,
    s.uploaded_at_utc, s.meta_hmac, s.hmac_key_id, s.is_active, s.review_status, s.reviewed_by, s.reviewed_at_utc,
    s.review_note, s.legacy_baseline_sha256, s.legacy_baseline_at_utc,
    pu.unit_code, pu.name AS unit_name, w.name AS ward_name,
    u.full_name AS uploaded_by_name, r.full_name AS reviewed_by_name';

const SHEET_ROW_JOINS = 'FROM result_sheet_uploads s
    INNER JOIN polling_units pu ON pu.id = s.polling_unit_id
    INNER JOIN wards w ON w.id = pu.ward_id
    LEFT JOIN users u ON u.id = s.uploaded_by
    LEFT JOIN users r ON r.id = s.reviewed_by';

/**
 * @return array<string, mixed>|null
 */
function sheet_find(int $id): ?array
{
    $statement = db()->prepare('SELECT ' . SHEET_ROW_COLUMNS . ' ' . SHEET_ROW_JOINS . ' WHERE s.id = :id LIMIT 1');
    $statement->bindValue(':id', $id, PDO::PARAM_INT);
    $statement->execute();
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

/**
 * @return array<string, mixed>|null
 */
function sheet_latest_for_unit(int $unitId): ?array
{
    $statement = db()->prepare(
        'SELECT ' . SHEET_ROW_COLUMNS . ' ' . SHEET_ROW_JOINS . '
         WHERE s.polling_unit_id = :unit AND s.is_active = 1
         ORDER BY s.id DESC
         LIMIT 1'
    );
    $statement->bindValue(':unit', $unitId, PDO::PARAM_INT);
    $statement->execute();
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

/**
 * Every version for a unit, newest first, including hidden ones.
 *
 * @return list<array<string, mixed>>
 */
function sheet_versions_for_unit(int $unitId): array
{
    $statement = db()->prepare(
        'SELECT ' . SHEET_ROW_COLUMNS . ' ' . SHEET_ROW_JOINS . '
         WHERE s.polling_unit_id = :unit
         ORDER BY s.id DESC'
    );
    $statement->bindValue(':unit', $unitId, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

/**
 * @return list<array<string, mixed>>
 */
function sheet_recent(int $limit = 24): array
{
    $limit = max(1, min(50, $limit));
    $statement = db()->query(
        'SELECT ' . SHEET_ROW_COLUMNS . ' ' . SHEET_ROW_JOINS . '
         WHERE s.is_active = 1
         ORDER BY s.id DESC
         LIMIT ' . $limit
    );

    return $statement->fetchAll();
}

/**
 * @return list<array<string, mixed>>
 */
function sheet_since(int $afterId, int $limit = 20): array
{
    $limit = max(1, min(50, $limit));
    $statement = db()->prepare(
        'SELECT ' . SHEET_ROW_COLUMNS . ' ' . SHEET_ROW_JOINS . '
         WHERE s.is_active = 1 AND s.id > :after
         ORDER BY s.id ASC
         LIMIT ' . $limit
    );
    $statement->bindValue(':after', $afterId, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

/**
 * Checks the uploaded bytes themselves. The bytes are never rewritten: the stored original is
 * exactly what was received, so its SHA-256 matches the upload.
 *
 * @return string|null MIME type when valid
 */
function sheet_validate_bytes(string $bytes): ?string
{
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($bytes);
    if (!is_string($mime) || !isset(sheet_mime_map()[$mime])) {
        return null;
    }
    if (stripos($bytes, '<?php') !== false || stripos($bytes, '<?=') !== false) {
        return null;
    }
    if ($mime === 'application/pdf') {
        return str_starts_with($bytes, '%PDF-') ? $mime : null;
    }
    $info = getimagesizefromstring($bytes);
    if ($info === false || ($info['mime'] ?? '') !== $mime) {
        return null;
    }
    $width = (int) $info[0];
    $height = (int) $info[1];
    if ($width < 1 || $height < 1 || ($width * $height) > 20000000) {
        return null;
    }
    if (function_exists('imagecreatefromstring')) {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }
        imagedestroy($image);
    }

    return $mime;
}

function sheet_original_name(string $name): string
{
    $name = str_replace(["\0", '/', '\\', "\r", "\n"], '', $name);
    $name = trim($name);
    if ($name === '') {
        return 'result-sheet';
    }
    if (strlen($name) > 180) {
        $name = substr($name, -180);
    }

    return $name;
}

/**
 * Stores a new version. Earlier versions and their files are never replaced or removed.
 *
 * @param array<string, mixed> $file
 * @return array{ok: bool, error: string, id: int}
 */
function sheet_store_upload(int $unitId, array $file, int $actorId, string $kind = '', string $reason = ''): array
{
    $fail = static fn (string $message): array => ['ok' => false, 'error' => $message, 'id' => 0];
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return $fail('Choose the result sheet photo or PDF.');
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        return $fail('That file is larger than the upload limit.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        return $fail('The result sheet could not be uploaded.');
    }
    if (!sheet_signing_available()) {
        error_log('Result-sheet upload refused: integrity key unavailable.');

        return $fail('Result-sheet uploads are paused because integrity protection is not configured. Contact an administrator.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $limitMessage = 'The result sheet must be a photo or PDF no larger than ' . (int) floor(sheet_max_bytes() / 1048576) . ' MB.';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return $fail($limitMessage);
    }
    $bytes = file_get_contents($tmp);
    if (!is_string($bytes) || $bytes === '' || strlen($bytes) > sheet_max_bytes()) {
        return $fail($limitMessage);
    }
    $mime = sheet_validate_bytes($bytes);
    if ($mime === null) {
        return $fail('Upload a JPEG, PNG, WebP, or PDF result sheet.');
    }
    $reason = trim(str_replace(["\r", "\n"], ' ', $reason));
    $sha256 = hash('sha256', $bytes);
    $stored = bin2hex(random_bytes(16)) . '.' . sheet_mime_map()[$mime];

    try {
        $dir = sheet_private_storage_dir(true);
    } catch (RuntimeException $exception) {
        error_log($exception->getMessage());

        return $fail('The result sheet could not be stored. Try again.');
    }
    $path = $dir . DIRECTORY_SEPARATOR . $stored;
    $handle = @fopen($path, 'xb');
    if ($handle === false) {
        return $fail('The result sheet could not be stored. Try again.');
    }
    $written = fwrite($handle, $bytes);
    fflush($handle);
    fclose($handle);
    if ($written !== strlen($bytes) || hash_file('sha256', $path) !== $sha256) {
        @chmod($path, 0600);
        @unlink($path);

        return $fail('The result sheet could not be stored. Try again.');
    }
    @chmod($path, 0400);

    try {
        $id = sheet_with_chain_lock(static function () use ($unitId, $actorId, $kind, $reason, $stored, $file, $mime, $bytes, $sha256): int|string {
            $pdo = db();
            $lockUnit = $pdo->prepare('SELECT id FROM polling_units WHERE id = :unit FOR UPDATE');
            $lockUnit->execute(['unit' => $unitId]);
            $latest = $pdo->prepare('SELECT id FROM result_sheet_uploads WHERE polling_unit_id = :unit ORDER BY id DESC LIMIT 1');
            $latest->execute(['unit' => $unitId]);
            $previousId = $latest->fetchColumn();
            $previousId = $previousId === false ? null : (int) $previousId;
            if ($previousId === null) {
                $kind = 'initial';
                $reason = $reason === '' ? 'First upload for this polling unit.' : $reason;
            } elseif (!isset(SHEET_UPLOAD_KINDS[$kind])) {
                return 'Choose whether this upload replaces or supplements the earlier version.';
            } elseif (strlen($reason) < 5 || strlen($reason) > 255) {
                return 'Explain in 5 to 255 characters why this version is being added.';
            }
            $next = $pdo->prepare('SELECT COALESCE(MAX(version_no), 0) + 1 FROM result_sheet_uploads WHERE polling_unit_id = :unit');
            $next->execute(['unit' => $unitId]);
            $row = [
                'polling_unit_id' => $unitId,
                'version_no' => (int) $next->fetchColumn(),
                'supersedes_id' => $previousId,
                'upload_kind' => $kind,
                'upload_reason' => $reason,
                'stored_name' => $stored,
                'storage_area' => 'private',
                'original_name' => sheet_original_name((string) ($file['name'] ?? '')),
                'mime' => $mime,
                'byte_size' => strlen($bytes),
                'sha256' => $sha256,
                'uploaded_by' => $actorId,
                'uploaded_at_utc' => sheet_utc_now(),
            ];
            $insert = $pdo->prepare(
                'INSERT INTO result_sheet_uploads
                    (polling_unit_id, version_no, supersedes_id, upload_kind, upload_reason, stored_name, storage_area,
                     original_name, mime, byte_size, sha256, uploaded_by, uploaded_at_utc)
                 VALUES (:polling_unit_id, :version_no, :supersedes_id, :upload_kind, :upload_reason, :stored_name, :storage_area,
                     :original_name, :mime, :byte_size, :sha256, :uploaded_by, :uploaded_at_utc)'
            );
            foreach ($row as $key => $value) {
                $insert->bindValue(':' . $key, $value, $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR));
            }
            $insert->execute();
            $row['id'] = (int) $pdo->lastInsertId();
            $signed = sheet_sign_row($row);
            if ($signed === null) {
                throw new RuntimeException('Integrity key became unavailable during upload.');
            }
            $sign = $pdo->prepare('UPDATE result_sheet_uploads SET meta_hmac = :hmac, hmac_key_id = :key_id WHERE id = :id');
            $sign->execute(['hmac' => $signed['hmac'], 'key_id' => $signed['key_id'], 'id' => $row['id']]);
            sheet_event($kind === 'initial' ? 'upload' : $kind, [
                'upload_id' => $row['id'],
                'polling_unit_id' => $unitId,
                'actor_user_id' => $actorId,
                'previous_upload_id' => $previousId,
                'new_upload_id' => $row['id'],
                'detail' => 'Version ' . $row['version_no'] . ' (' . $kind . '), SHA-256 ' . $sha256 . '. Reason: ' . $reason,
            ]);
            result_audit($actorId, 'upload_result_sheet', $row['id'], 'Uploaded result sheet version ' . $row['version_no'] . ' for polling unit ' . $unitId . '.');

            return $row['id'];
        });
    } catch (Throwable $exception) {
        @chmod($path, 0600);
        @unlink($path);
        throw $exception;
    }
    if (is_string($id)) {
        @chmod($path, 0600);
        @unlink($path);

        return $fail($id);
    }

    return ['ok' => true, 'error' => '', 'id' => $id];
}

function sheet_hide(int $id, int $actorId): bool
{
    return sheet_with_chain_lock(static function () use ($id, $actorId): bool {
        $statement = db()->prepare('UPDATE result_sheet_uploads SET is_active = 0 WHERE id = :id AND is_active = 1');
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();
        if ($statement->rowCount() < 1) {
            return false;
        }
        $unit = db()->prepare('SELECT polling_unit_id FROM result_sheet_uploads WHERE id = :id');
        $unit->execute(['id' => $id]);
        sheet_event('hidden', [
            'upload_id' => $id,
            'polling_unit_id' => (int) $unit->fetchColumn(),
            'actor_user_id' => $actorId,
            'detail' => 'Hidden from the live page. The file and its record are kept.',
        ]);
        result_audit($actorId, 'hide_result_sheet', $id, 'Hid result sheet ' . $id . ' from the live page.');

        return true;
    });
}

/**
 * Records a review decision. Approval is only allowed for a version that verifies right now.
 *
 * @param array{id: int, role: string} $user
 * @return array{ok: bool, message: string}
 */
function sheet_review(array $user, int $uploadId, string $decision, string $note): array
{
    if (!sheet_is_reviewer($user) || !in_array($decision, ['approved', 'rejected'], true)) {
        return ['ok' => false, 'message' => 'That review action is not available.'];
    }
    $note = trim(str_replace(["\r", "\n"], ' ', $note));
    if (strlen($note) > 255) {
        return ['ok' => false, 'message' => 'Keep the review note to 255 characters.'];
    }
    if ($decision === 'rejected' && strlen($note) < 5) {
        return ['ok' => false, 'message' => 'Give a reason of at least 5 characters when rejecting a version.'];
    }
    $row = sheet_find($uploadId);
    if ($row === null) {
        return ['ok' => false, 'message' => 'That result sheet was not found.'];
    }
    $check = sheet_verify($row, (int) $user['id']);
    if ($decision === 'approved' && $check['status'] !== SHEET_STATUS_VERIFIED) {
        return ['ok' => false, 'message' => 'Only a version whose integrity is VERIFIED can be approved.'];
    }

    sheet_with_chain_lock(static function () use ($row, $user, $decision, $note): void {
        $update = db()->prepare(
            'UPDATE result_sheet_uploads
             SET review_status = :status, reviewed_by = :by, reviewed_at_utc = :at, review_note = :note
             WHERE id = :id'
        );
        $update->execute([
            'status' => $decision,
            'by' => (int) $user['id'],
            'at' => sheet_utc_now(),
            'note' => $note === '' ? null : $note,
            'id' => (int) $row['id'],
        ]);
        sheet_event($decision, [
            'upload_id' => (int) $row['id'],
            'polling_unit_id' => (int) $row['polling_unit_id'],
            'actor_user_id' => (int) $user['id'],
            'detail' => 'Review: ' . $decision . ($note !== '' ? '. Note: ' . $note : '.'),
        ]);
    });

    return ['ok' => true, 'message' => $decision === 'approved' ? 'Version approved.' : 'Version rejected.'];
}

/**
 * The review state that the signed event trail supports. If the review columns were edited
 * directly in the database they will not match this.
 *
 * @param array<string, mixed> $row
 * @return array{status: string, trusted: bool}
 */
function sheet_review_state(array $row): array
{
    $statement = db()->prepare(
        "SELECT * FROM result_sheet_events
         WHERE upload_id = :upload AND event_type IN ('approved', 'rejected')
         ORDER BY id DESC LIMIT 1"
    );
    $statement->execute(['upload' => (int) $row['id']]);
    $event = $statement->fetch();
    $column = (string) ($row['review_status'] ?? 'pending');
    if (!is_array($event)) {
        return ['status' => $column, 'trusted' => $column === 'pending'];
    }

    return [
        'status' => $column,
        'trusted' => sheet_event_signature($event) === 'valid' && (string) $event['event_type'] === $column,
    ];
}

/**
 * Records a refused attempt to reach a result sheet.
 *
 * @param array{id: int, role: string} $user
 */
function sheet_log_denied(array $user, ?int $uploadId, ?int $unitId, string $context): void
{
    try {
        sheet_event('unauthorized_access', [
            'upload_id' => $uploadId,
            'polling_unit_id' => $unitId,
            'actor_user_id' => (int) $user['id'],
            'detail' => 'Refused ' . $context . ' for role ' . $user['role'] . '.',
        ]);
    } catch (Throwable $exception) {
        error_log('Denied access could not be recorded: ' . $exception->getMessage());
    }
}

/**
 * Verifies the stored bytes and sends exactly those bytes. Nothing is sent when integrity fails.
 *
 * @param array<string, mixed> $upload
 */
function sheet_send(array $upload, ?int $actorId): never
{
    $mime = (string) $upload['mime'];
    if (!isset(sheet_mime_map()[$mime])) {
        http_response_code(404);
        exit;
    }
    $check = sheet_verify($upload, $actorId, true);
    if (!sheet_status_deliverable($check['status']) || $check['bytes'] === null) {
        http_response_code(409);
        header('Content-Type: text/plain; charset=UTF-8');
        header('X-Sheet-Integrity: ' . sheet_status_label($check['status']));
        echo 'This result sheet is withheld: ' . sheet_status_label($check['status']) . '.';
        exit;
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) strlen($check['bytes']));
    header('Content-Disposition: inline; filename="result-sheet.' . sheet_mime_map()[$mime] . '"');
    header('X-Content-Type-Options: nosniff');
    header('X-Sheet-Integrity: ' . sheet_status_label($check['status']));
    if ($check['observed'] !== null) {
        header('X-Sheet-SHA256: ' . $check['observed']);
    }
    header(str_starts_with($mime, 'image/')
        ? "Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox"
        : "Content-Security-Policy: default-src 'none'; object-src 'self'");
    echo $check['bytes'];
    exit;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, int|string|bool>
 */
function sheet_public_row(array $row, ?int $actorId = null): array
{
    $check = sheet_verify($row, $actorId);

    return [
        'id' => (int) $row['id'],
        'unit_code' => (string) $row['unit_code'],
        'unit_name' => (string) $row['unit_name'],
        'ward_name' => (string) $row['ward_name'],
        'uploaded_by_name' => $row['uploaded_by_name'] !== null ? (string) $row['uploaded_by_name'] : '',
        'uploaded_at' => (string) ($row['uploaded_at_utc'] ?? '') !== '' ? $row['uploaded_at_utc'] . ' UTC' : (string) $row['uploaded_at'],
        'mime' => (string) $row['mime'],
        'original_name' => (string) $row['original_name'],
        'version' => $row['version_no'] !== null ? 'v' . (int) $row['version_no'] : 'legacy',
        'integrity' => sheet_status_label($check['status']),
        'integrity_ok' => $check['status'] === SHEET_STATUS_VERIFIED,
        'deliverable' => sheet_status_deliverable($check['status']),
        'sha256' => (string) ($row['sha256'] ?? ''),
    ];
}

/**
 * @param array<string, mixed> $row
 */
function sheet_uploaded_at(array $row): string
{
    return (string) ($row['uploaded_at_utc'] ?? '') !== '' ? $row['uploaded_at_utc'] . ' UTC' : $row['uploaded_at'] . ' (server time)';
}

function sheet_redirect(string $path): never
{
    if (!preg_match('#\Aresults/(live|entry|sheet|incidents|incident)\.php(?:\?[a-z0-9_=&%+.-]*)?\z#', $path)) {
        $path = 'results/live.php';
    }
    header('Location: ' . url($path), true, 302);
    exit;
}

function sheet_live_script(): void
{
    ?>
    <script nonce="<?= e(app_csp_nonce()) ?>">
    (function () {
        var board = document.getElementById('live-board');
        var status = document.getElementById('live-status');
        if (!board) {
            return;
        }
        var after = parseInt(board.getAttribute('data-latest') || '0', 10);
        var feed = board.getAttribute('data-feed') || '';
        var fileBase = board.getAttribute('data-file') || '';
        var detailBase = board.getAttribute('data-detail') || '';
        var csrf = board.getAttribute('data-csrf') || '';
        var busy = false;
        function text(tag, className, value) {
            var node = document.createElement(tag);
            if (className) {
                node.className = className;
            }
            node.textContent = value;
            return node;
        }
        function add(item) {
            var empty = document.getElementById('live-empty');
            if (empty) {
                empty.remove();
            }
            var article = document.createElement('article');
            article.className = 'pu-record';
            if (item.deliverable && item.mime.indexOf('image/') === 0) {
                var img = document.createElement('img');
                img.className = 'sheet-preview';
                img.alt = 'Result sheet for ' + item.unit_code;
                img.src = fileBase + '?id=' + item.id;
                article.appendChild(img);
            }
            var id = document.createElement('div');
            id.className = 'pu-record__id';
            id.appendChild(text('strong', '', item.unit_code + ' · ' + item.version));
            id.appendChild(text('p', '', item.unit_name + ' · ' + item.ward_name));
            id.appendChild(text('span', 'cms-badge ' + (item.integrity_ok ? 'cms-badge--ok' : (item.deliverable ? 'cms-badge--warn' : 'cms-badge--off')), item.integrity));
            article.appendChild(id);
            var meta = document.createElement('dl');
            meta.className = 'pu-record__meta';
            [['Uploaded by', item.uploaded_by_name || '—'], ['Time', item.uploaded_at], ['File', item.original_name], ['SHA-256', item.sha256 || 'Not recorded']].forEach(function (pair) {
                var block = document.createElement('div');
                block.appendChild(text('dt', '', pair[0]));
                block.appendChild(text('dd', pair[0] === 'SHA-256' ? 'sheet-hash' : '', pair[1]));
                meta.appendChild(block);
            });
            article.appendChild(meta);
            var actions = document.createElement('div');
            actions.className = 'pu-row-actions';
            var details = document.createElement('a');
            details.className = 'cms-btn cms-btn--ghost cms-btn--sm';
            details.href = detailBase + '?id=' + item.id;
            details.textContent = 'Integrity and review';
            actions.appendChild(details);
            if (item.deliverable && item.mime === 'application/pdf') {
                var open = document.createElement('a');
                open.className = 'cms-btn cms-btn--ghost cms-btn--sm';
                open.href = fileBase + '?id=' + item.id;
                open.textContent = 'Open PDF';
                actions.appendChild(open);
            }
            var form = document.createElement('form');
            form.method = 'post';
            form.action = board.getAttribute('data-action') || '';
            [['csrf_token', csrf], ['intent', 'hide'], ['sheet_id', String(item.id)]].forEach(function (pair) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = pair[0];
                input.value = pair[1];
                form.appendChild(input);
            });
            var button = document.createElement('button');
            button.className = 'cms-btn cms-btn--ghost cms-btn--sm';
            button.type = 'submit';
            button.textContent = 'Hide';
            form.appendChild(button);
            actions.appendChild(form);
            article.appendChild(actions);
            board.insertBefore(article, board.firstChild);
            if (item.id > after) {
                after = item.id;
            }
        }
        function tick() {
            if (busy || document.hidden) {
                return;
            }
            busy = true;
            fetch(feed + '?after=' + after, {credentials: 'same-origin', headers: {Accept: 'application/json'}})
                .then(function (response) { return response.ok ? response.json() : null; })
                .then(function (payload) {
                    if (!payload || !Array.isArray(payload.items)) {
                        if (status) {
                            status.textContent = 'The live check will try again.';
                        }
                        return;
                    }
                    payload.items.forEach(add);
                    if (status) {
                        status.textContent = payload.items.length > 0 ? 'New result sheet received.' : 'Watching for new result sheets.';
                    }
                })
                .catch(function () {
                    if (status) {
                        status.textContent = 'The live check will try again.';
                    }
                })
                .finally(function () { busy = false; });
        }
        setInterval(tick, 5000);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                tick();
            }
        });
    })();
    </script>
    <?php
}
