<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/reports.php';

auth_boot();
$user = report_require_user();
if (!report_schema_ready()) {
    report_render_missing_schema($user);
    exit;
}
if ((string) ($_GET['format'] ?? '') === 'csv') {
    try {
        report_send_collation_csv();
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        http_response_code(500);
        exit;
    }
}

try {
    $report = report_collation();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    render_shell_start('Collation report', 'reports', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">The collation report could not be loaded. Try again.</p>';
    render_shell_end();
    exit;
}

render_shell_start('Collation report', 'reports', $user);
?>
<nav class="pu-crumb" aria-label="Breadcrumb">
    <a href="<?= e(url(auth_dashboard_for($user['role']))) ?>">Workspace</a>
    <span aria-hidden="true">/</span>
    <a href="<?= e(url('reports/index.php')) ?>">Reports</a>
    <span aria-hidden="true">/</span>
    <span>Collation</span>
</nav>
<header class="cms-page__header">
    <div>
        <h1 class="cms-page__title">Collation report</h1>
        <p class="cms-page__subtitle">A ward is collated only after someone signs it. This page does not sign a ward.</p>
    </div>
    <?php if ($report['available']): ?>
    <div class="cms-page__actions">
        <a class="cms-btn cms-btn--primary" href="<?= e(url('reports/collation.php?format=csv')) ?>">Download CSV</a>
    </div>
    <?php endif; ?>
</header>
<?php if (!$report['available']): ?>
<p class="pu-alert pu-alert--warn" role="status">The collation tables are not available yet.</p>
<?php else: ?>
<?php if ($report['stale'] > 0): ?>
<p class="pu-alert pu-alert--warn" role="status"><?= e((string) $report['stale']) ?> signed ward<?= $report['stale'] === 1 ? '' : 's' ?> no longer match the submitted results.</p>
<?php endif; ?>
<div class="pu-stats">
    <article class="cms-stat cms-card">
        <div class="cms-stat__value"><?= $report['ready'] ? 'Ready' : 'Waiting' ?></div>
        <div class="cms-stat__label">Constituency sign-off</div>
    </article>
    <article class="cms-stat cms-card">
        <div class="cms-stat__value"><?= is_array($report['constituency']) ? 'Signed' : 'Not signed' ?></div>
        <div class="cms-stat__label">Constituency total</div>
    </article>
</div>
<section class="pu-stack">
    <article class="cms-card">
        <div class="cms-card__head"><h2 class="cms-card__title">Wards</h2></div>
        <div class="cms-card__body">
            <div class="pu-list">
                <?php foreach ($report['wards'] as $ward): ?>
                <?php
                    $badge = $ward['state'] === 'Collated' ? 'cms-badge--ok' : 'cms-badge--warn';
                ?>
                <article class="pu-record">
                    <div class="pu-record__id">
                        <strong><?= e((string) $ward['name']) ?></strong>
                        <p><?= e((string) $ward['submitted']) ?> of <?= e((string) $ward['active_units']) ?> active units submitted</p>
                    </div>
                    <dl class="pu-record__meta">
                        <div><dt>Collation</dt><dd><span class="cms-badge <?= e($badge) ?>"><?= e((string) $ward['state']) ?></span></dd></div>
                        <div><dt>Missing</dt><dd><?= e((string) $ward['missing']) ?></dd></div>
                        <div><dt>Accredited</dt><dd><?= e((string) $ward['accredited_voters']) ?></dd></div>
                        <div><dt>Rejected</dt><dd><?= e((string) $ward['rejected_ballots']) ?></dd></div>
                    </dl>
                    <div class="pu-row-actions">
                        <a class="cms-btn cms-btn--ghost cms-btn--sm" href="<?= e(url('collation/ward.php?id=' . (int) $ward['id'])) ?>">Open ward</a>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php if (is_array($report['constituency'])): ?>
            <p class="pu-note">Constituency total signed<?= $report['constituency']['collated_by_name'] !== null ? ' by ' . e((string) $report['constituency']['collated_by_name']) : '' ?> on <?= e((string) $report['constituency']['collated_at']) ?>, covering <?= e((string) $report['constituency']['ward_count']) ?> wards.</p>
            <?php else: ?>
            <p class="pu-note">The constituency total has not been signed.</p>
            <?php endif; ?>
            <div class="pu-form-actions">
                <a class="cms-btn cms-btn--ghost" href="<?= e(url('reports/index.php')) ?>">Back</a>
            </div>
        </div>
    </article>
</section>
<?php endif; ?>
<?php
render_shell_end();
