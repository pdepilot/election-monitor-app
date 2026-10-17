<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/result_sheets.php';

auth_boot();
$user = auth_user();
if ($user === null) {
    redirect_to('portal/login.php');
}
if (!sheet_schema_ready()) {
    http_response_code(404);
    render_shell_start('Result sheet', 'live', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">Apply database/migration_sheet_integrity.sql, then reload this page.</p>';
    render_shell_end();
    exit;
}
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($id) || $id < 1) {
    $posted = filter_var($_POST['sheet_id'] ?? '', FILTER_VALIDATE_INT);
    $id = is_int($posted) && $posted > 0 ? $posted : 0;
}
$row = $id > 0 ? sheet_find($id) : null;
if (!sheet_is_reviewer($user)) {
    sheet_log_denied($user, $row !== null ? (int) $row['id'] : null, $row !== null ? (int) $row['polling_unit_id'] : null, 'result-sheet review page');
    render_forbidden($user);
    exit;
}
if ($row === null) {
    http_response_code(404);
    render_shell_start('Result sheet', 'live', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">That result sheet was not found.</p>';
    render_shell_end();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_verify(is_string($token) ? $token : null) || (string) ($_POST['intent'] ?? '') !== 'review') {
        result_flash('error', 'The form expired. Reload the page and try again.');
    } else {
        try {
            $review = sheet_review($user, (int) $row['id'], (string) ($_POST['decision'] ?? ''), is_string($_POST['note'] ?? null) ? $_POST['note'] : '');
            result_flash($review['ok'] ? 'success' : 'error', $review['message']);
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            result_flash('error', 'The review could not be saved. Try again.');
        }
    }
    sheet_redirect('results/sheet.php?id=' . (int) $row['id']);
}

