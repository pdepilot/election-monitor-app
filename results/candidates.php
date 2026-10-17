<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/results.php';

auth_boot();
$user = result_require_user();
if (!result_is_manager($user)) {
    render_forbidden($user);
    exit;
}
if (!result_schema_ready()) {
    result_render_missing_schema($user);
    exit;
}

$errors = [];
$values = ['full_name' => '', 'party' => '', 'sort_order' => '0'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $intent = (string) ($_POST['intent'] ?? '');
    if (!csrf_verify(is_string($token) ? $token : null)) {
        http_response_code(403);
        $errors['form'] = 'The form expired. Reload the page and try again.';
    } else {
        try {
            if ($intent === 'add') {
                $checked = result_validate_candidate($_POST);
                $errors = $checked['errors'];
                $values = [
                    'full_name' => $checked['data']['full_name'],
                    'party' => $checked['data']['party'],
                    'sort_order' => (string) $checked['data']['sort_order'],
                ];
                if ($errors === []) {
                    result_add_candidate($checked['data'], (int) $user['id']);
                    result_flash('success', 'Candidate added.');
                    result_redirect('results/candidates.php');
                }
            } elseif ($intent === 'deactivate' || $intent === 'activate') {
                $candidateId = filter_var($_POST['candidate_id'] ?? '', FILTER_VALIDATE_INT);
                if (!is_int($candidateId) || $candidateId < 1 || !result_set_candidate_active($candidateId, $intent === 'activate', (int) $user['id'])) {
                    $errors['form'] = 'That candidate could not be updated.';
                } else {
                    result_flash('success', $intent === 'activate' ? 'Candidate activated.' : 'Candidate deactivated. Existing scores are kept.');
                    result_redirect('results/candidates.php');
                }
            } else {
                $errors['form'] = 'That action is not available.';
            }
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            if (($exception->errorInfo[0] ?? '') === '23000') {
                $errors['party'] = 'That party code is already in use.';
            } else {
                $errors['form'] = 'The candidate could not be saved. Try again.';
            }
        }
    }
}

try {
    $candidates = result_candidates(false);
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    render_shell_start('Candidates', 'results', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">Candidates could not be loaded. Try again.</p>';
    render_shell_end();
    exit;
}
$flash = result_take_flash();

render_shell_start('Candidates', 'results', $user);
?>
<nav class="pu-crumb" aria-label="Breadcrumb">
    <a href="<?= e(url('admin/dashboard.php')) ?>">Administration</a>
    <span aria-hidden="true">/</span>
    <a href="<?= e(url('results/index.php')) ?>">Results</a>
    <span aria-hidden="true">/</span>
    <span>Candidates</span>
</nav>
<header class="cms-page__header">
    <div>
        <h1 class="cms-page__title">Candidates</h1>
        <p class="cms-page__subtitle">Scores are entered against these candidates. No parties were added automatically.</p>
    </div>
</header>
<?php if ($flash !== null): ?>
<p class="pu-alert pu-alert--<?= e($flash['kind']) ?>" role="<?= $flash['kind'] === 'error' ? 'alert' : 'status' ?>"><?= e($flash['message']) ?></p>
<?php endif; ?>
<?php if (($errors['form'] ?? '') !== ''): ?>
<p class="pu-alert pu-alert--error" role="alert"><?= e($errors['form']) ?></p>
<?php endif; ?>

<section class="pu-stack">
    <article class="cms-card">
        <div class="cms-card__head"><h2 class="cms-card__title">Add a candidate</h2></div>
        <div class="cms-card__body">
            <form method="post" action="<?= e(url('results/candidates.php')) ?>" data-pu-busy="Saving…">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="intent" value="add">
                <div class="pu-form-grid">
                    <div class="pu-field">
                        <label for="full_name">Candidate name</label>
                        <input id="full_name" name="full_name" value="<?= e($values['full_name']) ?>" maxlength="150" required>
                        <?php if (($errors['full_name'] ?? '') !== ''): ?><span class="pu-error"><?= e($errors['full_name']) ?></span><?php endif; ?>
                    </div>
                    <div class="pu-field">
                        <label for="party">Party code</label>
                        <input id="party" name="party" value="<?= e($values['party']) ?>" maxlength="12" required autocapitalize="characters">
                        <?php if (($errors['party'] ?? '') !== ''): ?><span class="pu-error"><?= e($errors['party']) ?></span><?php endif; ?>
                    </div>
                    <div class="pu-field">
                        <label for="sort_order">Display order</label>
                        <input id="sort_order" name="sort_order" inputmode="numeric" value="<?= e($values['sort_order']) ?>" required>
                        <?php if (($errors['sort_order'] ?? '') !== ''): ?><span class="pu-error"><?= e($errors['sort_order']) ?></span><?php endif; ?>
                    </div>
                </div>
                <div class="pu-form-actions">
                    <button class="cms-btn cms-btn--primary" type="submit">Add candidate</button>
                </div>
            </form>
        </div>
    </article>
    <article class="cms-card">
        <div class="cms-card__head"><h2 class="cms-card__title">Current candidates</h2></div>
        <div class="cms-card__body">
            <?php if ($candidates === []): ?>
            <p class="pu-note">No candidates yet. Add the official candidates before agents enter scores.</p>
            <?php else: ?>
            <div class="pu-list">
                <?php foreach ($candidates as $candidate): ?>
                <article class="pu-record">
                    <div class="pu-record__head">
                        <div class="pu-record__id">
                            <strong><?= e($candidate['party']) ?></strong>
                            <p><?= e($candidate['full_name']) ?></p>
                        </div>
                    </div>
                    <dl class="pu-record__meta">
                        <div><dt>Order</dt><dd><?= e((string) $candidate['sort_order']) ?></dd></div>
                        <div><dt>Status</dt><dd><span class="cms-badge <?= $candidate['is_active'] === 1 ? 'cms-badge--ok' : 'cms-badge--off' ?>"><?= $candidate['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></dd></div>
                    </dl>
                    <form method="post" action="<?= e(url('results/candidates.php')) ?>">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="intent" value="<?= $candidate['is_active'] === 1 ? 'deactivate' : 'activate' ?>">
                        <input type="hidden" name="candidate_id" value="<?= e((string) $candidate['id']) ?>">
                        <button class="cms-btn <?= $candidate['is_active'] === 1 ? 'cms-btn--danger' : 'cms-btn--primary' ?>" type="submit"><?= $candidate['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                    </form>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </article>
</section>
<?php
result_form_script();
render_shell_end();
