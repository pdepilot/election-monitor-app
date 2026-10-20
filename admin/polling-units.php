<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/polling_units.php';

auth_boot();
$user = pu_require_manager();

if (!pu_schema_ready()) {
    pu_render_missing_schema($user);
    exit;
}

$importError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_verify(is_string($token) ? $token : null)) {
        $importError = 'The form expired. Reload the page and try again.';
        http_response_code(403);
    } else {
        $intent = (string) ($_POST['intent'] ?? '');
        try {
            if ($intent === 'discard') {
                unset($_SESSION['pu_import_preview']);
                pu_flash('success', 'The import preview was discarded.');
                pu_redirect('admin/polling-units.php');
            }
            if ($intent === 'preview') {
                $contents = pu_uploaded_csv();
                if ($contents['error'] !== '') {
                    $importError = $contents['error'];
                } else {
                    $report = pu_import_contents($contents['contents'], false, null);
                    if (!$report['ok']) {
                        $importError = $report['message'];
                    } else {
                        $_SESSION['pu_import_preview'] = [
                            'token' => bin2hex(random_bytes(16)),
                            'expires' => time() + 1800,
                            'rows' => $report['insert_rows'],
                            'inserted' => $report['inserted'],
                            'updated' => 0,
                            'skipped' => $report['skipped'],
                            'failed' => $report['failed'],
                            'failures' => array_slice($report['failures'], 0, 40),
                            'skipped_rows' => array_slice($report['skipped_rows'], 0, 40),
                            'failure_total' => count($report['failures']),
                            'skip_total' => count($report['skipped_rows']),
                            'discrepancies' => $report['discrepancies'],
                            'projected' => true,
                        ];
                        pu_redirect('admin/polling-units.php?import=preview');
                    }
                }
            } elseif ($intent === 'commit') {
                $preview = $_SESSION['pu_import_preview'] ?? null;
                $posted = (string) ($_POST['import_token'] ?? '');
                if (
                    !is_array($preview)
                    || !is_string($preview['token'] ?? null)
                    || !hash_equals($preview['token'], $posted)
                    || (int) ($preview['expires'] ?? 0) < time()
                ) {
                    unset($_SESSION['pu_import_preview']);
                    pu_flash('error', 'The import preview expired. Upload the CSV again.');
                    pu_redirect('admin/polling-units.php');
                }
                $rows = is_array($preview['rows'] ?? null) ? $preview['rows'] : [];
                $result = pu_commit_rows($rows, (int) $user['id']);
                unset($_SESSION['pu_import_preview']);
                if (!$result['ok']) {
                    pu_flash('error', $result['message']);
                    pu_redirect('admin/polling-units.php');
                }
                $_SESSION['pu_import_result'] = $result;
                pu_flash('success', 'Import finished. Existing polling-unit IDs were left unchanged.');
                pu_redirect('admin/polling-units.php?import=done');
            }
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $importError = 'The polling-unit register could not be updated. Try again.';
        }
    }
}

$filters = pu_filters_from_query($_GET);
$_SESSION['pu_list_query'] = pu_filters_query($filters);

