<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/collation.php';

auth_boot();
$user = collation_require_user();
if (!collation_schema_ready()) {
    collation_render_missing_schema($user);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!collation_can_sign($user)) {
        http_response_code(403);
        collation_flash('error', 'Your account cannot sign a collation.');
        collation_redirect('collation/constituency.php');
    }
    if (!csrf_verify(is_string($token) ? $token : null) || (string) ($_POST['intent'] ?? '') !== 'collate') {
        collation_flash('error', 'The form expired. Reload the page and try again.');
        collation_redirect('collation/constituency.php');
    }
    try {
        $result = collation_sign_constituency((int) $user['id']);
        collation_flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Constituency collation saved.' : $result['error']);
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        collation_flash('error', 'The constituency total could not be saved. Try again.');
    }
    collation_redirect('collation/constituency.php');
}

try {
    $preview = collation_constituency_preview();
    $record = collation_constituency_record();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    render_shell_start('Constituency collation', 'collation', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">The constituency total could not be loaded. Try again.</p>';
    render_shell_end();
    exit;
}
$valid = 0;
foreach ($preview['votes'] as $vote) {
    $valid += $vote['votes'];
}
$flash = collation_take_flash();

render_shell_start('Constituency collation', 'collation', $user);
?>
<nav class="pu-crumb" aria-label="Breadcrumb">
    <a href="<?= e(url(auth_dashboard_for($user['role']))) ?>">Workspace</a>
    <span aria-hidden="true">/</span>
    <a href="<?= e(url('collation/index.php')) ?>">Collation</a>
    <span aria-hidden="true">/</span>
    <span>Ohaji/Egbema</span>
</nav>
<header class="cms-page__header">
    <div>
        <h1 class="cms-page__title">Ohaji/Egbema</h1>
        <p class="cms-page__subtitle">Provisional constituency total from the signed ward collations. It does not add unsigned wards and is not an official INEC result.</p>
    </div>
</header>
<?php if ($flash !== null): ?>
<p class="pu-alert pu-alert--<?= e($flash['kind']) ?>" role="<?= $flash['kind'] === 'error' ? 'alert' : 'status' ?>"><?= e($flash['message']) ?></p>
<?php endif; ?>
<?php if ($preview['discrepancies'] > 0): ?>
<p class="pu-alert pu-alert--error" role="alert"><?= e((string) $preview['discrepancies']) ?> open result-sheet discrepanc<?= $preview['discrepancies'] === 1 ? 'y blocks' : 'ies block' ?> sign-off of the constituency total.</p>
<?php endif; ?>
<?php if ($preview['stale'] > 0): ?>
<p class="pu-alert pu-alert--warn" role="status"><?= e((string) $preview['stale']) ?> collated ward<?= $preview['stale'] === 1 ? '' : 's' ?> no longer match the submitted results.</p>
<?php endif; ?>

<div class="pu-stats">
    <article class="cms-stat cms-card"><div class="cms-stat__value"><?= e((string) $preview['accredited']) ?></div><div class="cms-stat__label">Accredited voters</div></article>
    <article class="cms-stat cms-card"><div class="cms-stat__value"><?= e((string) $valid) ?></div><div class="cms-stat__label">Valid votes</div></article>
    <article class="cms-stat cms-card"><div class="cms-stat__value"><?= e((string) $preview['rejected']) ?></div><div class="cms-stat__label">Rejected ballots</div></article>
    <article class="cms-stat cms-card"><div class="cms-stat__value"><?= $preview['ready'] ? 'Ready' : 'Waiting' ?></div><div class="cms-stat__label">Sign-off</div></article>
</div>

<section class="pu-stack">
    <article class="cms-card">
        <div class="cms-card__head"><h2 class="cms-card__title">Candidate totals</h2></div>
        <div class="cms-card__body">
            <?php if ($preview['votes'] === []): ?>
            <p class="pu-note">No signed ward scores are available yet.</p>
            <?php else: ?>
            <div class="pu-list">
                <?php foreach ($preview['votes'] as $vote): ?>
                <article class="pu-record">
                    <div class="pu-record__id">
                        <strong><?= e($vote['party']) ?></strong>
                        <p><?= e($vote['full_name']) ?></p>
                    </div>
                    <p class="cms-stat__value"><?= e((string) $vote['votes']) ?></p>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if (is_array($record)): ?>
            <p class="pu-note">Last signed<?= $record['collated_by_name'] !== null ? ' by ' . e((string) $record['collated_by_name']) : '' ?> on <?= e((string) $record['collated_at']) ?>, covering <?= e((string) $record['ward_count']) ?> wards.</p>
            <?php endif; ?>
            <?php if (collation_can_sign($user)): ?>
            <form method="post" action="<?= e(url('collation/constituency.php')) ?>" data-pu-busy="Saving…">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="intent" value="collate">
                <div class="pu-form-actions">
                    <button class="cms-btn cms-btn--primary" type="submit"><?= is_array($record) ? 'Recollate constituency' : 'Collate constituency' ?></button>
                    <a class="cms-btn cms-btn--ghost" href="<?= e(url('collation/index.php')) ?>">Back</a>
                </div>
            </form>
            <?php else: ?>
            <div class="pu-form-actions"><a class="cms-btn cms-btn--ghost" href="<?= e(url('collation/index.php')) ?>">Back</a></div>
            <?php endif; ?>
        </div>
    </article>
    <article class="cms-card">
        <div class="cms-card__head"><h2 class="cms-card__title">Wards in this total</h2></div>
        <div class="cms-card__body">
            <div class="pu-list">
                <?php foreach ($preview['rows'] as $ward): ?>
                <?php if ($ward['active_units'] < 1) { continue; } ?>
                <article class="pu-record">
                    <div class="pu-record__id">
                        <strong><?= e($ward['name']) ?></strong>
                        <p><?= $ward['collation_id'] === null ? 'Not collated' : 'Collated' ?></p>
                    </div>
                    <div class="pu-row-actions">
                        <a class="cms-btn cms-btn--ghost cms-btn--sm" href="<?= e(url('collation/ward.php?id=' . $ward['id'])) ?>">Open ward</a>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        </div>
    </article>
</section>
<?php
collation_form_script();
render_shell_end();
