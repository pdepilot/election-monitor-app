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
        report_send_scores_csv();
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        http_response_code(500);
        exit;
    }
}

try {
    $scores = report_scores();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    render_shell_start('Candidate scores', 'reports', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">Candidate scores could not be loaded. Try again.</p>';
    render_shell_end();
    exit;
}

render_shell_start('Candidate scores', 'reports', $user);
?>
<nav class="pu-crumb" aria-label="Breadcrumb">
    <a href="<?= e(url(auth_dashboard_for($user['role']))) ?>">Workspace</a>
    <span aria-hidden="true">/</span>
    <a href="<?= e(url('reports/index.php')) ?>">Reports</a>
    <span aria-hidden="true">/</span>
    <span>Candidate scores</span>
</nav>
<header class="cms-page__header">
    <div>
        <h1 class="cms-page__title">Candidate scores</h1>
        <p class="cms-page__subtitle">These totals add submitted polling-unit results. They are not the signed collation.</p>
    </div>
    <div class="cms-page__actions">
        <a class="cms-btn cms-btn--primary" href="<?= e(url('reports/scores.php?format=csv')) ?>">Download CSV</a>
    </div>
</header>
<section class="pu-stack">
    <article class="cms-card">
        <div class="cms-card__head"><h2 class="cms-card__title">Ohaji/Egbema</h2></div>
        <div class="cms-card__body">
            <?php if ($scores['candidates'] === []): ?>
            <p class="pu-note">No candidates are on the register, so this report has no party scores. Add candidates from Results. This report does not create them.</p>
            <?php else: ?>
            <div class="pu-list">
                <?php foreach ($scores['candidates'] as $candidate): ?>
                <article class="pu-record">
                    <div class="pu-record__id">
                        <strong><?= e($candidate['party']) ?></strong>
                        <p><?= e($candidate['full_name']) ?></p>
                    </div>
                    <p class="cms-stat__value"><?= e((string) ($scores['totals'][$candidate['id']] ?? 0)) ?></p>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </article>
    <?php if ($scores['candidates'] !== []): ?>
    <article class="cms-card">
        <div class="cms-card__head"><h2 class="cms-card__title">Scores by ward</h2></div>
        <div class="cms-card__body">
            <div class="pu-list">
                <?php foreach ($scores['wards'] as $ward): ?>
                <article class="pu-record">
                    <div class="pu-record__id"><strong><?= e($ward['name']) ?></strong></div>
                    <dl class="pu-record__meta">
                        <?php foreach ($scores['candidates'] as $candidate): ?>
                        <div>
                            <dt><?= e($candidate['party']) ?></dt>
                            <dd><?= e((string) ($ward['votes'][$candidate['id']] ?? 0)) ?></dd>
                        </div>
                        <?php endforeach; ?>
                    </dl>
                </article>
                <?php endforeach; ?>
            </div>
        </div>
    </article>
    <?php endif; ?>
    <div class="pu-form-actions">
        <a class="cms-btn cms-btn--ghost" href="<?= e(url('reports/index.php')) ?>">Back</a>
    </div>
</section>
<?php
render_shell_end();
