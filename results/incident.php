<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/sheet_incidents.php';

auth_boot();
$user = auth_user();
if ($user === null) {
    redirect_to('portal/login.php');
}
if (!sheet_is_reviewer($user)) {
    if (sheet_schema_ready()) {
        sheet_log_denied($user, null, null, 'integrity incident page');
    }
    render_forbidden($user);
    exit;
}
$notFound = static function (string $message, int $code) use ($user): never {
    http_response_code($code);
    render_shell_start('Integrity incident', 'incidents', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">' . e($message) . '</p>';
    render_shell_end();
    exit;
};
if (!sheet_schema_ready()) {
    $notFound('Apply database/migration_sheet_integrity.sql, then reload this page.', 404);
}

$rawId = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['incident_id'] ?? '') : ($_GET['id'] ?? '');
$id = is_string($rawId) && preg_match('/\A[1-9][0-9]{0,9}\z/', $rawId) ? (int) $rawId : 0;
$incident = $id > 0 && $id <= 2147483647 ? sheet_incident_find($id) : null;
if ($incident === null) {
    $notFound('That incident was not found.', 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_verify(is_string($token) ? $token : null)) {
        result_flash('error', 'The form expired. Reload the page and try again.');
    } else {
        try {
            $outcome = sheet_incident_act(
                $user,
                (int) $incident['id'],
                is_string($_POST['action'] ?? null) ? $_POST['action'] : '',
                is_string($_POST['reason'] ?? null) ? $_POST['reason'] : ''
            );
            result_flash($outcome['ok'] ? 'success' : 'error', $outcome['message']);
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            result_flash('error', 'The action could not be saved. Nothing was changed.');
        }
    }
    sheet_redirect('results/incident.php?id=' . (int) $incident['id']);
}

try {
    $events = sheet_incident_events($incident);
    $auth = sheet_incident_auth($incident, $events);
    $upload = sheet_find((int) $incident['upload_id']);
    $check = $upload !== null ? sheet_verify($upload, (int) $user['id']) : null;
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $notFound('The incident could not be checked. Try again.', 500);
}
$flash = result_take_flash();
$status = (string) $incident['status'];
$versionLabel = $incident['version_no'] !== null ? 'Version ' . (int) $incident['version_no'] : 'Legacy upload';
$eventLabels = ['valid' => 'Signed', 'invalid' => 'SIGNATURE FAILED', 'unsigned' => 'Unsigned', 'unavailable' => 'Key unavailable'];
$actions = [];
if ($auth['actionable']) {
    foreach (SHEET_INCIDENT_ACTIONS as $name => $rule) {
        if (in_array($status, $rule['from'], true)) {
            $actions[$name] = $rule;
        }
    }
}
$actionLabels = ['acknowledge' => 'Acknowledge', 'investigate' => 'Mark as investigating', 'close' => 'Close incident'];

render_shell_start('Integrity incident', 'incidents', $user);
?>
<nav class="pu-crumb" aria-label="Breadcrumb">
    <a href="<?= e(url('results/incidents.php')) ?>">Integrity incidents</a>
    <span aria-hidden="true">/</span>
    <span>Incident #<?= e((string) $incident['id']) ?></span>
</nav>
<header class="cms-page__header">
    <div>
        <h1 class="cms-page__title">Incident #<?= e((string) $incident['id']) ?> · <?= e((string) $incident['unit_code']) ?></h1>
        <p class="cms-page__subtitle"><?= e(sheet_incident_type_label((string) $incident['incident_type'])) ?> · <?= e((string) $incident['unit_name']) ?> · <?= e((string) $incident['ward_name']) ?></p>
    </div>
    <div class="cms-page__actions">
        <a class="cms-btn cms-btn--ghost" href="<?= e(url('results/sheet.php?id=' . (int) $incident['upload_id'])) ?>">Open sheet record</a>
    </div>
</header>
<?php if ($flash !== null): ?>
<p class="pu-alert pu-alert--<?= e($flash['kind']) ?>" role="<?= $flash['kind'] === 'error' ? 'alert' : 'status' ?>"><?= e($flash['message']) ?></p>
<?php endif; ?>
<?php if ($auth['message'] !== ''): ?>
<p class="pu-alert pu-alert--<?= $auth['state'] === 'unsigned' ? 'warn' : 'error' ?>" role="alert"><?= e($auth['message']) ?></p>
<?php endif; ?>

