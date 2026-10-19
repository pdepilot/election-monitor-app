<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/collation.php';

auth_boot();
$user = collation_require_user();
if (!collation_schema_ready()) {
    collation_render_missing_schema($user);
    exit;
}

$wardId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($wardId) || $wardId < 1) {
    $posted = filter_var($_POST['ward_id'] ?? '', FILTER_VALIDATE_INT);
    $wardId = is_int($posted) && $posted > 0 ? $posted : 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!collation_can_sign($user, $wardId > 0 ? $wardId : null)) {
        http_response_code(403);
        collation_flash('error', 'Your account cannot sign a collation.');
        collation_redirect('collation/ward.php?id=' . $wardId);
    }
    if (!csrf_verify(is_string($token) ? $token : null) || (string) ($_POST['intent'] ?? '') !== 'collate') {
        collation_flash('error', 'The form expired. Reload the page and try again.');
        collation_redirect($wardId > 0 ? 'collation/ward.php?id=' . $wardId : 'collation/index.php');
    }
    try {
        $result = collation_sign_ward($wardId, (int) $user['id'], $user);
        collation_flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Ward collation saved.' : $result['error']);
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        collation_flash('error', 'The ward could not be collated. Try again.');
    }
    collation_redirect('collation/ward.php?id=' . $wardId);
}

$ward = $wardId > 0 ? collation_ward($wardId, $user) : null;
if ($ward === null) {
    http_response_code(404);
    render_shell_start('Collation', 'collation', $user);
    ?>
    <header class="cms-page__header">
        <div>
            <h1 class="cms-page__title">Ward not found</h1>
            <p class="cms-page__subtitle">That ward is not in the register.</p>
        </div>
    </header>
    <p><a class="cms-btn cms-btn--primary" href="<?= e(url('collation/index.php')) ?>">Back to collation</a></p>
    <?php
    render_shell_end();
    exit;
}

