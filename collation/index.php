<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/collation.php';

auth_boot();
$user = collation_require_user();
if (!collation_schema_ready()) {
    collation_render_missing_schema($user);
    exit;
}

try {
    $summary = collation_summary();
    $wards = collation_ward_rows();
    $reviewSummary = collation_review_summary();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    render_shell_start('Collation', 'collation', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">Collation could not be loaded. Try again.</p>';
    render_shell_end();
    exit;
}
$flash = collation_take_flash();

render_shell_start('Collation', 'collation', $user);
?>
<nav class="pu-crumb" aria-label="Breadcrumb">
    <a href="<?= e(url(auth_dashboard_for($user['role']))) ?>">Workspace</a>
    <span aria-hidden="true">/</span>
    <span>Collation</span>
</nav>
<header class="cms-page__header">
    <div>
        <h1 class="cms-page__title">Collation</h1>
        <p class="cms-page__subtitle">Provisional ward totals use submitted polling-unit results only. Drafts are left out. These are monitoring figures, not official INEC results.</p>
    </div>
    <div class="cms-page__actions">
        <a class="cms-btn cms-btn--primary" href="<?= e(url('collation/constituency.php')) ?>">Constituency total</a>
    </div>
</header>
<?php if ($flash !== null): ?>
<p class="pu-alert pu-alert--<?= e($flash['kind']) ?>" role="<?= $flash['kind'] === 'error' ? 'alert' : 'status' ?>"><?= e($flash['message']) ?></p>
<?php endif; ?>

<div class="pu-stats">
    <?php
    $cards = [
        ['Wards', $summary['wards'], 'fa-map'],
        ['Wards collated', $summary['collated'], 'fa-layer-group'],
        ['Results submitted', $summary['submitted'], 'fa-circle-check'],
        ['Still missing', $summary['missing'], 'fa-clock'],
    ];
    foreach ($cards as [$label, $value, $icon]):
    ?>
    <article class="cms-stat cms-card">
        <div class="cms-stat__top">
            <div class="cms-stat__icon cms-stat__icon--green"><i class="fas <?= e($icon) ?>" aria-hidden="true"></i></div>
        </div>
        <div class="cms-stat__value"><?= e((string) $value) ?></div>
        <div class="cms-stat__label"><?= e($label) ?></div>
    </article>
    <?php endforeach; ?>
</div>

<section class="pu-stack">
    <article class="cms-card">
        <div class="cms-card__head"><h2 class="cms-card__title">Wards</h2></div>
        <div class="cms-card__body">
            <?php if ($wards === []): ?>
            <p class="pu-note">No wards are loaded.</p>
            <?php else: ?>
            <div class="pu-list">
                <?php foreach ($wards as $ward): ?>
                <article class="pu-record">
                    <div class="pu-record__head">
                        <div class="pu-record__id">
                            <strong><?= e($ward['name']) ?></strong>
                            <p><?= e((string) $ward['submitted']) ?> of <?= e((string) $ward['active_units']) ?> active units submitted</p>
                        </div>
                    </div>
                    <dl class="pu-record__meta">
                        <div><dt>Missing</dt><dd><?= e((string) $ward['missing']) ?></dd></div>
                        <div><dt>Accredited</dt><dd><?= e((string) $ward['accredited_voters']) ?></dd></div>
                        <div><dt>Rejected</dt><dd><?= e((string) $ward['rejected_ballots']) ?></dd></div>
                        <?php $review = $reviewSummary[$ward['id']] ?? null; ?>
                        <div><dt>Sheet comparison</dt><dd><?php if ($review === null): ?>—<?php else: ?><?= e((string) ($review['match'] + $review['resolved'])) ?> checked<?php if ($review['unreviewed'] + $review['stale'] > 0): ?> · <?= e((string) ($review['unreviewed'] + $review['stale'])) ?> to compare<?php endif; ?><?php if ($review['open'] > 0): ?> · <span class="cms-badge cms-badge--off"><?= e((string) $review['open']) ?> open discrepanc<?= $review['open'] === 1 ? 'y' : 'ies' ?></span><?php endif; ?><?php endif; ?></dd></div>
                        <div><dt>Collation</dt><dd><span class="cms-badge <?= $ward['collation_id'] === null ? 'cms-badge--warn' : 'cms-badge--ok' ?>"><?= $ward['collation_id'] === null ? 'Open' : 'Collated' ?></span></dd></div>
                    </dl>
                    <div class="pu-row-actions">
                        <a class="cms-btn cms-btn--primary cms-btn--sm" href="<?= e(url('collation/ward.php?id=' . $ward['id'])) ?>">Open ward</a>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </article>
</section>
<?php
render_shell_end();
