<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/auth.php';
auth_boot();

if (auth_user() !== null) {
    redirect_to(auth_dashboard_for(auth_user()['role']));
}

$rawToken = isset($_GET['t']) && is_string($_GET['t']) ? trim($_GET['t']) : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['t']) && is_string($_POST['t'])) {
    $rawToken = trim($_POST['t']);
}

$errors = [];
$peek = $rawToken !== '' ? auth_peek_password_setup_token($rawToken) : ['ok' => false, 'error' => 'Open the setup link from your email to continue.'];
$ready = $peek['ok'] === true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    $token = $_POST['csrf_token'] ?? null;
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['new_password_confirm'] ?? '';
    if (!is_string($token) || !csrf_verify($token)) {
        http_response_code(403);
        $errors['form'] = 'The form expired. Reload the page and try again.';
    } elseif (!is_string($new) || !is_string($confirm)) {
        $errors['form'] = 'Enter and confirm your new password.';
    } else {
        $result = auth_complete_password_setup($rawToken, $new, $confirm);
        if ($result['ok'] === true) {
            redirect_to('portal/login.php?setup=1');
        }
        $errors[$result['field']] = $result['error'];
        if (($result['field'] ?? '') === 'form') {
            $ready = false;
            $peek = ['ok' => false, 'error' => $result['error']];
        }
    }
}

$nonce = app_csp_nonce();
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Set your password · Election Monitor</title>
    <style nonce="<?= e($nonce) ?>">
        body { margin: 0; font-family: Georgia, "Times New Roman", serif; background: linear-gradient(160deg, #e8eef5 0%, #f7f4ef 55%, #eef2f6 100%); color: #1f2933; min-height: 100vh; }
        .wrap { max-width: 28rem; margin: 0 auto; padding: 3rem 1.25rem; }
        h1 { font-size: 1.75rem; margin: 0 0 0.5rem; color: #0e2a4a; }
        .sub { margin: 0 0 1.5rem; color: #52606d; line-height: 1.5; }
        .card { background: #fff; border: 1px solid #d5dde6; border-radius: 8px; padding: 1.25rem; }
        label { display: block; font-size: 0.9rem; margin-bottom: 0.35rem; color: #3e4c59; }
        input[type="password"] { width: 100%; box-sizing: border-box; padding: 0.65rem 0.75rem; border: 1px solid #cbd2d9; border-radius: 6px; font-size: 1rem; }
        .field { margin-bottom: 1rem; }
        .err { color: #9b1c1c; font-size: 0.875rem; display: block; margin-top: 0.35rem; }
        .alert { padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem; }
        .alert--error { background: #fde8e8; color: #9b1c1c; border: 1px solid #f5c2c2; }
        .note { font-size: 0.875rem; color: #52606d; margin: 0 0 1rem; }
        button { background: #1d6b45; color: #fff; border: 0; border-radius: 6px; padding: 0.7rem 1.25rem; font-size: 1rem; font-weight: 600; cursor: pointer; }
        a { color: #1d6b45; }
    </style>
</head>
<body>
<main class="wrap">
    <h1>Set your password</h1>
    <p class="sub">Choose a password for your polling agent account. This does not change your role or assigned polling units.</p>
    <?php if (!$ready): ?>
    <p class="alert alert--error" role="alert"><?= e((string) ($peek['error'] ?? 'This setup link is invalid or has expired.')) ?></p>
    <p class="note"><a href="<?= e(url('portal/login.php')) ?>" rel="noreferrer">Back to sign in</a>. Ask an administrator to resend the account email if you need a new link.</p>
    <?php else: ?>
    <?php if (($errors['form'] ?? '') !== ''): ?>
    <p class="alert alert--error" role="alert"><?= e($errors['form']) ?></p>
    <?php endif; ?>
    <div class="card">
        <p class="note">Account: <strong><?= e($peek['username']) ?></strong> (<?= e($peek['full_name']) ?>)</p>
        <form method="post" action="<?= e(url('portal/setup-password.php')) ?>" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="t" value="<?= e($rawToken) ?>">
            <input type="text" name="username" value="<?= e($peek['username']) ?>" autocomplete="username" hidden>
            <div class="field">
                <label for="new_password">New password</label>
                <input id="new_password" name="new_password" type="password" minlength="12" maxlength="128" required autocomplete="new-password">
                <?php if (($errors['new_password'] ?? '') !== ''): ?><span class="err"><?= e($errors['new_password']) ?></span><?php endif; ?>
            </div>
            <div class="field">
                <label for="new_password_confirm">Confirm new password</label>
                <input id="new_password_confirm" name="new_password_confirm" type="password" minlength="12" maxlength="128" required autocomplete="new-password">
                <?php if (($errors['new_password_confirm'] ?? '') !== ''): ?><span class="err"><?= e($errors['new_password_confirm']) ?></span><?php endif; ?>
            </div>
            <p class="note">Use at least 12 characters. After saving, sign in with this password. Queued offline results stay on your device until you sign in again.</p>
            <button type="submit">Save password</button>
        </form>
    </div>
    <?php endif; ?>
</main>
</body>
</html>