try {
    $votes = collation_live_votes($wardId);
    $units = collation_ward_units($wardId);
    $openDiscrepancies = collation_open_discrepancies($wardId);
    $reviewStates = result_review_schema_ready() && sheet_schema_ready() ? review_states_by_unit() : [];
    $snapshot = $ward['collation_id'] === null ? [] : collation_snapshot_votes((int) $ward['collation_id']);
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    render_shell_start('Collation', 'collation', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">This ward could not be loaded. Try again.</p>';
    render_shell_end();
    exit;
}
$storedAccredited = null;
$storedRejected = null;
if ($ward['collation_id'] !== null) {
    $stored = db()->prepare('SELECT accredited_voters, rejected_ballots FROM ward_collations WHERE id = :id');
    $stored->bindValue(':id', (int) $ward['collation_id'], PDO::PARAM_INT);
    $stored->execute();
    $storedRow = $stored->fetch();
    if (is_array($storedRow)) {
        $storedAccredited = (int) $storedRow['accredited_voters'];
        $storedRejected = (int) $storedRow['rejected_ballots'];
    }
}
$stale = $ward['collation_id'] !== null && (
    collation_votes_differ($snapshot, $votes)
    || $storedAccredited !== $ward['accredited_voters']
    || $storedRejected !== $ward['rejected_ballots']
);
$valid = 0;
foreach ($votes as $vote) {
    $valid += $vote['votes'];
}
$flash = collation_take_flash();

render_shell_start($ward['name'], 'collation', $user);
?>
<nav class="pu-crumb" aria-label="Breadcrumb">
    <a href="<?= e(url(auth_dashboard_for($user['role']))) ?>">Workspace</a>
    <span aria-hidden="true">/</span>
    <a href="<?= e(url('collation/index.php')) ?>">Collation</a>
    <span aria-hidden="true">/</span>
    <span><?= e($ward['name']) ?></span>
</nav>
<header class="cms-page__header">
    <div>
        <h1 class="cms-page__title"><?= e($ward['name']) ?></h1>
        <p class="cms-page__subtitle">Provisional totals · <?= e((string) $ward['submitted']) ?> submitted of <?= e((string) $ward['active_units']) ?> active polling units.</p>
    </div>
</header>
<?php if ($flash !== null): ?>
<p class="pu-alert pu-alert--<?= e($flash['kind']) ?>" role="<?= $flash['kind'] === 'error' ? 'alert' : 'status' ?>"><?= e($flash['message']) ?></p>
<?php endif; ?>
<p class="pu-note">Provisional totals from submitted polling-unit figures entered by monitoring agents. These are not official INEC results.</p>
<?php if ($openDiscrepancies > 0): ?>
<p class="pu-alert pu-alert--error" role="alert"><?= e((string) $openDiscrepancies) ?> open discrepanc<?= $openDiscrepancies === 1 ? 'y' : 'ies' ?> between entered figures and result sheets in this ward. The ward cannot be collated until a reviewer resolves them.</p>
<?php endif; ?>
<?php if ($stale): ?>
<p class="pu-alert pu-alert--warn" role="status">Submitted results changed after this ward was collated. Recollate it before using the constituency total.</p>
<?php endif; ?>

<div class="pu-stats">
    <article class="cms-stat cms-card"><div class="cms-stat__value"><?= e((string) $ward['accredited_voters']) ?></div><div class="cms-stat__label">Accredited voters</div></article>
    <article class="cms-stat cms-card"><div class="cms-stat__value"><?= e((string) $valid) ?></div><div class="cms-stat__label">Valid votes</div></article>
    <article class="cms-stat cms-card"><div class="cms-stat__value"><?= e((string) $ward['rejected_ballots']) ?></div><div class="cms-stat__label">Rejected ballots</div></article>
    <article class="cms-stat cms-card"><div class="cms-stat__value"><?= e((string) $ward['missing']) ?></div><div class="cms-stat__label">Units still missing</div></article>
</div>

<section class="pu-stack">
    <article class="cms-card">
        <div class="cms-card__head"><h2 class="cms-card__title">Candidate totals</h2></div>
        <div class="cms-card__body">
            <?php if ($votes === []): ?>
            <p class="pu-note">No candidates are available, so there are no party scores to add up.</p>
            <?php else: ?>
            <div class="pu-list">
                <?php foreach ($votes as $vote): ?>
                <article class="pu-record">
                    <div class="pu-record__id">
                        <strong><?= e($vote['party']) ?></strong>
                        <p><?= e($vote['full_name']) ?></p>
                    </div>
                    <p class="cms-stat__value"><?= e((string) $vote['votes']) ?></p>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($ward['collation_id'] !== null): ?>
            <p class="pu-note">Collated<?= $ward['collated_by_name'] !== null ? ' by ' . e($ward['collated_by_name']) : '' ?><?= $ward['collated_at'] !== null ? ' on ' . e($ward['collated_at']) : '' ?>.</p>
            <?php endif; ?>
            <?php if (collation_can_sign($user, $wardId)): ?>
            <form method="post" action="<?= e(url('collation/ward.php?id=' . $wardId)) ?>" data-pu-busy="Saving…">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="intent" value="collate">
                <input type="hidden" name="ward_id" value="<?= e((string) $wardId) ?>">
                <div class="pu-form-actions">
                    <button class="cms-btn cms-btn--primary" type="submit"><?= $ward['collation_id'] === null ? 'Collate ward' : 'Recollate ward' ?></button>
                    <a class="cms-btn cms-btn--ghost" href="<?= e(url('collation/index.php')) ?>">Back</a>
                </div>
            </form>
            <?php else: ?>
            <div class="pu-form-actions"><a class="cms-btn cms-btn--ghost" href="<?= e(url('collation/index.php')) ?>">Back</a></div>
            <?php endif; ?>
        </div>
    </article>
    <article class="cms-card">
        <div class="cms-card__head"><h2 class="cms-card__title">Polling units</h2></div>
        <div class="cms-card__body">
            <div class="pu-list">
                <?php foreach ($units as $unit): ?>
                <?php
                    $status = $unit['status'] === null ? 'Not entered' : ((string) $unit['status'] === 'submitted' ? 'Submitted' : 'Draft');
                    $badge = (string) ($unit['status'] ?? '') === 'submitted' ? 'cms-badge--ok' : 'cms-badge--warn';
                ?>
                <article class="pu-record">
                    <div class="pu-record__id">
                        <strong><?= e((string) $unit['unit_code']) ?></strong>
                        <p><?= e((string) $unit['name']) ?></p>
                    </div>
                    <dl class="pu-record__meta">
                        <div><dt>Status</dt><dd><span class="cms-badge <?= e($badge) ?>"><?= e($status) ?></span><?php if ((int) $unit['is_active'] !== 1): ?> <span class="cms-badge cms-badge--off">Inactive</span><?php endif; ?><?php if (isset($reviewStates[(int) $unit['id']])): ?> <span class="cms-badge <?= e(REVIEW_STATES[$reviewStates[(int) $unit['id']]['state']][1]) ?>"><?= e(REVIEW_STATES[$reviewStates[(int) $unit['id']]['state']][0]) ?></span><?php endif; ?></dd></div>
                        <div><dt>Accredited</dt><dd><?= e($unit['accredited_voters'] === null ? '—' : (string) $unit['accredited_voters']) ?></dd></div>
                        <div><dt>Valid votes</dt><dd><?= e($unit['status'] === null ? '—' : (string) $unit['valid_votes']) ?></dd></div>
                        <div><dt>Rejected</dt><dd><?= e($unit['rejected_ballots'] === null ? '—' : (string) $unit['rejected_ballots']) ?></dd></div>
                    </dl>
                    <div class="pu-row-actions">
                        <a class="cms-btn cms-btn--ghost cms-btn--sm" href="<?= e(url('results/entry.php?unit=' . (int) $unit['id'])) ?>">Open result</a>
                        <?php if (review_can($user) && (string) ($unit['status'] ?? '') === 'submitted'): ?>
                        <a class="cms-btn cms-btn--ghost cms-btn--sm" href="<?= e(url('results/review.php?unit=' . (int) $unit['id'])) ?>">Compare with sheet</a>
                        <?php endif; ?>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        </div>
    </article>
</section>
<?php
collation_form_script();
render_shell_end();
