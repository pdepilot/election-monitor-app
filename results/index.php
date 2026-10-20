<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/results.php';

auth_boot();
$user = result_require_user();
if ($user['role'] === 'polling_agent') {
    redirect_to('agent/dashboard.php');
}

if (!result_schema_ready()) {
    result_render_missing_schema($user);
    exit;
}

$filters = result_filters_from_query($_GET, $user);
try {
    $summary = $user['role'] === 'polling_agent'
        ? result_summary(null, (int) $user['id'])
        : result_summary($user);
    $wards = result_wards($user);
    $list = result_list($filters, $user);
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    render_shell_start('Results', 'results', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">Results could not be loaded. Try again.</p>';
    render_shell_end();
    exit;
}
$flash = result_take_flash();

render_shell_start('Results', 'results', $user);
?>
<nav class="pu-crumb" aria-label="Breadcrumb">
    <a href="<?= e(url(auth_dashboard_for($user['role']))) ?>">Workspace</a>
    <span aria-hidden="true">/</span>
    <span>Results</span>
</nav>
<header class="cms-page__header">
    <div>
        <h1 class="cms-page__title">Results</h1>
        <p class="cms-page__subtitle">Polling-unit scores for Ohaji/Egbema, Oguta, and Oru West.</p>
    </div>
    <?php if (result_is_manager($user)): ?>
    <div class="cms-page__actions">
        <a class="cms-btn cms-btn--primary" href="<?= e(url('results/candidates.php')) ?>">Candidates</a>
    </div>
    <?php endif; ?>
</header>
<?php if ($flash !== null): ?>
<p class="pu-alert pu-alert--<?= e($flash['kind']) ?>" role="<?= $flash['kind'] === 'error' ? 'alert' : 'status' ?>"><?= e($flash['message']) ?></p>
<?php endif; ?>

<div class="pu-stats">
    <?php
    $cards = [
        ['Polling units', $summary['units'], 'fa-building-columns'],
        ['Submitted', $summary['submitted'], 'fa-circle-check'],
        ['Drafts', $summary['drafts'], 'fa-pen'],
        ['Not entered', $summary['missing'], 'fa-clock'],
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
        <div class="cms-card__head"><h2 class="cms-card__title">Polling unit results</h2></div>
        <div class="cms-card__body">
            <form class="pu-filters" method="get" action="<?= e(url('results/index.php')) ?>">
                <div class="pu-field">
                    <label for="result-search">Search</label>
                    <input id="result-search" type="search" name="q" value="<?= e($filters['q']) ?>" maxlength="100" placeholder="Polling-unit ID or name">
                </div>
                <div class="pu-field">
                    <label for="result-ward">Ward</label>
                    <select id="result-ward" name="ward">
                        <option value="">All wards</option>
                        <?php foreach ($wards as $ward): ?>
                        <option value="<?= e((string) $ward['id']) ?>"<?= $filters['ward'] === $ward['id'] ? ' selected' : '' ?>><?= e($ward['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pu-field">
                    <label for="result-status">Status</label>
                    <select id="result-status" name="status">
                        <option value="">All</option>
                        <option value="submitted"<?= $filters['status'] === 'submitted' ? ' selected' : '' ?>>Submitted</option>
                        <option value="draft"<?= $filters['status'] === 'draft' ? ' selected' : '' ?>>Draft</option>
                        <option value="missing"<?= $filters['status'] === 'missing' ? ' selected' : '' ?>>Not entered</option>
                    </select>
                </div>
                <button class="cms-btn cms-btn--primary" type="submit">Apply</button>
            </form>

            <?php if ($list['total'] === 0): ?>
            <p class="pu-note"><?= $user['role'] === 'polling_agent'
                ? 'No polling unit is assigned to your account.'
                : 'No polling units match these filters.' ?></p>
            <?php else: ?>
            <div class="pu-list">
                <?php foreach ($list['rows'] as $row): ?>
                <?php
                    $status = $row['status'] === null ? 'Not entered' : ((string) $row['status'] === 'submitted' ? 'Submitted' : 'Draft');
                    $badge = $row['status'] === null ? 'cms-badge--warn' : ((string) $row['status'] === 'submitted' ? 'cms-badge--ok' : 'cms-badge--warn');
                ?>
                <article class="pu-record">
                    <div class="pu-record__head">
                        <div class="pu-record__id">
                            <strong><?= e((string) $row['unit_code']) ?></strong>
                            <p><?= e((string) $row['name']) ?></p>
                        </div>
                    </div>
                    <dl class="pu-record__meta">
                        <div><dt>Ward</dt><dd><?= e((string) $row['ward_name']) ?></dd></div>
                        <div><dt>Agent</dt><dd><?= e($row['agent_name'] !== null ? (string) $row['agent_name'] : '—') ?></dd></div>
                        <div><dt>Status</dt><dd><span class="cms-badge <?= e($badge) ?>"><?= e($status) ?></span><?php if ((int) $row['is_active'] !== 1): ?> <span class="cms-badge cms-badge--off">Inactive</span><?php endif; ?></dd></div>
                        <div><dt>Accredited</dt><dd><?= e($row['accredited_voters'] === null ? '—' : (string) $row['accredited_voters']) ?></dd></div>
                        <div><dt>Valid votes</dt><dd><?= e($row['result_id'] === null ? '—' : (string) $row['valid_votes']) ?></dd></div>
                        <div><dt>Rejected</dt><dd><?= e($row['rejected_ballots'] === null ? '—' : (string) $row['rejected_ballots']) ?></dd></div>
                    </dl>
                    <div class="pu-row-actions">
                        <a class="cms-btn cms-btn--primary cms-btn--sm" href="<?= e(url('results/entry.php?unit=' . (int) $row['id'])) ?>"><?= $row['result_id'] === null ? 'Enter result' : 'Open result' ?></a>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <div class="pu-pager">
                <p class="pu-note">Showing <?= e((string) $list['from']) ?>–<?= e((string) $list['to']) ?> of <?= e((string) $list['total']) ?></p>
                <div class="pu-row-actions">
                    <?php if ($list['page'] > 1): ?>
                    <a class="cms-btn cms-btn--ghost cms-btn--sm" href="<?= e(url('results/index.php' . (($previous = result_filters_query($filters, $list['page'] - 1)) === '' ? '' : '?' . $previous))) ?>">Previous</a>
                    <?php else: ?>
                    <span class="cms-btn cms-btn--ghost cms-btn--sm is-disabled">Previous</span>
                    <?php endif; ?>
                    <span>Page <?= e((string) $list['page']) ?> of <?= e((string) $list['pages']) ?></span>
                    <?php if ($list['page'] < $list['pages']): ?>
                    <a class="cms-btn cms-btn--ghost cms-btn--sm" href="<?= e(url('results/index.php?' . result_filters_query($filters, $list['page'] + 1))) ?>">Next</a>
                    <?php else: ?>
                    <span class="cms-btn cms-btn--ghost cms-btn--sm is-disabled">Next</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </article>
</section>
<?php
render_shell_end();
