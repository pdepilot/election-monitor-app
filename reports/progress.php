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
        report_send_progress_csv();
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        http_response_code(500);
        exit;
    }
}

try {
    $wards = report_progress();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    render_shell_start('Result progress', 'reports', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">Result progress could not be loaded. Try again.</p>';
    render_shell_end();
    exit;
}

render_shell_start('Result progress', 'reports', $user);
?>
<nav class="pu-crumb" aria-label="Breadcrumb">
    <a href="<?= e(url(auth_dashboard_for($user['role']))) ?>">Workspace</a>
    <span aria-hidden="true">/</span>
    <a href="<?= e(url('reports/index.php')) ?>">Reports</a>
    <span aria-hidden="true">/</span>
    <span>Result progress</span>
</nav>
<header class="cms-page__header">
    <div>
        <h1 class="cms-page__title">Result progress</h1>
        <p class="cms-page__subtitle">Submitted results are counted. A draft is not a submitted result.</p>
    </div>
    <div class="cms-page__actions">
        <a class="cms-btn cms-btn--primary" href="<?= e(url('reports/progress.php?format=csv')) ?>">Download CSV</a>
    </div>
</header>
<section class="pu-stack">
    <article class="cms-card">
        <div class="cms-card__head"><h2 class="cms-card__title"><?= e((string) count($wards)) ?> wards</h2></div>
        <div class="cms-card__body">
            <div class="pu-list">
                <?php foreach ($wards as $ward): ?>
                <article class="pu-record">
                    <div class="pu-record__id">
                        <strong><?= e((string) $ward['name']) ?></strong>
                        <p><?= e((string) $ward['submitted']) ?> of <?= e((string) $ward['active_units']) ?> active units submitted</p>
                    </div>
                    <dl class="pu-record__meta">
                        <div><dt>Polling units</dt><dd><?= e((string) $ward['units']) ?></dd></div>
                        <div><dt>Drafts</dt><dd><?= e((string) $ward['drafts']) ?></dd></div>
                        <div><dt>Missing</dt><dd><?= e((string) $ward['missing']) ?></dd></div>
                        <div><dt>Accredited</dt><dd><?= e((string) $ward['accredited']) ?></dd></div>
                        <div><dt>Rejected</dt><dd><?= e((string) $ward['rejected']) ?></dd></div>
                        <div><dt>Valid votes</dt><dd><?= e((string) $ward['valid_votes']) ?></dd></div>
                    </dl>
                </article>
                <?php endforeach; ?>
            </div>
            <div class="pu-form-actions">
                <a class="cms-btn cms-btn--ghost" href="<?= e(url('reports/index.php')) ?>">Back</a>
            </div>
        </div>
    </article>
</section>
<?php
render_shell_end();