<article class="cms-card">
    <div class="cms-card__head">
        <h2 class="cms-card__title">Incident</h2>
        <span class="cms-badge <?= $status === 'closed' ? 'cms-badge--muted' : 'cms-badge--off' ?>"><?= e(ucfirst($status)) ?></span>
        <span class="cms-badge <?= $auth['state'] === 'authenticated' ? 'cms-badge--ok' : 'cms-badge--off' ?>"><?= e($auth['label']) ?></span>
    </div>
    <div class="cms-card__body">
        <dl class="pu-record__meta">
            <div><dt>Type</dt><dd><?= e((string) $incident['incident_type']) ?></dd></div>
            <div><dt>Polling unit</dt><dd><?= e((string) $incident['unit_code']) ?></dd></div>
            <div><dt>Affected sheet</dt><dd><?= e($versionLabel) ?> (record <?= e((string) $incident['upload_id']) ?>, <?= e((string) ($incident['upload_kind'] ?? 'legacy')) ?>)</dd></div>
            <div><dt>Detected</dt><dd><?= e((string) $incident['detected_at_utc']) ?> UTC<?= $incident['detected_by_name'] !== null ? ' · during access by ' . e((string) $incident['detected_by_name']) : '' ?></dd></div>
            <?php if ($status === 'closed'): ?>
            <div><dt>Closed</dt><dd><?= e((string) $incident['resolved_at_utc']) ?> UTC · <?= e($incident['resolved_by_name'] !== null ? (string) $incident['resolved_by_name'] : '—') ?></dd></div>
            <div><dt>Closing explanation</dt><dd><?= e((string) $incident['resolution_note']) ?></dd></div>
            <?php endif; ?>
        </dl>
    </div>
</article>

<article class="cms-card">
    <div class="cms-card__head">
        <h2 class="cms-card__title">Verification findings</h2>
        <?php if ($check !== null): ?>
        <span class="cms-badge <?= e(sheet_status_badge($check['status'])) ?>"><?= e(sheet_status_label($check['status'])) ?></span>
        <?php endif; ?>
    </div>
    <div class="cms-card__body">
        <?php if ($check !== null && !$check['deliverable']): ?>
        <p class="pu-alert pu-alert--error" role="alert">The sheet is still withheld. Closing this incident does not change that; only a passing integrity check releases the file.</p>
        <?php elseif ($check !== null && $status !== 'closed'): ?>
        <p class="pu-note">The sheet currently passes its integrity check. The incident remains on record until a reviewer closes it with an explanation.</p>
        <?php endif; ?>
        <dl class="pu-record__meta">
            <div><dt>Recorded SHA-256</dt><dd class="sheet-hash"><?= e((string) ($incident['recorded_sha256'] ?? '') !== '' ? (string) $incident['recorded_sha256'] : 'Not recorded') ?></dd></div>
            <div><dt>SHA-256 observed at detection</dt><dd class="sheet-hash"><?= e((string) ($incident['observed_sha256'] ?? '') !== '' ? (string) $incident['observed_sha256'] : 'Not available') ?></dd></div>
            <?php if ($check !== null): ?>
            <div><dt>SHA-256 of stored file now</dt><dd class="sheet-hash"><?= e($check['observed'] !== null ? (string) $check['observed'] : 'Not available') ?></dd></div>
            <?php endif; ?>
        </dl>
        <p class="pu-note">SHA-256 is an integrity fingerprint. A match shows the stored file is unchanged since upload; it does not prove the document was genuine when uploaded. This is an independent monitoring record, not an official INEC result.</p>
    </div>
</article>

<?php if ($actions !== []): ?>
<article class="cms-card">
    <div class="cms-card__head"><h2 class="cms-card__title">Record an action</h2></div>
    <div class="cms-card__body">
        <form method="post" action="<?= e(url('results/incident.php')) ?>" data-pu-busy="Saving…">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="incident_id" value="<?= e((string) $incident['id']) ?>">
            <div class="pu-field">
                <label for="incident-reason">Reason (required; closing needs at least 10 characters)</label>
                <textarea id="incident-reason" name="reason" maxlength="<?= SHEET_INCIDENT_REASON_MAX ?>" required minlength="5"></textarea>
            </div>
            <div class="pu-form-actions">
                <?php foreach ($actions as $name => $rule): ?>
                <button class="cms-btn <?= $name === 'close' ? 'cms-btn--ghost' : 'cms-btn--primary' ?>" type="submit" name="action" value="<?= e($name) ?>"><?= e($actionLabels[$name]) ?></button>
                <?php endforeach; ?>
            </div>
        </form>
    </div>
</article>
<?php endif; ?>

<article class="cms-card">
    <div class="cms-card__head"><h2 class="cms-card__title">Incident history</h2></div>
    <div class="cms-card__body pu-list">
        <?php if ($events === []): ?>
        <p class="pu-note">No signed events reference this incident.</p>
        <?php endif; ?>
        <?php foreach ($events as $event): ?>
        <?php $eventSignature = $auth['signatures'][(int) $event['id']] ?? 'unavailable'; ?>
        <article class="pu-record">
            <div class="pu-record__id">
                <strong><?= e(str_replace('_', ' ', (string) $event['event_type'])) ?></strong>
                <p><?= e((string) $event['occurred_at_utc']) ?> UTC · <?= e($event['actor_name'] !== null ? (string) $event['actor_name'] : 'System') ?></p>
                <span class="cms-badge <?= e($eventSignature === 'valid' ? 'cms-badge--ok' : 'cms-badge--off') ?>"><?= e($eventLabels[$eventSignature]) ?></span>
            </div>
            <dl class="pu-record__meta">
                <div><dt>Detail</dt><dd><?= e((string) $event['detail']) ?></dd></div>
            </dl>
        </article>
        <?php endforeach; ?>
    </div>
</article>
<?php
result_form_script();
render_shell_end();
