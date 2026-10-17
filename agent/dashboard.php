<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/agent_access.php';
require_once dirname(__DIR__) . '/includes/result_sheets.php';
require_once dirname(__DIR__) . '/includes/result_submissions.php';

auth_boot();
$user = agent_require();

try {
    $units = agent_assigned_units((int) $user['id']);
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    render_shell_start('My polling unit', 'dashboard', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">Your polling unit could not be loaded. Try again.</p>';
    render_shell_end();
    exit;
}

render_shell_start('My polling unit', 'dashboard', $user);
?>
<header class="cms-page__header">
    <div>
        <h1 class="cms-page__title"><?= e($user['full_name']) ?></h1>
        <p class="cms-page__subtitle">Your polling unit in Ohaji/Egbema, Imo State.</p>
    </div>
    <div class="cms-page__actions">
        <form method="post" action="<?= e(url('portal/logout.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button class="cms-btn cms-btn--ghost" type="submit">Log out</button>
        </form>
    </div>
</header>
<?php $offlineSync = submission_schema_ready(); ?>
<?php if ($offlineSync) { submission_sync_panel($user); } ?>
<?php if ($units === []): ?>
<article class="cms-card">
    <div class="cms-card__body">
        <p>No polling unit has been assigned to your account. Please contact an administrator.</p>
    </div>
</article>
<?php else: ?>
<section class="pu-stack">
    <?php foreach ($units as $unit): ?>
    <?php
        $status = $unit['status'] === null ? 'Not entered' : ((string) $unit['status'] === 'submitted' ? 'Submitted' : 'Draft');
        $badge = $unit['status'] === null ? 'cms-badge--warn' : ((string) $unit['status'] === 'submitted' ? 'cms-badge--ok' : 'cms-badge--warn');
        $action = $unit['result_id'] === null ? 'Enter result' : ((string) $unit['status'] === 'submitted' ? 'View submitted result' : 'Continue result');
        $scores = $unit['result_id'] === null ? [] : agent_result_scores((int) $unit['result_id']);
        $sheet = sheet_schema_ready() ? sheet_latest_for_unit((int) $unit['id']) : null;
    ?>
    <article class="cms-card">
        <div class="cms-card__head"><h2 class="cms-card__title"><?= e((string) $unit['unit_code']) ?></h2></div>
        <div class="cms-card__body">
            <dl class="pu-record__meta">
                <div><dt>Polling unit</dt><dd><?= e((string) $unit['name']) ?></dd></div>
                <div><dt>Ward</dt><dd><?= e((string) $unit['ward_name']) ?></dd></div>
                <div><dt>LGA</dt><dd><?= e((string) $unit['lga_name']) ?></dd></div>
                <div><dt>Result status</dt><dd><span class="cms-badge <?= e($badge) ?>"><?= e($status) ?></span></dd></div>
                <div><dt>Accredited voters</dt><dd><?= e($unit['accredited_voters'] === null ? '—' : (string) $unit['accredited_voters']) ?></dd></div>
                <div><dt>Valid votes</dt><dd><?= e($unit['result_id'] === null ? '—' : (string) $unit['valid_votes']) ?></dd></div>
                <div><dt>Rejected ballots</dt><dd><?= e($unit['rejected_ballots'] === null ? '—' : (string) $unit['rejected_ballots']) ?></dd></div>
                <div><dt>Submitted</dt><dd><?= e($unit['submitted_at'] !== null ? (string) $unit['submitted_at'] : '—') ?></dd></div>
                <div><dt>Last updated</dt><dd><?= e($unit['updated_at'] !== null ? (string) $unit['updated_at'] : '—') ?></dd></div>
                <div><dt>Result sheet</dt><dd><?= is_array($sheet) ? 'Uploaded ' . e((string) $sheet['uploaded_at']) : 'Not uploaded' ?></dd></div>
            </dl>
            <?php if ($scores !== []): ?>
            <div class="pu-list">
                <?php foreach ($scores as $score): ?>
                <article class="pu-record">
                    <div class="pu-record__id">
                        <strong><?= e($score['party']) ?></strong>
                        <p><?= e($score['full_name']) ?></p>
                    </div>
                    <p class="cms-stat__value"><?= e((string) $score['votes']) ?></p>
                </article>
                <?php endforeach; ?>
            </div>
            <?php elseif ($unit['result_id'] === null): ?>
            <p class="pu-note">No result has been entered for this polling unit.</p>
            <?php endif; ?>
            <div class="pu-form-actions">
                <a class="cms-btn cms-btn--primary" href="<?= e(url('results/entry.php?unit=' . (int) $unit['id'])) ?>"><?= e($action) ?></a>
            </div>
        </div>
    </article>
    <?php endforeach; ?>
</section>
<?php endif; ?>
<?php if ($offlineSync): ?>
<script nonce="<?= e(app_csp_nonce()) ?>" src="<?= e(asset_url('assets/js/offline-store.js')) ?>"></script>
<script nonce="<?= e(app_csp_nonce()) ?>" src="<?= e(asset_url('assets/js/offline-sync.js')) ?>"></script>
<script nonce="<?= e(app_csp_nonce()) ?>" src="<?= e(asset_url('assets/js/offline-register.js')) ?>"></script>
<?php endif; ?>
<?php
render_shell_end();
