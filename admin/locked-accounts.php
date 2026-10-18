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
if (!in_array($user['role'], ['super_admin', 'admin'], true)) {
    render_forbidden($user);
    exit;
}

function lock_can_unlock(array $actor, string $targetRole): bool
{
    return $actor['role'] === 'super_admin' || $targetRole !== 'super_admin';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $targetId = filter_var($_POST['user_id'] ?? '', FILTER_VALIDATE_INT);
    $message = ['kind' => 'error', 'message' => 'The form expired. Reload the page and try again.'];
    if (csrf_verify(is_string($token) ? $token : null) && is_int($targetId) && $targetId > 0) {
        try {
            $find = db()->prepare('SELECT role FROM users WHERE id = :id AND locked_at IS NOT NULL LIMIT 1');
            $find->execute(['id' => $targetId]);
            $targetRole = $find->fetchColumn();
            if ($targetRole === false) {
                $message = ['kind' => 'error', 'message' => 'That account is not locked.'];
            } elseif (!lock_can_unlock($user, (string) $targetRole)) {
                $message = ['kind' => 'error', 'message' => 'Only a super administrator can unlock a super administrator account.'];
            } elseif (auth_unlock_account($targetId, (int) $user['id'])) {
                $message = ['kind' => 'success', 'message' => 'The account was unlocked. The user can sign in again.'];
            } else {
                $message = ['kind' => 'error', 'message' => 'That account is not locked.'];
            }
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $message = ['kind' => 'error', 'message' => 'The account could not be unlocked. Try again.'];
        }
    }
    $_SESSION['lock_flash'] = $message;
    redirect_to('admin/locked-accounts.php');
}

$flash = $_SESSION['lock_flash'] ?? null;
unset($_SESSION['lock_flash']);
if (!is_array($flash) || !isset($flash['kind'], $flash['message'])) {
    $flash = null;
}

try {
    $rows = db()->query(
        'SELECT id, username, full_name, role, failed_login_count, locked_at
         FROM users
         WHERE locked_at IS NOT NULL
         ORDER BY locked_at DESC, username'
    )->fetchAll();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    render_shell_start('Locked accounts', 'locked-accounts', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">Locked accounts could not be loaded. Try again.</p>';
    render_shell_end();
    exit;
}

render_shell_start('Locked accounts', 'locked-accounts', $user);
?>
<nav class="pu-crumb" aria-label="Breadcrumb">
    <a href="<?= e(url(auth_dashboard_for($user['role']))) ?>">Workspace</a>
    <span aria-hidden="true">/</span>
    <span>Locked accounts</span>
</nav>
<header class="cms-page__header">
    <div>
        <h1 class="cms-page__title">Locked accounts</h1>
        <p class="cms-page__subtitle">Accounts enter a temporary cool-down after <?= e((string) AUTH_TEMP_LOCK_AFTER) ?> failed passwords and a permanent lock after <?= e((string) AUTH_HARD_LOCK_AFTER) ?> (except the sole super administrator). Permanent locks are cleared here.</p>
    </div>
</header>
<?php if ($flash !== null): ?>
<p class="pu-alert pu-alert--<?= e((string) $flash['kind']) ?>" role="<?= $flash['kind'] === 'error' ? 'alert' : 'status' ?>"><?= e((string) $flash['message']) ?></p>
<?php endif; ?>
<div class="pu-list">
    <?php if ($rows === []): ?>
    <p class="pu-note">No accounts are locked.</p>
    <?php endif; ?>
    <?php foreach ($rows as $row): ?>
    <article class="pu-record">
        <div class="pu-record__id">
            <strong><?= e((string) $row['username']) ?></strong>
            <p><?= e((string) $row['full_name']) ?> · <?= e(AUTH_ROLES[(string) $row['role']] ?? (string) $row['role']) ?></p>
        </div>
        <dl class="pu-record__meta">
            <div><dt>Locked at</dt><dd><?= e((string) $row['locked_at']) ?></dd></div>
            <div><dt>Failed attempts</dt><dd><?= e((string) $row['failed_login_count']) ?></dd></div>
        </dl>
        <div class="pu-row-actions">
            <?php if (lock_can_unlock($user, (string) $row['role'])): ?>
            <form method="post" action="<?= e(url('admin/locked-accounts.php')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="user_id" value="<?= e((string) $row['id']) ?>">
                <button class="cms-btn cms-btn--primary cms-btn--sm" type="submit">Unlock</button>
            </form>
            <?php else: ?>
            <span class="cms-badge cms-badge--muted">Super administrator unlock required</span>
            <?php endif; ?>
        </div>
    </article>
    <?php endforeach; ?>
</div>
<?php
render_shell_end();
