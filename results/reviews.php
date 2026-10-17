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
        sheet_log_denied($user, null, null, 'result review queue');
    }
    render_forbidden($user);
    exit;
}
if (!result_schema_ready() || !sheet_schema_ready() || !result_review_schema_ready()) {
    http_response_code(503);
    render_shell_start('Result review', 'reviews', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">Apply database/migration_result_review.sql, then reload this page.</p>';
    render_shell_end();
    exit;
}

$filter = (string) filter_input(INPUT_GET, 'state');
if ($filter !== 'all' && !isset(REVIEW_STATES[$filter])) {
    $filter = 'all';
}
try {
    $queue = review_queue($filter);
    $counts = array_fill_keys(array_keys(REVIEW_STATES), 0);
    foreach (review_states_by_unit() as $state) {
        $counts[$state['state']]++;
    }
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    render_shell_start('Result review', 'reviews', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">The review queue could not be loaded. Try again.</p>';
    render_shell_end();
    exit;
}

render_shell_start('Result review', 'reviews', $user);
?>
<header class="cms-page__header">
    <div>
        <h1 class="cms-page__title">Result review</h1>
        <p class="cms-page__subtitle">Compare each submitted result with its uploaded sheet. Open discrepancies block ward collation until a reviewer resolves them.</p>
    </div>
</header>
<nav class="pu-row-actions" aria-label="Review filter">
    <a class="cms-btn cms-btn--sm <?= $filter === 'all' ? 'cms-btn--primary' : 'cms-btn--ghost' ?>" href="<?= e(url('results/reviews.php?state=all')) ?>">All (<?= e((string) array_sum($counts)) ?>)</a>
    <?php foreach (REVIEW_STATES as $key => [$label]): ?>
    <a class="cms-btn cms-btn--sm <?= $filter === $key ? 'cms-btn--primary' : 'cms-btn--ghost' ?>" href="<?= e(url('results/reviews.php?state=' . $key)) ?>"><?= e($label) ?> (<?= e((string) $counts[$key]) ?>)</a>
    <?php endforeach; ?>
</nav>
<div class="pu-list">
    <?php if ($queue === []): ?>
    <p class="pu-note">No submitted results in this view.</p>
    <?php endif; ?>
    <?php foreach ($queue as $row): ?>
    <article class="pu-record">
        <div class="pu-record__id">
            <strong><?= e((string) $row['unit_code']) ?></strong>
            <p><?= e((string) $row['unit_name']) ?> · <?= e((string) $row['ward_name']) ?></p>
            <span class="cms-badge <?= e(REVIEW_STATES[$row['review_state']][1]) ?>"><?= e(REVIEW_STATES[$row['review_state']][0]) ?></span>
            <?php if ((int) $row['sheet_count'] === 0): ?><span class="cms-badge cms-badge--muted">No sheet uploaded</span><?php endif; ?>
        </div>
        <dl class="pu-record__meta">
            <div><dt>Open discrepancies</dt><dd><?= e((string) $row['open']) ?></dd></div>
            <div><dt>Submitted</dt><dd><?= e((string) ($row['submitted_at'] ?? '—')) ?></dd></div>
        </dl>
        <div class="pu-row-actions">
            <a class="cms-btn cms-btn--ghost cms-btn--sm" href="<?= e(url('results/review.php?unit=' . (int) $row['polling_unit_id'])) ?>">Compare with sheet</a>
        </div>
    </article>
    <?php endforeach; ?>
</div>
<?php
render_shell_end();
