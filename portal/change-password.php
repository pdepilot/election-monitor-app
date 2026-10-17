<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/dashboard_view.php';

auth_boot();
$user = auth_user();
if ($user === null) {
    redirect_to('portal/login.php');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['new_password_confirm'] ?? '';
    if (!is_string($token) || !csrf_verify($token)) {
        http_response_code(403);
        $errors['form'] = 'The form expired. Reload the page and try again.';
    } elseif (!is_string($current) || !is_string($new) || !is_string($confirm)) {
        $errors['form'] = 'Enter your current password and a new password.';
    } else {
        $result = auth_change_password($user['id'], $current, $new, $confirm);
        if ($result['ok'] === true) {
            redirect_to(auth_dashboard_for($user['role']));
        }
        $errors[$result['field']] = $result['error'];
    }
}

$required = $user['must_change_password'];
render_shell_start('Change password', 'dashboard', $user);
?>
<header class="cms-page__header">
    <div>
        <h1 class="cms-page__title"><?= $required ? 'Choose your own password' : 'Change password' ?></h1>
        <p class="cms-page__subtitle"><?= $required
            ? 'Your account was set up with a temporary password from an administrator. Replace it before you continue.'
            : 'Other devices where you chose “remember me” will be signed out.' ?></p>
    </div>
</header>
<?php if (($errors['form'] ?? '') !== ''): ?>
<p class="pu-alert pu-alert--error" role="alert"><?= e($errors['form']) ?></p>
<?php elseif ($required): ?>
<p class="pu-alert pu-alert--warn" role="status">You must change your password before you can use the portal.</p>
<?php endif; ?>
<article class="cms-card">
    <div class="cms-card__body">
        <form method="post" action="<?= e(url('portal/change-password.php')) ?>" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="text" name="username" value="<?= e($user['username']) ?>" autocomplete="username" hidden>
            <div class="pu-form-grid">
                <div class="pu-field">
                    <label for="current_password"><?= $required ? 'Temporary password' : 'Current password' ?></label>
                    <input id="current_password" name="current_password" type="password" maxlength="128" required autocomplete="current-password">
                    <?php if (($errors['current_password'] ?? '') !== ''): ?><span class="pu-error"><?= e($errors['current_password']) ?></span><?php endif; ?>
                </div>
                <div class="pu-field">
                    <label for="new_password">New password</label>
                    <input id="new_password" name="new_password" type="password" minlength="12" maxlength="128" required autocomplete="new-password">
                    <?php if (($errors['new_password'] ?? '') !== ''): ?><span class="pu-error"><?= e($errors['new_password']) ?></span><?php endif; ?>
                </div>
                <div class="pu-field">
                    <label for="new_password_confirm">Confirm new password</label>
                    <input id="new_password_confirm" name="new_password_confirm" type="password" minlength="12" maxlength="128" required autocomplete="new-password">
                    <?php if (($errors['new_password_confirm'] ?? '') !== ''): ?><span class="pu-error"><?= e($errors['new_password_confirm']) ?></span><?php endif; ?>
                </div>
            </div>
            <p class="pu-note">Use at least 12 characters. Do not reuse the temporary password or share your password with anyone.</p>
            <div class="pu-form-actions">
                <button class="cms-btn cms-btn--primary" type="submit">Save new password</button>
            </div>
        </form>
    </div>
</article>
<?php
render_shell_end();