try {
    $check = sheet_verify($row, (int) $user['id']);
    $signature = sheet_is_legacy($row) ? 'legacy' : sheet_row_signature($row);
    $incidents = sheet_open_incidents((int) $row['id']);
    $versions = sheet_versions_for_unit((int) $row['polling_unit_id']);
    $events = sheet_events_for_unit((int) $row['polling_unit_id']);
    $review = sheet_review_state($row);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    render_shell_start('Result sheet', 'live', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">The result sheet could not be checked. Try again.</p>';
    render_shell_end();
    exit;
}
$flash = result_take_flash();
$status = $check['status'];
$warning = sheet_status_warning($status);
$versionLabel = $row['version_no'] !== null ? 'Version ' . (int) $row['version_no'] : 'Legacy upload';
$signatureLabel = match ($signature) {
    'valid' => 'Authenticated (key ' . (string) $row['hmac_key_id'] . ')',
    'invalid' => 'FAILED: the record does not match its signature',
    'unavailable' => 'Cannot be checked: the signing key is unavailable',
    default => 'None (uploaded before integrity protection)',
};
$eventLabels = ['valid' => 'Signed', 'invalid' => 'SIGNATURE FAILED', 'unsigned' => 'Unsigned', 'unavailable' => 'Key unavailable'];

render_shell_start('Result sheet integrity', 'live', $user);
?>
<nav class="pu-crumb" aria-label="Breadcrumb">
    <a href="<?= e(url('results/live.php')) ?>">Live sheets</a>
    <span aria-hidden="true">/</span>
    <span><?= e((string) $row['unit_code']) ?> · <?= e($versionLabel) ?></span>
</nav>
<header class="cms-page__header">
    <div>
        <h1 class="cms-page__title"><?= e((string) $row['unit_code']) ?> · <?= e($versionLabel) ?></h1>
        <p class="cms-page__subtitle"><?= e((string) $row['unit_name']) ?> · <?= e((string) $row['ward_name']) ?></p>
    </div>
    <div class="cms-page__actions">
        <a class="cms-btn cms-btn--ghost" href="<?= e(url('results/entry.php?unit=' . (int) $row['polling_unit_id'])) ?>">Open result</a>
    </div>
</header>
<?php if ($flash !== null): ?>
<p class="pu-alert pu-alert--<?= e($flash['kind']) ?>" role="<?= $flash['kind'] === 'error' ? 'alert' : 'status' ?>"><?= e($flash['message']) ?></p>
<?php endif; ?>
<p class="pu-note">This is an independent monitoring upload, not an official INEC result. A VERIFIED status means the stored file and its record are unchanged since upload. It does not prove the photo or document was genuine when it was uploaded.</p>

<article class="cms-card">
    <div class="cms-card__head">
        <h2 class="cms-card__title">Integrity</h2>
        <span class="cms-badge <?= e(sheet_status_badge($status)) ?>"><?= e(sheet_status_label($status)) ?></span>
    </div>
    <div class="cms-card__body">
        <?php if ($warning !== ''): ?>
        <p class="pu-alert pu-alert--<?= $status === SHEET_STATUS_LEGACY ? 'warn' : 'error' ?>" role="alert"><?= e($warning) ?></p>
        <?php endif; ?>
        <?php if ($status === SHEET_STATUS_LEGACY && (string) ($row['legacy_baseline_at_utc'] ?? '') !== ''): ?>
        <p class="pu-note">A baseline fingerprint was recorded on <?= e((string) $row['legacy_baseline_at_utc']) ?> UTC. It only shows the file has not changed since then.</p>
        <?php endif; ?>
        <?php if ($check['deliverable'] && str_starts_with((string) $row['mime'], 'image/')): ?>
        <img class="sheet-preview" src="<?= e(url('results/sheet-file.php?id=' . (int) $row['id'])) ?>" alt="Result sheet for <?= e((string) $row['unit_code']) ?>">
        <?php elseif ($check['deliverable']): ?>
        <p><a class="cms-btn cms-btn--ghost cms-btn--sm" href="<?= e(url('results/sheet-file.php?id=' . (int) $row['id'])) ?>">Open PDF</a></p>
        <?php endif; ?>
        <dl class="pu-record__meta">
            <div><dt>Recorded SHA-256</dt><dd class="sheet-hash"><?= e((string) ($row['sha256'] ?? '') !== '' ? (string) $row['sha256'] : 'Not recorded') ?></dd></div>
            <?php if ($check['observed'] !== null && $status !== SHEET_STATUS_VERIFIED): ?>
            <div><dt>SHA-256 of stored file now</dt><dd class="sheet-hash"><?= e((string) $check['observed']) ?></dd></div>
            <?php endif; ?>
            <div><dt>Record signature</dt><dd><?= e($signatureLabel) ?></dd></div>
            <div><dt>Uploaded</dt><dd><?= e(sheet_uploaded_at($row)) ?></dd></div>
            <div><dt>Uploaded by</dt><dd><?= e($row['uploaded_by_name'] !== null ? (string) $row['uploaded_by_name'] : '—') ?></dd></div>
            <div><dt>Type</dt><dd><?= e((string) ($row['upload_kind'] ?? 'legacy')) ?></dd></div>
            <div><dt>Reason</dt><dd><?= e((string) ($row['upload_reason'] ?? '—')) ?></dd></div>
            <div><dt>Previous version</dt><dd><?php if ($row['supersedes_id'] !== null): ?><a href="<?= e(url('results/sheet.php?id=' . (int) $row['supersedes_id'])) ?>">Record <?= e((string) $row['supersedes_id']) ?></a><?php else: ?>—<?php endif; ?></dd></div>
            <div><dt>File</dt><dd><?= e((string) $row['mime']) ?> · <?= e(number_format((int) $row['byte_size'])) ?> bytes</dd></div>
            <div><dt>Original filename (display only)</dt><dd><?= e((string) $row['original_name']) ?></dd></div>
            <div><dt>On live page</dt><dd><?= (int) $row['is_active'] === 1 ? 'Yes' : 'Hidden' ?></dd></div>
        </dl>
        <?php if ($incidents !== []): ?>
        <h3 class="cms-card__title">Unresolved integrity incidents</h3>
        <ul>
            <?php foreach ($incidents as $incident): ?>
            <li><a href="<?= e(url('results/incident.php?id=' . (int) $incident['id'])) ?>">Incident #<?= e((string) $incident['id']) ?></a> · <?= e((string) $incident['incident_type']) ?> · <?= e((string) $incident['status']) ?> · detected <?= e((string) $incident['detected_at_utc']) ?> UTC</li>
            <?php endforeach; ?>
        </ul>
        <p class="pu-note">Incidents stay open until someone investigates them. The original file, its record, and the recorded fingerprint are never changed automatically.</p>
        <?php endif; ?>
    </div>
</article>

<article class="cms-card">
    <div class="cms-card__head">
        <h2 class="cms-card__title">Review</h2>
        <span class="cms-badge <?= e($review['status'] === 'approved' ? 'cms-badge--ok' : ($review['status'] === 'rejected' ? 'cms-badge--off' : 'cms-badge--muted')) ?>"><?= e(ucfirst($review['status'])) ?></span>
    </div>
    <div class="cms-card__body">
        <?php if (!$review['trusted']): ?>
        <p class="pu-alert pu-alert--error" role="alert">The stored review status does not match the signed event trail. Treat it as unreliable.</p>
        <?php endif; ?>
        <?php if ((string) ($row['reviewed_at_utc'] ?? '') !== ''): ?>
        <p class="pu-note">Last reviewed by <?= e($row['reviewed_by_name'] !== null ? (string) $row['reviewed_by_name'] : '—') ?> on <?= e((string) $row['reviewed_at_utc']) ?> UTC<?= (string) ($row['review_note'] ?? '') !== '' ? ': ' . e((string) $row['review_note']) : '' ?>.</p>
        <?php endif; ?>
        <form method="post" action="<?= e(url('results/sheet.php?id=' . (int) $row['id'])) ?>" data-pu-busy="Saving…">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="intent" value="review">
            <input type="hidden" name="sheet_id" value="<?= e((string) $row['id']) ?>">
            <div class="pu-field">
                <label for="review-note">Review note (required when rejecting)</label>
                <textarea id="review-note" name="note" maxlength="255"></textarea>
            </div>
            <div class="pu-form-actions">
                <?php if ($status === SHEET_STATUS_VERIFIED): ?>
                <button class="cms-btn cms-btn--primary" type="submit" name="decision" value="approved">Approve version</button>
                <?php else: ?>
                <p class="pu-note">Approval is only available when integrity is VERIFIED.</p>
                <?php endif; ?>
                <button class="cms-btn cms-btn--ghost" type="submit" name="decision" value="rejected">Reject version</button>
            </div>
        </form>
    </div>
</article>

<article class="cms-card">
    <div class="cms-card__head"><h2 class="cms-card__title">Version history for <?= e((string) $row['unit_code']) ?></h2></div>
    <div class="cms-card__body pu-list">
        <?php foreach ($versions as $version): ?>
        <?php $vCheck = sheet_verify($version, (int) $user['id']); ?>
        <article class="pu-record">
            <div class="pu-record__id">
                <strong><a href="<?= e(url('results/sheet.php?id=' . (int) $version['id'])) ?>"><?= e($version['version_no'] !== null ? 'Version ' . (int) $version['version_no'] : 'Legacy upload') ?></a><?= (int) $version['id'] === (int) $row['id'] ? ' (this one)' : '' ?></strong>
                <p><?= e((string) ($version['upload_kind'] ?? 'legacy')) ?> · <?= e(sheet_uploaded_at($version)) ?> · <?= e($version['uploaded_by_name'] !== null ? (string) $version['uploaded_by_name'] : '—') ?></p>
                <span class="cms-badge <?= e(sheet_status_badge($vCheck['status'])) ?>"><?= e(sheet_status_label($vCheck['status'])) ?></span>
                <span class="cms-badge cms-badge--muted"><?= e(ucfirst((string) $version['review_status'])) ?></span>
            </div>
            <dl class="pu-record__meta">
                <div><dt>SHA-256</dt><dd class="sheet-hash"><?= e((string) ($version['sha256'] ?? '') !== '' ? (string) $version['sha256'] : 'Not recorded') ?></dd></div>
                <div><dt>Reason</dt><dd><?= e((string) ($version['upload_reason'] ?? '—')) ?></dd></div>
            </dl>
        </article>
        <?php endforeach; ?>
    </div>
</article>

<article class="cms-card">
    <div class="cms-card__head"><h2 class="cms-card__title">Event trail for <?= e((string) $row['unit_code']) ?></h2></div>
    <div class="cms-card__body pu-list">
        <?php if ($events === []): ?>
        <p class="pu-note">No events recorded.</p>
        <?php endif; ?>
        <?php foreach ($events as $event): ?>
        <?php $eventSignature = sheet_event_signature($event); ?>
        <article class="pu-record">
            <div class="pu-record__id">
                <strong><?= e(str_replace('_', ' ', (string) $event['event_type'])) ?></strong>
                <p><?= e((string) $event['occurred_at_utc']) ?> UTC · <?= e($event['actor_name'] !== null ? (string) $event['actor_name'] : 'System') ?></p>
                <span class="cms-badge <?= e($eventSignature === 'valid' ? 'cms-badge--ok' : 'cms-badge--off') ?>"><?= e($eventLabels[$eventSignature]) ?></span>
            </div>
            <dl class="pu-record__meta">
                <div><dt>Detail</dt><dd><?= e((string) $event['detail']) ?></dd></div>
                <?php if ($event['previous_upload_id'] !== null || $event['new_upload_id'] !== null): ?>
                <div><dt>Records</dt><dd><?= e('previous ' . ($event['previous_upload_id'] ?? '—') . ' · new ' . ($event['new_upload_id'] ?? '—')) ?></dd></div>
                <?php endif; ?>
            </dl>
        </article>
        <?php endforeach; ?>
    </div>
</article>
<?php
result_form_script();
render_shell_end();
