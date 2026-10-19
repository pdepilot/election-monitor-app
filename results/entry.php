<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/result_sheets.php';
require_once dirname(__DIR__) . '/includes/result_submissions.php';

auth_boot();
$user = result_require_user();

if (!result_schema_ready()) {
    result_render_missing_schema($user);
    exit;
}
if (!result_review_schema_ready()) {
    http_response_code(503);
    render_shell_start('Result', 'results', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">Apply database/migration_result_review.sql, then reload this page.</p>';
    render_shell_end();
    exit;
}

$unitId = filter_input(INPUT_GET, 'unit', FILTER_VALIDATE_INT);
if (!is_int($unitId) || $unitId < 1) {
    $posted = filter_var($_POST['unit_id'] ?? '', FILTER_VALIDATE_INT);
    $unitId = is_int($posted) && $posted > 0 ? $posted : 0;
}
$unit = $unitId > 0 ? result_unit($unitId) : null;
if ($unit === null || !result_can_view_unit($user, $unit)) {
    // Agents get a uniform 403 so unit IDs are not enumerated by missing vs denied.
    $hideExistence = $user['role'] === 'polling_agent' || $unit !== null;
    http_response_code($hideExistence ? 403 : 404);
    render_shell_start('Result', 'results', $user);
    ?>
    <header class="cms-page__header">
        <div>
            <h1 class="cms-page__title"><?= $hideExistence ? 'Access denied' : 'Result not found' ?></h1>
            <p class="cms-page__subtitle"><?= $hideExistence
                ? 'You can only open results for a polling unit you are authorized to view.'
                : 'That polling unit is not in the register.' ?></p>
        </div>
    </header>
    <p><a class="cms-btn cms-btn--primary" href="<?= e(url('results/index.php')) ?>">Back to results</a></p>
    <?php
    render_shell_end();
    exit;
}

$sheet = result_sheet((int) $unit['id']);
$candidates = result_candidates_for_sheet($sheet === null ? null : (int) $sheet['id']);
$canEdit = result_can_edit($user, $unit, $sheet);
$canUpload = sheet_schema_ready() && sheet_can_upload($user, $unit);
$sheetError = '';
$errors = [];
$warnings = [];
$values = ['votes' => $sheet === null ? [] : result_votes((int) $sheet['id'])];
foreach (array_keys(RESULT_FIGURE_FIELDS) as $field) {
    $values[$field] = $sheet === null || $sheet[$field] === null ? '' : (string) $sheet[$field];
}
$isSubmitted = $sheet !== null && (string) $sheet['status'] === 'submitted';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['intent'] ?? '') === 'upload_sheet') {
    $token = $_POST['csrf_token'] ?? null;
    if (!$canUpload) {
        http_response_code(403);
        if (sheet_schema_ready()) {
            sheet_log_denied($user, null, (int) $unit['id'], 'result-sheet upload');
        }
        $sheetError = 'Your account cannot upload a result sheet for this polling unit.';
    } elseif (!csrf_verify(is_string($token) ? $token : null)) {
        http_response_code(403);
        $sheetError = 'The form expired. Reload the page and try again.';
    } else {
        try {
            $saved = sheet_store_upload(
                (int) $unit['id'],
                is_array($_FILES['result_sheet'] ?? null) ? $_FILES['result_sheet'] : [],
                (int) $user['id'],
                is_string($_POST['upload_kind'] ?? null) ? $_POST['upload_kind'] : '',
                is_string($_POST['upload_reason'] ?? null) ? $_POST['upload_reason'] : ''
            );
            if ($saved['ok']) {
                result_flash('success', 'Result sheet uploaded as a new version. Earlier versions are kept.');
                result_redirect('results/entry.php?unit=' . (int) $unit['id']);
            }
            $sheetError = $saved['error'];
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            $sheetError = 'The result sheet could not be uploaded. Try again.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $intent = (string) ($_POST['intent'] ?? '');
    if (!$canEdit) {
        http_response_code(403);
        $errors['form'] = 'This result can no longer be changed from your account.';
    } elseif (!csrf_verify(is_string($token) ? $token : null)) {
        http_response_code(403);
        $errors['form'] = 'The form expired. Reload the page and try again.';
    } elseif (!in_array($intent, ['save', 'submit'], true)) {
        $errors['form'] = 'That action is not available.';
    } else {
        $checked = result_validate($_POST, $candidates, $intent === 'submit' || $isSubmitted);
        $errors = $checked['errors'];
        foreach (array_keys(RESULT_FIGURE_FIELDS) as $field) {
            $values[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : '';
        }
        $values['votes'] = is_array($_POST['votes'] ?? null) ? array_map(static fn ($v): string => is_string($v) ? trim($v) : '', $_POST['votes']) : [];
        if ($candidates === []) {
            $errors['form'] = 'Add at least one active candidate before saving a result.';
        }
        if ($errors === []) {
            try {
                $saved = result_save((int) $unit['id'], $checked['data'], $user, $intent === 'submit', is_string($_POST['correction_reason'] ?? null) ? $_POST['correction_reason'] : '');
                if ($saved['ok']) {
                    $warn = $checked['warnings'] !== [] ? ' Check before submitting: ' . implode(' ', $checked['warnings']) : '';
                    result_flash($warn === '' ? 'success' : 'warn', $saved['message'] . $warn);
                    result_redirect('results/entry.php?unit=' . (int) $unit['id']);
                }
                http_response_code(str_contains($saved['message'], 'no longer') ? 403 : 422);
                $errors['form'] = $saved['message'];
            } catch (PDOException $exception) {
                error_log($exception->getMessage());
                $errors['form'] = 'The result could not be saved. Try again.';
            }
        }
    }
}

$flash = result_take_flash();
$offlineAgent = $user['role'] === 'polling_agent' && submission_schema_ready();
$offlineFigures = $offlineAgent && $candidates !== [];
$offlineSheet = $user['role'] === 'polling_agent' && $canUpload && sheet_schema_ready();
render_shell_start('Result', 'results', $user);
?>
<nav class="pu-crumb" aria-label="Breadcrumb">
    <a href="<?= e(url(auth_dashboard_for($user['role']))) ?>">Workspace</a>
    <span aria-hidden="true">/</span>
    <a href="<?= e(url('results/index.php')) ?>">Results</a>
    <span aria-hidden="true">/</span>
    <span><?= e((string) $unit['unit_code']) ?></span>
</nav>
<header class="cms-page__header">
    <div>
        <h1 class="cms-page__title"><?= e((string) $unit['unit_code']) ?></h1>
        <p class="cms-page__subtitle"><?= e((string) $unit['name']) ?> · <?= e((string) $unit['ward_name']) ?></p>
    </div>
</header>
<?php if ($flash !== null): ?>
<p class="pu-alert pu-alert--<?= e($flash['kind']) ?>" role="<?= $flash['kind'] === 'error' ? 'alert' : 'status' ?>"><?= e($flash['message']) ?></p>
<?php endif; ?>
<?php if ($user['role'] === 'polling_agent' && submission_schema_ready()) { submission_sync_panel($user); } ?>
<?php if (($errors['form'] ?? '') !== ''): ?>
<p class="pu-alert pu-alert--error" role="alert"><?= e($errors['form']) ?></p>
<?php endif; ?>

<article class="cms-card">
    <div class="cms-card__body">
        <dl class="cms-meta-list">
            <div><dt>LGA</dt><dd><?= e((string) $unit['lga_name']) ?></dd></div>
            <div><dt>Assigned agent</dt><dd><?= e($unit['agent_name'] !== null ? (string) $unit['agent_name'] : '—') ?></dd></div>
            <div><dt>Sheet</dt><dd><?= $sheet === null ? 'Not entered' : ((string) $sheet['status'] === 'submitted' ? 'Submitted' : 'Draft') ?></dd></div>
        </dl>
        <?php if (!$canEdit): ?>
        <p class="pu-note"><?= (int) $unit['is_active'] !== 1
            ? 'This polling unit is inactive, so its result cannot be edited.'
            : 'This result is submitted and locked against agent edits. Only an administrator can correct it, with a recorded reason.' ?></p>
        <?php elseif ($isSubmitted): ?>
        <p class="pu-alert pu-alert--warn" role="status">This result is submitted and locked for agents. Saving a change here records a correction with your reason, and any comparison with the result sheet must be repeated.</p>
        <?php else: ?>
        <p class="pu-note">Save a draft at any time, even if some figures are still blank. Submitting requires every figure, checks that they add up, and then locks the result against agent edits.</p>
        <?php endif; ?>
        <?php if (sheet_is_reviewer($user) && $isSubmitted): ?>
        <p><a class="cms-btn cms-btn--ghost cms-btn--sm" href="<?= e(url('results/review.php?unit=' . (int) $unit['id'])) ?>">Compare with result sheet</a></p>
        <?php endif; ?>
        <?php if ($candidates === []): ?>
        <p class="pu-note">No active candidates are available yet.<?= result_is_manager($user) ? ' Add them from Candidates before entering scores.' : ' An administrator needs to add the candidates before scores can be saved.' ?></p>
        <?php endif; ?>
        <?php if ($offlineFigures): ?>
        <section id="em-offline" class="em-offline" hidden
            data-user-id="<?= e((string) $user['id']) ?>"
            data-unit-id="<?= e((string) $unit['id']) ?>"
            data-unit-code="<?= e((string) $unit['unit_code']) ?>"
            data-config-fingerprint="<?= e(submission_candidate_fingerprint($candidates)) ?>"
            data-candidates="<?= e(json_encode(array_map(static fn (array $c): int => (int) $c['id'], $candidates))) ?>"
            data-server-status="<?= e($sheet === null ? 'none' : (string) $sheet['status']) ?>"
            data-can-submit="<?= $canEdit && !$isSubmitted ? '1' : '0' ?>">
            <h3 class="cms-card__title" style="font-size:1rem;margin:0 0 0.5rem">A. Enter and submit numerical results</h3>
            <p class="em-offline__notice"><?= e(SUBMISSION_NOTICE) ?></p>
            <div data-em-status></div>
            <div class="pu-list" data-em-records aria-live="polite"></div>
            <?php if ($canEdit && !$isSubmitted): ?>
            <p class="pu-note em-offline__draft" data-em-draft-note aria-live="polite"></p>
            <?php endif; ?>
        </section>
        <?php endif; ?>
        <form method="post" action="<?= e(url('results/entry.php?unit=' . (int) $unit['id'])) ?>" data-pu-busy="Saving…"<?= $offlineFigures ? ' data-em-offline-form' : '' ?>>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="unit_id" value="<?= e((string) $unit['id']) ?>">
            <div class="pu-form-grid">
                <?php foreach (RESULT_FIGURE_FIELDS as $field => $label): ?>
                <div class="pu-field">
                    <label for="<?= e($field) ?>"><?= e($label) ?></label>
                    <input id="<?= e($field) ?>" name="<?= e($field) ?>" inputmode="numeric" pattern="[0-9]*" maxlength="6" value="<?= e((string) $values[$field]) ?>"<?= $canEdit ? '' : ' readonly' ?>>
                    <?php if (($errors[$field] ?? '') !== ''): ?><span class="pu-error"><?= e($errors[$field]) ?></span><?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php foreach ($candidates as $candidate): ?>
                <div class="pu-field">
                    <label for="votes-<?= e((string) $candidate['id']) ?>"><?= e($candidate['party']) ?> · <?= e($candidate['full_name']) ?></label>
                    <input id="votes-<?= e((string) $candidate['id']) ?>" name="votes[<?= e((string) $candidate['id']) ?>]" inputmode="numeric" pattern="[0-9]*" maxlength="6" value="<?= e((string) ($values['votes'][$candidate['id']] ?? '')) ?>"<?= $canEdit ? '' : ' readonly' ?>>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (($errors['votes'] ?? '') !== ''): ?><p class="pu-error"><?= e($errors['votes']) ?></p><?php endif; ?>
            <?php if ($canEdit && $isSubmitted): ?>
            <div class="pu-field">
                <label for="correction_reason">Reason for correcting a submitted result</label>
                <textarea id="correction_reason" name="correction_reason" minlength="5" maxlength="200" required></textarea>
            </div>
            <?php endif; ?>
            <?php if ($canEdit && $isSubmitted): ?>
            <div class="pu-form-actions">
                <button class="cms-btn cms-btn--primary" type="submit" name="intent" value="submit">Save correction</button>
                <a class="cms-btn cms-btn--ghost" href="<?= e(url('results/index.php')) ?>">Back</a>
            </div>
            <?php elseif ($canEdit): ?>
            <div class="pu-form-actions">
                <button class="cms-btn cms-btn--ghost" type="submit" name="intent" value="save" formnovalidate>Save draft</button>
                <button class="cms-btn cms-btn--primary" type="submit" name="intent" value="submit">Submit result</button>
                <a class="cms-btn cms-btn--ghost" href="<?= e(url('results/index.php')) ?>">Back</a>
            </div>
            <?php else: ?>
            <div class="pu-form-actions">
                <a class="cms-btn cms-btn--ghost" href="<?= e(url('results/index.php')) ?>">Back to results</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</article>
<?php if ($sheetError !== ''): ?>
<p class="pu-alert pu-alert--error" role="alert"><?= e($sheetError) ?></p>
<?php endif; ?>
<article class="cms-card">
    <div class="cms-card__head"><h2 class="cms-card__title">Result sheet</h2></div>
    <div class="cms-card__body">
        <?php if (!sheet_schema_ready()): ?>
        <p class="pu-note">Result sheet uploads are not available until the result-sheet migration is applied.</p>
        <?php else: ?>
        <?php
            $versions = array_values(array_filter(
                sheet_versions_for_unit((int) $unit['id']),
                static fn (array $version): bool => sheet_can_view($user, $version)
            ));
            $latest = $versions[0] ?? null;
            $latestCheck = is_array($latest) ? sheet_verify($latest, (int) $user['id']) : null;
            $hasVersions = sheet_versions_for_unit((int) $unit['id']) !== [];
        ?>
        <p class="pu-note">B. Submit result sheet only — no candidate scores required. Uploads are kept exactly as received with a SHA-256 fingerprint. A new upload never replaces or deletes an earlier version. Server receipt means the file is stored for review; it is not approval or an official INEC result.</p>
        <?php if (is_array($latest) && $latestCheck['deliverable'] && str_starts_with((string) $latest['mime'], 'image/')): ?>
        <img class="sheet-preview" src="<?= e(url('results/sheet-file.php?id=' . (int) $latest['id'])) ?>" alt="Uploaded result sheet for <?= e((string) $unit['unit_code']) ?>">
        <?php elseif (is_array($latest) && $latestCheck['deliverable']): ?>
        <p class="pu-note"><a href="<?= e(url('results/sheet-file.php?id=' . (int) $latest['id'])) ?>">Open the latest PDF sheet</a>.</p>
        <?php endif; ?>
        <?php if ($versions === []): ?>
        <p class="pu-note"><?= $hasVersions ? 'A result sheet is on file for this polling unit.' : 'No result sheet has been uploaded for this polling unit.' ?></p>
        <?php else: ?>
        <div class="pu-list">
            <?php foreach ($versions as $version): ?>
            <?php $vCheck = sheet_verify($version, (int) $user['id']); ?>
            <article class="pu-record">
                <div class="pu-record__id">
                    <strong><?= e($version['version_no'] !== null ? 'Version ' . (int) $version['version_no'] : 'Legacy upload') ?> · <?= e((string) ($version['upload_kind'] ?? 'legacy')) ?></strong>
                    <p><?= e(sheet_uploaded_at($version)) ?> · <?= e($version['uploaded_by_name'] !== null ? (string) $version['uploaded_by_name'] : '—') ?></p>
                    <span class="cms-badge <?= e(sheet_status_badge($vCheck['status'])) ?>"><?= e(sheet_status_label($vCheck['status'])) ?></span>
                    <span class="cms-badge cms-badge--muted">Review: <?= e(ucfirst((string) $version['review_status'])) ?></span>
                </div>
                <dl class="pu-record__meta">
                    <div><dt>SHA-256</dt><dd class="sheet-hash"><?= e((string) ($version['sha256'] ?? '') !== '' ? (string) $version['sha256'] : 'Not recorded') ?></dd></div>
                    <div><dt>Reason</dt><dd><?= e((string) ($version['upload_reason'] ?? '—')) ?></dd></div>
                </dl>
                <?php if (sheet_status_warning($vCheck['status']) !== '' && $vCheck['status'] !== SHEET_STATUS_VERIFIED): ?>
                <p class="pu-note"><?= e(sheet_status_warning($vCheck['status'])) ?></p>
                <?php endif; ?>
                <div class="pu-row-actions">
                    <?php if ($vCheck['deliverable']): ?>
                    <a class="cms-btn cms-btn--ghost cms-btn--sm" href="<?= e(url('results/sheet-file.php?id=' . (int) $version['id'])) ?>">Open file</a>
                    <?php endif; ?>
                    <?php if (sheet_is_reviewer($user)): ?>
                    <a class="cms-btn cms-btn--ghost cms-btn--sm" href="<?= e(url('results/sheet.php?id=' . (int) $version['id'])) ?>">Integrity and review</a>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($canUpload): ?>
        <div id="em-sheet-only"<?= $offlineSheet ? '' : ' hidden' ?>
            data-user-id="<?= e((string) $user['id']) ?>"
            data-unit-id="<?= e((string) $unit['id']) ?>"
            data-unit-code="<?= e((string) $unit['unit_code']) ?>"
            data-has-versions="<?= $hasVersions ? '1' : '0' ?>"
            data-max-bytes="<?= e((string) sheet_max_bytes()) ?>">
            <p class="pu-note" data-em-sheet-status aria-live="polite"></p>
            <div class="pu-list" data-em-sheet-records aria-live="polite"></div>
        </div>
        <form method="post" action="<?= e(url('results/entry.php?unit=' . (int) $unit['id'])) ?>" enctype="multipart/form-data" data-pu-busy="Uploading…" id="sheet-only"<?= $offlineSheet ? ' data-em-sheet-form' : '' ?>>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="intent" value="upload_sheet">
            <input type="hidden" name="unit_id" value="<?= e((string) $unit['id']) ?>">
            <div class="pu-field">
                <label for="result-sheet">Photo or PDF of the result sheet</label>
                <input id="result-sheet" type="file" name="result_sheet" accept="image/jpeg,image/png,image/webp,application/pdf" required>
            </div>
            <?php if ($hasVersions): ?>
            <div class="pu-field">
                <label for="upload-kind">This upload is a</label>
                <select id="upload-kind" name="upload_kind" required>
                    <?php foreach (SHEET_UPLOAD_KINDS as $kindValue => $kindLabel): ?>
                    <option value="<?= e($kindValue) ?>"><?= e($kindLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="pu-field">
                <label for="upload-reason">Why is this version being added?</label>
                <textarea id="upload-reason" name="upload_reason" minlength="5" maxlength="255" required></textarea>
            </div>
            <?php endif; ?>
            <div class="pu-form-actions">
                <button class="cms-btn cms-btn--primary" type="submit" name="em_sheet_action" value="queue"><?= $hasVersions ? 'Submit result sheet only (new version)' : 'Submit result sheet only' ?></button>
            </div>
            <p class="pu-note">While offline, the file is saved on this device and uploaded when connectivity returns. No numerical scores are required for this action.</p>
        </form>
        <?php elseif ((int) $unit['is_active'] !== 1): ?>
        <p class="pu-note">This polling unit is inactive, so a result sheet cannot be uploaded.</p>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</article>
<?php
result_form_script();
if ($user['role'] === 'polling_agent' && submission_schema_ready()): ?>
<script nonce="<?= e(app_csp_nonce()) ?>" src="<?= e(asset_url('assets/js/offline-store.js')) ?>"></script>
<script nonce="<?= e(app_csp_nonce()) ?>" src="<?= e(asset_url('assets/js/offline-sync.js')) ?>"></script>
<script nonce="<?= e(app_csp_nonce()) ?>" src="<?= e(asset_url('assets/js/offline-register.js')) ?>"></script>
<?php endif;
if ($offlineFigures): ?>
<script nonce="<?= e(app_csp_nonce()) ?>" src="<?= e(asset_url('assets/js/offline-entry.js')) ?>"></script>
<?php endif;
if ($offlineSheet): ?>
<script nonce="<?= e(app_csp_nonce()) ?>" src="<?= e(asset_url('assets/js/offline-sheet.js')) ?>"></script>
<?php endif;
render_shell_end();
