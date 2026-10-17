<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/result_sheets.php';

auth_boot();
$user = auth_user();
if ($user === null) {
    redirect_to('portal/login.php');
}
if (!in_array($user['role'], SHEET_READERS, true)) {
    render_forbidden($user);
    exit;
}
if (!sheet_schema_ready()) {
    render_shell_start('Live sheets', 'live', $user);
    ?>
    <header class="cms-page__header">
        <div>
            <h1 class="cms-page__title">Live sheets</h1>
            <p class="cms-page__subtitle">The result-sheet table is not available yet.</p>
        </div>
    </header>
    <article class="cms-card">
        <div class="cms-card__body">
            <p>Apply the result-sheet migrations, then reload this page. They do not remove polling units, results, or uploads.</p>
            <p class="cms-muted">mysql -u root election_monitor_app &lt; database/migration_result_sheets.sql</p>
            <p class="cms-muted">mysql -u root election_monitor_app &lt; database/migration_sheet_integrity.sql</p>
        </div>
    </article>
    <?php
    render_shell_end();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $id = filter_var($_POST['sheet_id'] ?? '', FILTER_VALIDATE_INT);
    if (!csrf_verify(is_string($token) ? $token : null) || (string) ($_POST['intent'] ?? '') !== 'hide' || !is_int($id)) {
        result_flash('error', 'The form expired. Reload the page and try again.');
    } else {
        try {
            $hidden = sheet_hide($id, (int) $user['id']);
            result_flash($hidden ? 'success' : 'error', $hidden ? 'The sheet was hidden from the live page.' : 'That sheet is no longer on the live page.');
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            result_flash('error', 'The sheet could not be hidden. Try again.');
        }
    }
    sheet_redirect('results/live.php');
}

try {
    $rows = [];
    foreach (sheet_recent() as $recent) {
        $rows[] = $recent + ['check' => sheet_public_row($recent, (int) $user['id'])];
    }
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    render_shell_start('Live sheets', 'live', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">Live sheets could not be loaded. Try again.</p>';
    render_shell_end();
    exit;
}
$latest = 0;
foreach ($rows as $row) {
    $latest = max($latest, (int) $row['id']);
}
$flash = result_take_flash();

render_shell_start('Live sheets', 'live', $user);
?>
<nav class="pu-crumb" aria-label="Breadcrumb">
    <a href="<?= e(url(auth_dashboard_for($user['role']))) ?>">Workspace</a>
    <span aria-hidden="true">/</span>
    <span>Live sheets</span>
</nav>
<header class="cms-page__header">
    <div>
        <h1 class="cms-page__title">Live sheets</h1>
        <p class="cms-page__subtitle">Result sheets uploaded by polling agents appear here. This page checks for new sheets every 5 seconds. These are monitoring uploads, not official INEC results.</p>
    </div>
</header>
<?php if ($flash !== null): ?>
<p class="pu-alert pu-alert--<?= e($flash['kind']) ?>" role="<?= $flash['kind'] === 'error' ? 'alert' : 'status' ?>"><?= e($flash['message']) ?></p>
<?php endif; ?>
<p class="pu-note" id="live-status" role="status">Watching for new result sheets.</p>
<div class="pu-list" id="live-board" data-latest="<?= e((string) $latest) ?>" data-feed="<?= e(url('results/live-feed.php')) ?>" data-file="<?= e(url('results/sheet-file.php')) ?>" data-detail="<?= e(url('results/sheet.php')) ?>" data-action="<?= e(url('results/live.php')) ?>" data-csrf="<?= e(csrf_token()) ?>">
    <?php if ($rows === []): ?>
    <p class="pu-note" id="live-empty">No result sheets have been uploaded yet.</p>
    <?php endif; ?>
    <?php foreach ($rows as $row): ?>
    <?php $check = $row['check']; ?>
    <article class="pu-record">
        <?php if ($check['deliverable'] && str_starts_with((string) $row['mime'], 'image/')): ?>
        <img class="sheet-preview" src="<?= e(url('results/sheet-file.php?id=' . (int) $row['id'])) ?>" alt="Result sheet for <?= e((string) $row['unit_code']) ?>">
        <?php endif; ?>
        <div class="pu-record__id">
            <strong><?= e((string) $row['unit_code']) ?> · <?= e((string) $check['version']) ?></strong>
            <p><?= e((string) $row['unit_name']) ?> · <?= e((string) $row['ward_name']) ?></p>
            <span class="cms-badge <?= e($check['integrity_ok'] ? 'cms-badge--ok' : ($check['deliverable'] ? 'cms-badge--warn' : 'cms-badge--off')) ?>"><?= e((string) $check['integrity']) ?></span>
        </div>
        <dl class="pu-record__meta">
            <div><dt>Uploaded by</dt><dd><?= e($row['uploaded_by_name'] !== null ? (string) $row['uploaded_by_name'] : '—') ?></dd></div>
            <div><dt>Time</dt><dd><?= e((string) $check['uploaded_at']) ?></dd></div>
            <div><dt>File</dt><dd><?= e((string) $row['original_name']) ?></dd></div>
            <div><dt>SHA-256</dt><dd class="sheet-hash"><?= e((string) $check['sha256'] !== '' ? (string) $check['sha256'] : 'Not recorded') ?></dd></div>
        </dl>
        <div class="pu-row-actions">
            <a class="cms-btn cms-btn--ghost cms-btn--sm" href="<?= e(url('results/sheet.php?id=' . (int) $row['id'])) ?>">Integrity and review</a>
            <?php if ($check['deliverable'] && (string) $row['mime'] === 'application/pdf'): ?>
            <a class="cms-btn cms-btn--ghost cms-btn--sm" href="<?= e(url('results/sheet-file.php?id=' . (int) $row['id'])) ?>">Open PDF</a>
            <?php endif; ?>
            <form method="post" action="<?= e(url('results/live.php')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="intent" value="hide">
                <input type="hidden" name="sheet_id" value="<?= e((string) $row['id']) ?>">
                <button class="cms-btn cms-btn--ghost cms-btn--sm" type="submit">Hide</button>
            </form>
        </div>
    </article>
    <?php endforeach; ?>
</div>
<?php
sheet_live_script();
render_shell_end();
