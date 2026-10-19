<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/reports.php';

auth_boot();
$user = report_require_user();
if (!report_schema_ready()) {
    report_render_missing_schema($user);
    exit;
}

try {
    $overview = report_overview($user);
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    render_shell_start('Reports', 'reports', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">Reports could not be loaded. Try again.</p>';
    render_shell_end();
    exit;
}

render_shell_start('Reports', 'reports', $user);
?>
<nav class="pu-crumb" aria-label="Breadcrumb">
    <a href="<?= e(url(auth_dashboard_for($user['role']))) ?>">Workspace</a>
    <span aria-hidden="true">/</span>
    <span>Reports</span>
</nav>
<header class="cms-page__header">
    <div>
        <h1 class="cms-page__title">Reports</h1>
        <p class="cms-page__subtitle">Ohaji/Egbema figures from saved polling units and submitted results. Drafts are left out of the scores.</p>
    </div>
</header>

<div class="pu-stats">
    <?php
    $cards = [
        ['Polling units', $overview['units'], 'fa-building-columns'],
        ['Results submitted', $overview['submitted'], 'fa-circle-check'],
        ['Drafts', $overview['drafts'], 'fa-pen'],
        ['Still missing', $overview['missing'], 'fa-clock'],
        ['Accredited voters', $overview['accredited'], 'fa-user-check'],
        ['Valid votes', $overview['valid_votes'], 'fa-square-poll-vertical'],
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
        <div class="cms-card__head"><h2 class="cms-card__title">Open a report</h2></div>
        <div class="cms-card__body">
            <div class="pu-list">
                <article class="pu-record">
                    <div class="pu-record__id">
                        <strong>Result progress</strong>
                        <p><?= e((string) $overview['submitted']) ?> submitted, <?= e((string) $overview['drafts']) ?> drafts, and <?= e((string) $overview['missing']) ?> active units still missing.</p>
                    </div>
                    <div class="pu-row-actions">
                        <a class="cms-btn cms-btn--primary cms-btn--sm" href="<?= e(url('reports/progress.php')) ?>">Open progress</a>
                    </div>
                </article>
                <article class="pu-record">
                    <div class="pu-record__id">
                        <strong>Candidate scores</strong>
                        <p><?= $overview['candidates'] === 0 ? 'No candidates have been added, so there are no party scores yet.' : e((string) $overview['candidates']) . ' active candidates. Scores use submitted results only.' ?></p>
                    </div>
                    <div class="pu-row-actions">
                        <a class="cms-btn cms-btn--primary cms-btn--sm" href="<?= e(url('reports/scores.php')) ?>">Open scores</a>
                    </div>
                </article>
                <article class="pu-record">
                    <div class="pu-record__id">
                        <strong>Collation</strong>
                        <p><?= $overview['collation_ready'] ? 'Ward sign-off and the constituency total.' : 'The collation tables are not available yet.' ?></p>
                    </div>
                    <div class="pu-row-actions">
                        <a class="cms-btn cms-btn--primary cms-btn--sm" href="<?= e(url('reports/collation.php')) ?>">Open collation report</a>
                    </div>
                </article>
            </div>
        </div>
    </article>
</section>
<?php
render_shell_end();
