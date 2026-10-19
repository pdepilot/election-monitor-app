<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/scope.php';
require_once dirname(__DIR__) . '/includes/dashboard_view.php';

auth_boot();
$user = auth_user();
if ($user === null) {
    redirect_to('portal/login.php');
}
if (!scope_is_manager($user)) {
    render_forbidden($user);
    exit;
}

$flash = null;
$selectedId = filter_var($_GET['user_id'] ?? ($_POST['user_id'] ?? ''), FILTER_VALIDATE_INT);
$selectedId = is_int($selectedId) && $selectedId > 0 ? $selectedId : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $action = (string) ($_POST['action'] ?? '');
    $message = ['kind' => 'error', 'message' => 'The form expired. Reload the page and try again.'];
    if (csrf_verify(is_string($token) ? $token : null) && $selectedId > 0) {
        try {
            $result = match ($action) {
                'assign_ward' => scope_assign_ward(
                    $selectedId,
                    (int) (filter_var($_POST['ward_id'] ?? '', FILTER_VALIDATE_INT) ?: 0),
                    (int) $user['id']
                ),
                'revoke_ward' => scope_revoke_ward(
                    $selectedId,
                    (int) (filter_var($_POST['ward_id'] ?? '', FILTER_VALIDATE_INT) ?: 0),
                    (int) $user['id']
                ),
                'assign_unit' => scope_assign_unit(
                    $selectedId,
                    (int) (filter_var($_POST['polling_unit_id'] ?? '', FILTER_VALIDATE_INT) ?: 0),
                    (int) $user['id'],
                    ($wardHint = filter_var($_POST['expected_ward_id'] ?? '', FILTER_VALIDATE_INT)) === false
                        ? null
                        : (int) $wardHint
                ),
                'revoke_unit' => scope_revoke_unit(
                    $selectedId,
                    (int) (filter_var($_POST['polling_unit_id'] ?? '', FILTER_VALIDATE_INT) ?: 0),
                    (int) $user['id']
                ),
                default => ['ok' => false, 'error' => 'Unknown action.'],
            };
            $message = $result['ok']
                ? ['kind' => 'success', 'message' => 'Scope assignment updated.']
                : ['kind' => 'error', 'message' => $result['error']];
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $message = ['kind' => 'error', 'message' => 'The assignment could not be saved. Try again.'];
        }
    }
    $_SESSION['scope_flash'] = $message;
    redirect_to('admin/user-scopes.php?user_id=' . $selectedId);
}

$flash = $_SESSION['scope_flash'] ?? null;
unset($_SESSION['scope_flash']);
if (!is_array($flash) || !isset($flash['kind'], $flash['message'])) {
    $flash = null;
}

