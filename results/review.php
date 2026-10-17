<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/result_review.php';

auth_boot();
$user = auth_user();
if ($user === null) {
    redirect_to('portal/login.php');
}
if (!review_can($user)) {
    if (sheet_schema_ready()) {
        sheet_log_denied($user, null, null, 'result review page');
    }
    render_forbidden($user);
    exit;
}
$stop = static function (string $message, int $code) use ($user): never {
    http_response_code($code);
    render_shell_start('Result review', 'reviews', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">' . e($message) . '</p>';
    render_shell_end();
    exit;
};
if (!result_schema_ready() || !sheet_schema_ready() || !result_review_schema_ready()) {
    $stop('Apply database/migration_result_review.sql, then reload this page.', 503);
}

$rawUnit = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['unit_id'] ?? '') : ($_GET['unit'] ?? '');
$unitId = is_string($rawUnit) && preg_match('/\A[1-9][0-9]{0,9}\z/', $rawUnit) && (int) $rawUnit <= 2147483647 ? (int) $rawUnit : 0;
$unit = $unitId > 0 ? result_unit($unitId) : null;
if ($unit === null) {
    $stop('That polling unit was not found.', 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $intent = (string) ($_POST['intent'] ?? '');
    if (!csrf_verify(is_string($token) ? $token : null)) {
        result_flash('error', 'The form expired. Reload the page and try again.');
    } else {
        try {
            if ($intent === 'compare') {
                $outcome = review_record($user, $unitId, $_POST);
            } elseif ($intent === 'resolve') {
                $rawId = $_POST['discrepancy_id'] ?? '';
                $discrepancyId = is_string($rawId) && preg_match('/\A[1-9][0-9]{0,9}\z/', $rawId) ? (int) $rawId : 0;
                $outcome = review_resolve($user, $unitId, $discrepancyId, is_string($_POST['resolution_note'] ?? null) ? $_POST['resolution_note'] : '');
            } else {
                $outcome = ['ok' => false, 'message' => 'That action is not available.'];
            }
            result_flash($outcome['ok'] ? 'success' : 'error', $outcome['message']);
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            result_flash('error', 'The review could not be saved. Nothing was changed.');
        }
    }
    $back = 'results/review.php?unit=' . $unitId;
    $chosen = filter_var($_POST['upload_id'] ?? '', FILTER_VALIDATE_INT);
    result_redirect(is_int($chosen) && $chosen > 0 ? $back . '&sheet=' . $chosen : $back);
}

try {
    $result = result_sheet($unitId);
    $versions = sheet_versions_for_unit($unitId);
    $requested = filter_input(INPUT_GET, 'sheet', FILTER_VALIDATE_INT);
    $chosen = null;
    $checks = [];
    foreach ($versions as $version) {
        $checks[(int) $version['id']] = sheet_verify($version, (int) $user['id']);
        if ($chosen === null && (!is_int($requested) || $requested === (int) $version['id']) && $checks[(int) $version['id']]['deliverable']) {
            $chosen = $version;
        }
    }
    $candidates = result_candidates_for_sheet($result === null ? null : (int) $result['id']);
    $names = [];
    foreach ($candidates as $candidate) {
        $names[$candidate['id']] = $candidate['party'] . ' · ' . $candidate['full_name'];
    }
    $votes = $result === null ? [] : result_votes((int) $result['id']);
    $state = $result !== null && (string) $result['status'] === 'submitted' ? review_state($result) : null;
    $history = $result === null ? [] : review_history((int) $result['id']);
    $discrepancies = $result === null ? [] : review_discrepancies((int) $result['id']);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $stop('This result could not be loaded. Try again.', 500);
}
$flash = result_take_flash();

render_shell_start('Result review', 'reviews', $user);
?>
<nav class="pu-crumb" aria-label="Breadcrumb">
    <a href="<?= e(url('results/reviews.php')) ?>">Result review</a>
    <span aria-hidden="true">/</span>
    <span><?= e((string) $unit['unit_code']) ?></span>
</nav>
<header class="cms-page__header">
    <div>
        <h1 class="cms-page__title"><?= e((string) $unit['unit_code']) ?></h1>
        <p class="cms-page__subtitle"><?= e((string) $unit['name']) ?> · <?= e((string) $unit['ward_name']) ?></p>
    </div>
    <div class="cms-page__actions">
        <a class="cms-btn cms-btn--ghost" href="<?= e(url('results/entry.php?unit=' . $unitId)) ?>">Open result</a>
    </div>
</header>
<?php if ($flash !== null): ?>
<p class="pu-alert pu-alert--<?= e($flash['kind']) ?>" role="<?= $flash['kind'] === 'error' ? 'alert' : 'status' ?>"><?= e($flash['message']) ?></p>
<?php endif; ?>
<p class="pu-note">Provisional monitoring figures entered by the campaign's agents, compared against the sheet photo they uploaded. This is not an official INEC result.</p>

<?php if ($state === null): ?>
<p class="pu-alert pu-alert--warn" role="status">This polling unit has no submitted result yet, so there is nothing to compare.</p>
<?php else: ?>
<article class="cms-card">
    <div class="cms-card__head">
        <h2 class="cms-card__title">Compare with result sheet</h2>
        <span class="cms-badge <?= e(REVIEW_STATES[$state['state']][1]) ?>"><?= e(REVIEW_STATES[$state['state']][0]) ?></span>
    </div>
    <div class="cms-card__body">
        <?php if ($state['state'] === 'stale'): ?>
        <p class="pu-alert pu-alert--warn" role="status">The figures were corrected after the last comparison. Compare them again.</p>
        <?php endif; ?>
        <?php if ($versions !== []): ?>
        <nav class="pu-row-actions" aria-label="Sheet version">
            <?php foreach ($versions as $version): ?>
            <?php $vCheck = $checks[(int) $version['id']]; ?>
            <?php $label = ($version['version_no'] !== null ? 'Version ' . (int) $version['version_no'] : 'Legacy') . ' · ' . sheet_status_label($vCheck['status']); ?>
            <?php if ($vCheck['deliverable']): ?>
            <a class="cms-btn cms-btn--sm <?= $chosen !== null && (int) $chosen['id'] === (int) $version['id'] ? 'cms-btn--primary' : 'cms-btn--ghost' ?>" href="<?= e(url('results/review.php?unit=' . $unitId . '&sheet=' . (int) $version['id'])) ?>"><?= e($label) ?></a>
            <?php else: ?>
            <span class="cms-badge cms-badge--off"><?= e($label) ?> (withheld)</span>
            <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
        <?php if ($chosen === null): ?>
        <p class="pu-alert pu-alert--error" role="alert"><?= $versions === [] ? 'No result sheet has been uploaded for this polling unit, so the figures cannot be compared yet.' : 'No uploaded sheet for this polling unit passes its integrity check, so the figures cannot be compared.' ?></p>
        <?php else: ?>
        <?php if (str_starts_with((string) $chosen['mime'], 'image/')): ?>
        <img class="sheet-preview" src="<?= e(url('results/sheet-file.php?id=' . (int) $chosen['id'])) ?>" alt="Result sheet for <?= e((string) $unit['unit_code']) ?>">
        <?php else: ?>
        <p><a class="cms-btn cms-btn--ghost cms-btn--sm" href="<?= e(url('results/sheet-file.php?id=' . (int) $chosen['id'])) ?>" target="_blank" rel="noopener">Open PDF sheet</a></p>
        <?php endif; ?>
        <?php if ($checks[(int) $chosen['id']]['status'] === SHEET_STATUS_LEGACY): ?>
        <p class="pu-alert pu-alert--warn" role="status"><?= e(sheet_status_warning(SHEET_STATUS_LEGACY)) ?></p>
        <?php endif; ?>
        <form method="post" action="<?= e(url('results/review.php')) ?>" data-pu-busy="Saving…">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="intent" value="compare">
            <input type="hidden" name="unit_id" value="<?= e((string) $unitId) ?>">
            <input type="hidden" name="upload_id" value="<?= e((string) $chosen['id']) ?>">
            <input type="hidden" name="figures_sha256" value="<?= e($state['current_sha']) ?>">
            <p class="pu-note">Type each figure exactly as it appears on the sheet. Leave a box blank only if the figure cannot be read; it will be recorded as a discrepancy.</p>
            <div class="pu-form-grid">
                <?php foreach (array_keys(RESULT_FIGURE_FIELDS) as $field): ?>
                <div class="pu-field">
                    <label for="sheet-<?= e($field) ?>"><?= e(review_field_label($field, null, $names)) ?> — entered: <strong><?= e($result[$field] === null ? '—' : (string) $result[$field]) ?></strong></label>
                    <input id="sheet-<?= e($field) ?>" name="sheet[<?= e($field) ?>]" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="On the sheet">
                </div>
                <?php endforeach; ?>
                <?php foreach ($votes as $candidateId => $count): ?>
                <div class="pu-field">
                    <label for="sheet-vote-<?= e((string) $candidateId) ?>"><?= e(review_field_label('votes', (int) $candidateId, $names)) ?> — entered: <strong><?= e($count === null ? '—' : (string) $count) ?></strong></label>
                    <input id="sheet-vote-<?= e((string) $candidateId) ?>" name="sheet[votes][<?= e((string) $candidateId) ?>]" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="On the sheet">
                </div>
                <?php endforeach; ?>
            </div>
            <div class="pu-field">
                <label for="review-note">Note (required when anything differs)</label>
                <textarea id="review-note" name="note" maxlength="255"></textarea>
            </div>
            <div class="pu-form-actions">
                <button class="cms-btn cms-btn--primary" type="submit">Record comparison</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</article>
<?php endif; ?>

<article class="cms-card">
    <div class="cms-card__head"><h2 class="cms-card__title">Discrepancies</h2></div>
    <div class="cms-card__body pu-list">
        <?php if ($discrepancies === []): ?>
        <p class="pu-note">No discrepancies recorded.</p>
        <?php endif; ?>
        <?php foreach ($discrepancies as $item): ?>
        <article class="pu-record">
            <div class="pu-record__id">
                <strong><?= e(review_field_label((string) $item['field'], $item['candidate_id'] !== null ? (int) $item['candidate_id'] : null, $names)) ?></strong>
                <p>Entered <?= e($item['entered_value'] === null ? '—' : (string) $item['entered_value']) ?> · on sheet <?= e($item['sheet_value'] === null ? 'unreadable' : (string) $item['sheet_value']) ?> · review <?= e((string) $item['review_id']) ?></p>
                <span class="cms-badge <?= (string) $item['status'] === 'open' ? 'cms-badge--off' : 'cms-badge--ok' ?>"><?= e(ucfirst((string) $item['status'])) ?></span>
            </div>
            <?php if ((string) $item['status'] === 'resolved'): ?>
            <dl class="pu-record__meta">
                <div><dt>Resolved</dt><dd><?= e((string) $item['resolved_at_utc']) ?> UTC · <?= e($item['resolved_by_name'] !== null ? (string) $item['resolved_by_name'] : '—') ?></dd></div>
                <div><dt>Explanation</dt><dd><?= e((string) $item['resolution_note']) ?></dd></div>
            </dl>
            <?php else: ?>
            <form method="post" action="<?= e(url('results/review.php')) ?>" data-pu-busy="Saving…">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="intent" value="resolve">
                <input type="hidden" name="unit_id" value="<?= e((string) $unitId) ?>">
                <input type="hidden" name="discrepancy_id" value="<?= e((string) $item['id']) ?>">
                <div class="pu-field">
                    <label for="resolve-<?= e((string) $item['id']) ?>">How was this resolved?</label>
                    <textarea id="resolve-<?= e((string) $item['id']) ?>" name="resolution_note" minlength="10" maxlength="200" required></textarea>
                </div>
                <div class="pu-form-actions">
                    <button class="cms-btn cms-btn--ghost cms-btn--sm" type="submit">Resolve</button>
                </div>
            </form>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    </div>
</article>

<article class="cms-card">
    <div class="cms-card__head"><h2 class="cms-card__title">Comparison history</h2></div>
    <div class="cms-card__body pu-list">
        <?php if ($history === []): ?>
        <p class="pu-note">This result has not been compared with a sheet yet.</p>
        <?php endif; ?>
        <?php foreach ($history as $review): ?>
        <article class="pu-record">
            <div class="pu-record__id">
                <strong><?= (string) $review['outcome'] === 'match' ? 'All figures matched' : 'Discrepancies found' ?></strong>
                <p><?= e((string) $review['reviewed_at_utc']) ?> UTC · <?= e($review['reviewer_name'] !== null ? (string) $review['reviewer_name'] : '—') ?> · <?= e($review['version_no'] !== null ? 'sheet version ' . (int) $review['version_no'] : 'sheet record ' . (int) $review['upload_id']) ?></p>
                <?php if ($state !== null && !hash_equals((string) $review['figures_sha256'], $state['current_sha'])): ?>
                <span class="cms-badge cms-badge--muted">Figures changed since</span>
                <?php endif; ?>
            </div>
            <?php if ((string) ($review['note'] ?? '') !== ''): ?>
            <dl class="pu-record__meta"><div><dt>Note</dt><dd><?= e((string) $review['note']) ?></dd></div></dl>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    </div>
</article>
<?php
result_form_script();
render_shell_end();
