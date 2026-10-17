<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/dashboard_view.php';

auth_boot();

if (auth_user() === null) {
    redirect_to('portal/login.php');
}

$token = $_POST['csrf_token'] ?? null;
$invalidPost = $_SERVER['REQUEST_METHOD'] === 'POST' && (!is_string($token) || !csrf_verify($token));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$invalidPost) {
    $user = auth_user();
    if ($user !== null && auth_is_idle_role($user['role']) && isset($_POST['idle']) && (string) $_POST['idle'] === '1') {
        try {
            db()->prepare('DELETE FROM remember_tokens WHERE user_id = :user_id')->execute(['user_id' => (int) $user['id']]);
        } catch (Throwable $e) {
            error_log('Idle logout remember cleanup failed: ' . $e->getMessage());
        }
    }
    auth_logout();
    if (isset($_POST['idle']) && (string) $_POST['idle'] === '1') {
        header('Location: ' . url('portal/login.php') . '?idle=1', true, 302);
        exit;
    }
    redirect_to('portal/login.php');
}

if ($invalidPost) {
    http_response_code(403);
}

$user = auth_user();
if ($user === null) {
    redirect_to('portal/login.php');
}

render_shell_start('Sign out', 'dashboard', $user);
?>
<header class="cms-page__header">
    <div>
        <h1 class="cms-page__title">Sign out</h1>
        <p class="cms-page__subtitle">This ends your session on this device.</p>
    </div>
</header>
<article class="cms-card">
    <div class="cms-card__body">
        <?php if ($invalidPost): ?>
            <p role="alert">Your session expired before sign-out completed. Try again.</p>
        <?php else: ?>
            <p class="cms-muted" style="margin-top:0">You can also sign out from the profile menu.</p>
        <?php endif; ?>
        <form method="post" action="<?= e(url('portal/logout.php')) ?>" style="margin-top:16px">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button type="submit" class="cms-btn cms-btn--primary">Sign out</button>
        </form>
    </div>
</article>
<?php
render_shell_end();
