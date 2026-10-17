<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/sheet_incidents.php';

auth_boot();
$user = auth_user();
if ($user === null) {
    redirect_to('portal/login.php');
}
if (!sheet_is_reviewer($user)) {
    if (sheet_schema_ready()) {
        sheet_log_denied($user, null, null, 'integrity incident list');
    }
    render_forbidden($user);
    exit;
}
if (!sheet_schema_ready()) {
    http_response_code(404);
    render_shell_start('Integrity incidents', 'incidents', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">Apply database/migration_sheet_integrity.sql, then reload this page.</p>';
    render_shell_end();
    exit;
}

$filter = (string) filter_input(INPUT_GET, 'status');
if (!in_array($filter, ['active', 'closed', 'all'], true)) {
    $filter = 'active';
}
try {
    $rows = [];
    foreach (sheet_incident_list($filter) as $incident) {
        $rows[] = $incident + ['auth' => sheet_incident_auth($incident, sheet_incident_events($incident))];
    }
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    render_shell_start('Integrity incidents', 'incidents', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">Incidents could not be loaded. Try again.</p>';
    render_shell_end();
    exit;
}
$flash = result_take_flash();
$filters = ['active' => 'Unresolved', 'closed' => 'Closed', 'all' => 'All'];

render_shell_start('Integrity incidents', 'incidents', $user);
?>
<header class="cms-page__header">
    <div>
        <h1 class="cms-page__title">Integrity incidents</h1>
        <p class="cms-page__subtitle">Result-sheet integrity failures detected by the system. Closing an incident records the investigation outcome; it never changes a sheet's integrity status or releases a withheld file.</p>
    </div>
</header>
<?php if ($flash !== null): ?>
<p class="pu-alert pu-alert--<?= e($flash['kind']) ?>" role="<?= $flash['kind'] === 'error' ? 'alert' : 'status' ?>"><?= e($flash['message']) ?></p>
<?php endif; ?>
<?php if (!sheet_signing_available()): ?>
<p class="pu-alert pu-alert--error" role="alert">The signing key is missing or invalid. Incidents cannot be authenticated and no action can be taken until it is restored.</p>
<?php endif; ?>
<nav class="pu-row-actions" aria-label="Incident filter">
    <?php foreach ($filters as $value => $label): ?>
    <a class="cms-btn cms-btn--sm <?= $filter === $value ? 'cms-btn--primary' : 'cms-btn--ghost' ?>" href="<?= e(url('results/incidents.php?status=' . $value)) ?>"<?= $filter === $value ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<div class="pu-list">
    <?php if ($rows === []): ?>
    <p class="pu-note">No incidents in this view.</p>
    <?php endif; ?>
    <?php foreach ($rows as $row): ?>
    <article class="pu-record">
        <div class="pu-record__id">
            <strong><a href="<?= e(url('results/incident.php?id=' . (int) $row['id'])) ?>">Incident #<?= e((string) $row['id']) ?></a> · <?= e((string) $row['unit_code']) ?></strong>
            <p><?= e(sheet_incident_type_label((string) $row['incident_type'])) ?></p>
            <span class="cms-badge <?= (string) $row['status'] === 'closed' ? 'cms-badge--muted' : 'cms-badge--off' ?>"><?= e(ucfirst((string) $row['status'])) ?></span>
            <span class="cms-badge <?= $row['auth']['state'] === 'authenticated' ? 'cms-badge--ok' : 'cms-badge--off' ?>"><?= e($row['auth']['label']) ?></span>
        </div>
        <dl class="pu-record__meta">
            <div><dt>Sheet</dt><dd><?= e($row['version_no'] !== null ? 'Version ' . (int) $row['version_no'] : 'Legacy upload') ?> (record <?= e((string) $row['upload_id']) ?>)</dd></div>
            <div><dt>Detected</dt><dd><?= e((string) $row['detected_at_utc']) ?> UTC</dd></div>
        </dl>
    </article>
    <?php endforeach; ?>
</div>
<?php
render_shell_end();