try {
    $summary = pu_summary();
    $wards = pu_ward_counts();
    $list = pu_list($filters);
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    render_shell_start('Polling Unit Management', 'polling-units', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">The polling-unit register could not be loaded. Try again.</p>';
    render_shell_end();
    exit;
}

$preview = null;
if ((string) ($_GET['import'] ?? '') === 'preview') {
    $stored = $_SESSION['pu_import_preview'] ?? null;
    if (is_array($stored) && (int) ($stored['expires'] ?? 0) >= time()) {
        $preview = $stored;
    } else {
        unset($_SESSION['pu_import_preview']);
        $importError = 'The import preview expired. Upload the CSV again.';
    }
}
$result = null;
if ((string) ($_GET['import'] ?? '') === 'done') {
    $stored = $_SESSION['pu_import_result'] ?? null;
    unset($_SESSION['pu_import_result']);
    if (is_array($stored)) {
        $result = $stored;
    }
}
$flash = pu_take_flash();

render_shell_start('Polling Unit Management', 'polling-units', $user);
?>
<nav class="pu-crumb" aria-label="Breadcrumb">
    <a href="<?= e(url('admin/dashboard.php')) ?>">Administration</a>
    <span aria-hidden="true">/</span>
    <span>Polling Unit Management</span>
</nav>
<header class="cms-page__header">
    <div>
        <h1 class="cms-page__title">Polling Unit Management</h1>
        <p class="cms-page__subtitle"><?= e((string) $summary['total_units']) ?> polling units across <?= e((string) $summary['total_wards']) ?> wards. <?= e(PU_CONSTITUENCY_LABEL) ?>, Imo State.</p>
    </div>
    <div class="cms-page__actions">
        <a class="cms-btn cms-btn--primary" href="<?= e(url('admin/polling-unit.php?mode=create')) ?>">Add polling unit</a>
    </div>
</header>

<?php if ($flash !== null): ?>
<p class="pu-alert pu-alert--<?= e($flash['kind']) ?>" role="<?= $flash['kind'] === 'error' ? 'alert' : 'status' ?>"><?= e($flash['message']) ?></p>
<?php endif; ?>
<?php if ($importError !== ''): ?>
<p class="pu-alert pu-alert--error" role="alert"><?= e($importError) ?></p>
<?php endif; ?>

<p class="pu-note"><?= e(PU_REGISTER_NOTE) ?></p>

<div class="pu-stats">
    <?php
    $cards = [
        ['Total polling units', $summary['total_units'], 'fa-building-columns', 'gold'],
        ['Total wards', $summary['total_wards'], 'fa-map', 'blue'],
        ['Existing polling units', $summary['existing_units'], 'fa-clipboard-check', 'green'],
        ['New polling units', $summary['new_units'], 'fa-plus', 'purple'],
        ['Assigned polling units', $summary['assigned_units'], 'fa-user-check', 'green'],
        ['Unassigned polling units', $summary['unassigned_units'], 'fa-user-clock', 'gold'],
    ];
    foreach ($cards as [$label, $value, $icon, $tone]):
    ?>
    <article class="cms-stat cms-card">
        <div class="cms-stat__top">
            <div class="cms-stat__icon cms-stat__icon--<?= e($tone) ?>"><i class="fas <?= e($icon) ?>" aria-hidden="true"></i></div>
        </div>
        <div class="cms-stat__value"><?= e((string) $value) ?></div>
        <div class="cms-stat__label"><?= e($label) ?></div>
    </article>
    <?php endforeach; ?>
</div>
<?php if ($summary['inactive_units'] > 0): ?>
<p class="pu-note"><?= e((string) $summary['inactive_units']) ?> inactive polling units remain in the register and are included in the totals.</p>
<?php endif; ?>

<section class="pu-stack">
    <article class="cms-card">
        <div class="cms-card__head"><h2 class="cms-card__title">Polling units in each ward</h2></div>
        <div class="cms-card__body">
            <div class="pu-ward-counts">
                <?php foreach ($wards as $ward): ?>
                <a class="pu-ward-count<?= $filters['ward'] === $ward['id'] ? ' is-current' : '' ?>" href="<?= e(url('admin/polling-units.php?ward=' . $ward['id'])) ?>">
                    <strong><?= e((string) $ward['units']) ?></strong>
                    <span><?= e($ward['name']) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </article>
</section>

<section class="pu-stack">
    <?php if ($preview !== null): ?>
    <article class="cms-card">
        <div class="cms-card__head"><h2 class="cms-card__title">Import preview</h2></div>
        <div class="cms-card__body">
            <p>Nothing has been written yet. Existing polling-unit IDs will be skipped, not updated.</p>
            <?php pu_render_counts($preview, true); ?>
            <?php
            $previewGaps = is_array($preview['discrepancies'] ?? null) ? $preview['discrepancies'] : [];
            $previewGapCount = 0;
            foreach ($previewGaps as $gap) {
                if ((int) ($gap['gap'] ?? 0) !== 0) {
                    $previewGapCount++;
                }
            }
            ?>
            <?php if ($previewGapCount > 0): ?>
            <p class="pu-note">After this preview, <?= e((string) $previewGapCount) ?> wards would still differ from the supplied targets. Missing units are not created.</p>
            <?php elseif ($previewGaps !== []): ?>
            <p class="pu-note">After this preview the ward counts would match the supplied targets. That is not an INEC verification.</p>
            <?php endif; ?>
            <?php if (($preview['rows'] ?? []) !== []): ?>
            <div class="pu-table-wrap">
                <table class="pu-table">
                    <thead>
                        <tr><th>Line</th><th>Polling Unit ID</th><th>Name</th><th>Ward</th><th>Classification</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($preview['rows'], 0, 15) as $row): ?>
                        <tr>
                            <td><?= e((string) ($row['line'] ?? '')) ?></td>
                            <td><?= e((string) ($row['unit_code'] ?? '')) ?></td>
                            <td><?= e((string) ($row['name'] ?? '')) ?></td>
                            <td><?= e((string) ($row['ward_name'] ?? '')) ?></td>
                            <td><?= e((string) ($row['classification'] ?? '')) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($preview['rows']) > 15): ?>
            <p class="pu-note">Showing the first 15 of <?= e((string) count($preview['rows'])) ?> rows that would be inserted.</p>
            <?php endif; ?>
            <?php endif; ?>
            <?php pu_render_issues($preview); ?>
            <div class="pu-form-actions">
                <?php if (($preview['rows'] ?? []) !== []): ?>
                <form method="post" action="<?= e(url('admin/polling-units.php')) ?>" data-pu-busy="Importing…">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="intent" value="commit">
                    <input type="hidden" name="import_token" value="<?= e((string) $preview['token']) ?>">
                    <button class="cms-btn cms-btn--primary" type="submit">Import new rows</button>
                </form>
                <?php endif; ?>
                <form method="post" action="<?= e(url('admin/polling-units.php')) ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="intent" value="discard">
                    <button class="cms-btn cms-btn--ghost" type="submit">Discard preview</button>
                </form>
            </div>
        </div>
    </article>
    <?php endif; ?>

    <?php if ($result !== null): ?>
    <article class="cms-card">
        <div class="cms-card__head"><h2 class="cms-card__title">Import result</h2></div>
        <div class="cms-card__body">
            <?php pu_render_counts($result, false); ?>
            <?php pu_render_issues($result); ?>
        </div>
    </article>
    <?php endif; ?>

    <article class="cms-card">
        <div class="cms-card__head"><h2 class="cms-card__title"><?= e((string) $list['total']) ?> polling unit<?= $list['total'] === 1 ? '' : 's' ?></h2></div>
        <div class="cms-card__body">
            <form class="pu-filters" method="get" action="<?= e(url('admin/polling-units.php')) ?>">
                <div class="pu-field">
                    <label for="pu-search">Search</label>
                    <input id="pu-search" type="search" name="q" value="<?= e($filters['q']) ?>" maxlength="100" placeholder="Polling-unit ID or name">
                </div>
                <div class="pu-field">
                    <label for="pu-ward">Ward</label>
                    <select id="pu-ward" name="ward">
                        <option value="">All wards</option>
                        <?php foreach ($wards as $ward): ?>
                        <option value="<?= e((string) $ward['id']) ?>"<?= $filters['ward'] === $ward['id'] ? ' selected' : '' ?>><?= e($ward['name']) ?> (<?= e((string) $ward['units']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pu-field">
                    <label for="pu-class">Classification</label>
                    <select id="pu-class" name="classification">
                        <option value="">All</option>
                        <?php foreach (PU_CLASSIFICATIONS as $classification): ?>
                        <option value="<?= e($classification) ?>"<?= $filters['classification'] === $classification ? ' selected' : '' ?>><?= e($classification) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pu-field">
                    <label for="pu-assignment">Assignment</label>
                    <select id="pu-assignment" name="assignment">
                        <option value="">All</option>
                        <option value="assigned"<?= $filters['assignment'] === 'assigned' ? ' selected' : '' ?>>Assigned</option>
                        <option value="unassigned"<?= $filters['assignment'] === 'unassigned' ? ' selected' : '' ?>>Unassigned</option>
                    </select>
                </div>
                <button class="cms-btn cms-btn--primary" type="submit">Apply</button>
            </form>

            <?php if ($list['total'] === 0): ?>
            <p class="pu-note"><?= $filters['q'] === '' && $filters['ward'] === 0 && $filters['classification'] === '' && $filters['assignment'] === ''
                ? 'No polling units are in the register yet. Import the official CSV when you have it. This page will not invent polling-unit names or IDs.'
                : 'No polling units match these filters.' ?></p>
            <?php else: ?>
            <div class="pu-list">
                <?php $serial = $list['from']; ?>
                <?php foreach ($list['rows'] as $row): ?>
                <article class="pu-record">
                    <div class="pu-record__head">
                        <span class="pu-record__sn"><?= e((string) $serial) ?></span>
                        <div class="pu-record__id">
                            <strong><?= e((string) $row['unit_code']) ?></strong>
                            <p><?= e((string) $row['name']) ?></p>
                        </div>
                    </div>
                    <dl class="pu-record__meta">
                        <div><dt>Ward</dt><dd><?= e((string) $row['ward_name']) ?></dd></div>
                        <div><dt>LGA</dt><dd><?= e((string) $row['lga_name']) ?></dd></div>
                        <div><dt>Classification</dt><dd><span class="cms-badge <?= $row['classification'] === 'NEW PU' ? 'cms-badge--warn' : 'cms-badge--ok' ?>"><?= e((string) $row['classification']) ?></span></dd></div>
                        <div><dt>Assigned agent</dt><dd><?php if ($row['agent_name'] === null): ?>—<?php else: ?><?= e((string) $row['agent_name']) ?><span class="cms-muted"><?= e((string) $row['agent_username']) ?></span><?php endif; ?></dd></div>
                        <div><dt>Assignment status</dt><dd>
                            <span class="cms-badge <?= $row['agent_user_id'] === null ? 'cms-badge--warn' : 'cms-badge--ok' ?>"><?= $row['agent_user_id'] === null ? 'Unassigned' : 'Assigned' ?></span>
                            <?php if ((int) $row['is_active'] !== 1): ?><span class="cms-badge cms-badge--off">Inactive</span><?php endif; ?>
                        </dd></div>
                    </dl>
                    <div class="pu-row-actions">
                        <a class="cms-btn cms-btn--ghost cms-btn--sm" href="<?= e(url('admin/polling-unit.php?id=' . (int) $row['id'])) ?>">View</a>
                        <a class="cms-btn cms-btn--ghost cms-btn--sm" href="<?= e(url('admin/polling-unit.php?id=' . (int) $row['id'] . '&mode=edit')) ?>">Edit</a>
                        <a class="cms-btn cms-btn--ghost cms-btn--sm" href="<?= e(url('admin/polling-unit.php?id=' . (int) $row['id'] . '&mode=assign')) ?>">Assign</a>
                    </div>
                </article>
                <?php $serial++; ?>
                <?php endforeach; ?>
            </div>
            <div class="pu-pager">
                <p class="pu-note">Showing <?= e((string) $list['from']) ?>–<?= e((string) $list['to']) ?> of <?= e((string) $list['total']) ?></p>
                <div class="pu-row-actions">
                    <?php if ($list['page'] > 1): ?>
                    <a class="cms-btn cms-btn--ghost cms-btn--sm" href="<?= e(url('admin/polling-units.php' . (($q = pu_filters_query($filters, $list['page'] - 1)) === '' ? '' : '?' . $q))) ?>">Previous</a>
                    <?php else: ?>
                    <span class="cms-btn cms-btn--ghost cms-btn--sm is-disabled">Previous</span>
                    <?php endif; ?>
                    <span>Page <?= e((string) $list['page']) ?> of <?= e((string) $list['pages']) ?></span>
                    <?php if ($list['page'] < $list['pages']): ?>
                    <a class="cms-btn cms-btn--ghost cms-btn--sm" href="<?= e(url('admin/polling-units.php?' . pu_filters_query($filters, $list['page'] + 1))) ?>">Next</a>
                    <?php else: ?>
                    <span class="cms-btn cms-btn--ghost cms-btn--sm is-disabled">Next</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </article>

    <article class="cms-card">
        <div class="cms-card__head"><h2 class="cms-card__title">Import a CSV register</h2></div>
        <div class="cms-card__body">
            <p>Use the official file from the constituency register. The preview checks every row before anything is saved. Import inserts new polling-unit IDs only and does not overwrite an existing record.</p>
            <p class="pu-note">Columns: polling_unit_id, polling_unit_name, ward_name, lga_name, classification. Classification must be EXISTING PU or NEW PU. LGA must be Ohaji/Egbema, Oguta, or Oru West and must match the ward. <a href="<?= e(url('database/polling_units_template.csv')) ?>">Download the CSV template</a>.</p>
            <form method="post" action="<?= e(url('admin/polling-units.php')) ?>" enctype="multipart/form-data" data-pu-busy="Checking file…">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="intent" value="preview">
                <div class="pu-field">
                    <label for="pu-csv">CSV file</label>
                    <input id="pu-csv" type="file" name="csv_file" accept=".csv,text/csv" required>
                </div>
                <div class="pu-form-actions">
                    <button class="cms-btn cms-btn--primary" type="submit">Preview import</button>
                </div>
            </form>
        </div>
    </article>
</section>
<?php
pu_form_script();
render_shell_end();

/**
 * @return array{error: string, contents: string}
 */
function pu_uploaded_csv(): array
{
    $file = $_FILES['csv_file'] ?? null;
    if (!is_array($file)) {
        return ['error' => 'Choose a CSV file.', 'contents' => ''];
    }
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return ['error' => 'Choose a CSV file.', 'contents' => ''];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return ['error' => 'The CSV file could not be uploaded.', 'contents' => ''];
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > PU_IMPORT_MAX_BYTES) {
        return ['error' => 'The CSV file must be between 1 byte and 2 MB.', 'contents' => ''];
    }
    $name = (string) ($file['name'] ?? '');
    if (!preg_match('/\.csv\z/i', $name)) {
        return ['error' => 'Upload a file that ends in .csv.', 'contents' => ''];
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['error' => 'The CSV file could not be uploaded.', 'contents' => ''];
    }
    $contents = file_get_contents($tmp);
    if (!is_string($contents)) {
        return ['error' => 'The CSV file could not be read.', 'contents' => ''];
    }

    return ['error' => '', 'contents' => $contents];
}

/**
 * @param array<string, mixed> $report
 */
function pu_render_counts(array $report, bool $projected): void
{
    $insertLabel = $projected ? 'Would insert' : 'Inserted';
    ?>
    <p>
        <?= e($insertLabel) ?>: <?= e((string) ($report['inserted'] ?? 0)) ?>.
        Updated: <?= e((string) ($report['updated'] ?? 0)) ?>.
        Skipped: <?= e((string) ($report['skipped'] ?? 0)) ?>.
        Failed: <?= e((string) ($report['failed'] ?? 0)) ?>.
    </p>
    <?php
}

/**
 * @param array<string, mixed> $report
 */
function pu_render_issues(array $report): void
{
    $failures = is_array($report['failures'] ?? null) ? $report['failures'] : [];
    $skipped = is_array($report['skipped_rows'] ?? null) ? $report['skipped_rows'] : [];
    if ($failures === [] && $skipped === []) {
        return;
    }
    ?>
    <div class="pu-table-wrap">
        <table class="pu-table">
            <thead>
                <tr><th>Line</th><th>Polling Unit ID</th><th>Result</th></tr>
            </thead>
            <tbody>
                <?php foreach ($failures as $issue): ?>
                <tr>
                    <td><?= e((string) ($issue['line'] ?? '')) ?></td>
                    <td><?= e((string) ($issue['polling_unit_id'] ?? '')) ?></td>
                    <td><?= e((string) ($issue['message'] ?? '')) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php foreach ($skipped as $issue): ?>
                <tr>
                    <td><?= e((string) ($issue['line'] ?? '')) ?></td>
                    <td><?= e((string) ($issue['polling_unit_id'] ?? '')) ?></td>
                    <td><?= e((string) ($issue['message'] ?? '')) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    $hidden = (int) ($report['failure_total'] ?? count($failures)) - count($failures);
    $hidden += (int) ($report['skip_total'] ?? count($skipped)) - count($skipped);
    if ($hidden > 0) {
        echo '<p class="pu-note">' . e((string) $hidden) . ' more rows are not shown.</p>';
    }
}