try {
    $scopedUsers = scope_list_scoped_users();
    $wards = db()->query('SELECT id, name FROM wards ORDER BY name')->fetchAll();
    $units = db()->query(
        'SELECT pu.id, pu.unit_code, pu.name, pu.ward_id, w.name AS ward_name
         FROM polling_units pu
         INNER JOIN wards w ON w.id = pu.ward_id
         ORDER BY w.name, pu.unit_code'
    )->fetchAll();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    render_shell_start('User scopes', 'user-scopes', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">Scope assignments could not be loaded. Try again.</p>';
    render_shell_end();
    exit;
}

$selected = null;
$assignments = ['wards' => [], 'units' => []];
foreach ($scopedUsers as $row) {
    if ($row['id'] === $selectedId) {
        $selected = $row;
        break;
    }
}
if ($selected !== null) {
    $assignments = scope_assignments_for($selectedId);
} elseif ($selectedId > 0) {
    $selectedId = 0;
}

render_shell_start('User scopes', 'user-scopes', $user);
?>
<nav class="pu-crumb" aria-label="Breadcrumb">
    <a href="<?= e(url(auth_dashboard_for($user['role']))) ?>">Workspace</a>
    <span aria-hidden="true">/</span>
    <span>User scopes</span>
</nav>
<header class="cms-page__header">
    <div>
        <h1 class="cms-page__title">User scopes</h1>
        <p class="cms-page__subtitle">Assign wards (and optional polling units) for supervisors, collation officers and viewers. Empty scope means no access.</p>
    </div>
</header>
<?php if (!scope_schema_ready()): ?>
<p class="pu-alert pu-alert--error" role="alert">Apply <code>database/migration_user_scopes.sql</code> before managing scopes.</p>
<?php endif; ?>
<?php if ($flash !== null): ?>
<p class="pu-alert pu-alert--<?= e((string) $flash['kind']) ?>" role="<?= $flash['kind'] === 'error' ? 'alert' : 'status' ?>"><?= e((string) $flash['message']) ?></p>
<?php endif; ?>

<article class="cms-card">
    <div class="cms-card__body">
        <form method="get" action="<?= e(url('admin/user-scopes.php')) ?>" class="pu-form">
            <label for="scope_user">Account</label>
            <select id="scope_user" name="user_id" onchange="this.form.submit()">
                <option value="">Select a scoped account…</option>
                <?php foreach ($scopedUsers as $row): ?>
                <option value="<?= (int) $row['id'] ?>"<?= $selectedId === (int) $row['id'] ? ' selected' : '' ?>>
                    <?= e($row['full_name'] . ' (' . $row['username'] . ') — ' . (AUTH_ROLES[$row['role']] ?? $row['role'])) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</article>

<?php if ($selected === null): ?>
<p class="pu-note">Choose a supervisor, collation officer or viewer to manage their geographic access.</p>
<?php else: ?>
<section class="pu-list" style="margin-top:1.25rem">
    <article class="cms-card">
        <div class="cms-card__body">
            <h2 class="cms-card__title"><?= e($selected['full_name']) ?></h2>
            <p class="cms-muted"><?= e($selected['username']) ?> · <?= e(AUTH_ROLES[$selected['role']] ?? $selected['role']) ?></p>

            <h3>Ward assignments</h3>
            <?php if ($assignments['wards'] === []): ?>
            <p class="pu-note">No wards assigned.</p>
            <?php else: ?>
            <ul class="pu-list">
                <?php foreach ($assignments['wards'] as $ward): ?>
                <li class="pu-record">
                    <div class="pu-record__id"><strong><?= e($ward['name']) ?></strong></div>
                    <form method="post" action="<?= e(url('admin/user-scopes.php?user_id=' . $selectedId)) ?>">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="user_id" value="<?= (int) $selectedId ?>">
                        <input type="hidden" name="action" value="revoke_ward">
                        <input type="hidden" name="ward_id" value="<?= (int) $ward['id'] ?>">
                        <button class="cms-btn cms-btn--ghost" type="submit">Revoke</button>
                    </form>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <form method="post" action="<?= e(url('admin/user-scopes.php?user_id=' . $selectedId)) ?>" class="pu-form" style="margin-top:0.75rem">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="user_id" value="<?= (int) $selectedId ?>">
                <input type="hidden" name="action" value="assign_ward">
                <label for="assign_ward">Add ward</label>
                <select id="assign_ward" name="ward_id" required>
                    <option value="">Choose ward…</option>
                    <?php foreach ($wards as $ward): ?>
                    <option value="<?= (int) $ward['id'] ?>"><?= e((string) $ward['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="cms-btn cms-btn--primary" type="submit">Assign ward</button>
            </form>

            <?php if ($selected['role'] !== 'supervisor'): ?>
            <h3 style="margin-top:1.5rem">Polling-unit assignments</h3>
            <?php if ($assignments['units'] === []): ?>
            <p class="pu-note">No polling units assigned.</p>
            <?php else: ?>
            <ul class="pu-list">
                <?php foreach ($assignments['units'] as $unit): ?>
                <li class="pu-record">
                    <div class="pu-record__id">
                        <strong><?= e($unit['unit_code']) ?></strong>
                        <p><?= e($unit['name']) ?> · <?= e($unit['ward_name']) ?></p>
                    </div>
                    <form method="post" action="<?= e(url('admin/user-scopes.php?user_id=' . $selectedId)) ?>">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="user_id" value="<?= (int) $selectedId ?>">
                        <input type="hidden" name="action" value="revoke_unit">
                        <input type="hidden" name="polling_unit_id" value="<?= (int) $unit['id'] ?>">
                        <button class="cms-btn cms-btn--ghost" type="submit">Revoke</button>
                    </form>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <form method="post" action="<?= e(url('admin/user-scopes.php?user_id=' . $selectedId)) ?>" class="pu-form" style="margin-top:0.75rem">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="user_id" value="<?= (int) $selectedId ?>">
                <input type="hidden" name="action" value="assign_unit">
                <label for="assign_unit">Add polling unit</label>
                <select id="assign_unit" name="polling_unit_id" required>
                    <option value="">Choose unit…</option>
                    <?php foreach ($units as $unit): ?>
                    <option value="<?= (int) $unit['id'] ?>" data-ward="<?= (int) $unit['ward_id'] ?>">
                        <?= e($unit['ward_name'] . ' · ' . $unit['unit_code'] . ' — ' . $unit['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="expected_ward_id" id="expected_ward_id" value="">
                <button class="cms-btn cms-btn--primary" type="submit">Assign unit</button>
            </form>
            <script>
            (function () {
                var sel = document.getElementById('assign_unit');
                var hint = document.getElementById('expected_ward_id');
                if (!sel || !hint) return;
                sel.addEventListener('change', function () {
                    var opt = sel.options[sel.selectedIndex];
                    hint.value = opt && opt.getAttribute('data-ward') ? opt.getAttribute('data-ward') : '';
                });
            })();
            </script>
            <?php else: ?>
            <p class="pu-note" style="margin-top:1rem">Supervisors are scoped by ward only.</p>
            <?php endif; ?>
        </div>
    </article>
</section>
<?php endif; ?>
<?php
render_shell_end();
