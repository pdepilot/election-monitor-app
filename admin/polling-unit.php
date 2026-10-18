<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/polling_units.php';
require_once dirname(__DIR__) . '/includes/agent_notifications.php';

auth_boot();
$user = pu_require_manager();

if (!pu_schema_ready()) {
    pu_render_missing_schema($user);
    exit;
}

$mode = (string) ($_GET['mode'] ?? 'view');
if (!in_array($mode, ['view', 'edit', 'assign', 'create'], true)) {
    $mode = 'view';
}
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$id = is_int($id) && $id > 0 ? $id : 0;
$errors = [];
$unit = null;
$values = [
    'unit_code' => '',
    'name' => '',
    'ward_id' => 0,
    'classification' => '',
    'is_active' => 1,
    'agent_user_id' => 0,
];
$agentForm = [
    'agent_name' => '',
    'agent_username' => '',
    'agent_email' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $intent = (string) ($_POST['intent'] ?? '');
    $postedId = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
    $id = is_int($postedId) && $postedId > 0 ? $postedId : 0;
    $values['unit_code'] = trim((string) ($_POST['unit_code'] ?? ''));
    $values['name'] = trim((string) ($_POST['name'] ?? ''));
    $postedWard = filter_var($_POST['ward_id'] ?? '', FILTER_VALIDATE_INT);
    $values['ward_id'] = is_int($postedWard) ? $postedWard : 0;
    $values['classification'] = trim((string) ($_POST['classification'] ?? ''));
    $values['is_active'] = isset($_POST['is_active']) ? 1 : 0;
    $postedAgent = filter_var($_POST['agent_user_id'] ?? '', FILTER_VALIDATE_INT);
    $values['agent_user_id'] = is_int($postedAgent) ? $postedAgent : 0;

    if (!csrf_verify(is_string($token) ? $token : null)) {
        http_response_code(403);
        $errors['form'] = 'The form expired. Reload the page and try again.';
        $mode = match ($intent) {
            'create' => 'create',
            'update' => 'edit',
            'assign', 'create_agent' => 'assign',
            default => 'view',
        };
    } else {
        try {
            if ($intent === 'create') {
                $mode = 'create';
                $checked = pu_validate_unit($_POST, null);
                $errors = $checked['errors'];
                $values = array_merge($values, $checked['data']);
                if ($errors === []) {
                    $newId = pu_create($checked['data'], (int) $user['id']);
                    pu_flash('success', 'Polling unit added.');
                    pu_redirect('admin/polling-unit.php?id=' . $newId);
                }
            } elseif ($intent === 'update') {
                $mode = 'edit';
                $unit = $id > 0 ? pu_find($id) : null;
                if ($unit === null) {
                    pu_render_missing_unit($user);
                    exit;
                }
                $checked = pu_validate_unit($_POST, $id);
                $errors = $checked['errors'];
                $values = array_merge($values, $checked['data']);
                if ($errors === []) {
                    pu_update($id, $checked['data'], (int) $user['id'], (string) $unit['unit_code']);
                    pu_flash('success', 'Polling unit updated.');
                    pu_redirect('admin/polling-unit.php?id=' . $id);
                }
            } elseif ($intent === 'assign') {
                $mode = 'assign';
                $before = $id > 0 ? pu_find($id) : null;
                $result = pu_assign($id, $values['agent_user_id'], (int) $user['id']);
                if ($result['ok']) {
                    $previousAgent = (int) ($before['agent_user_id'] ?? 0);
                    if ($previousAgent === $values['agent_user_id']) {
                        pu_flash('success', 'Polling agent assigned. This agent already held the unit, so no email was sent.');
                    } else {
                        $notice = an_notify_assignment_change([$values['agent_user_id'], $previousAgent], (int) $user['id']);
                        pu_flash($notice['ok'] ? 'success' : 'warn', trim('Polling agent assigned. ' . $notice['text']));
                    }
                    pu_redirect('admin/polling-unit.php?id=' . $id);
                }
                $errors['agent_user_id'] = $result['error'];
            } elseif ($intent === 'create_agent') {
                $mode = 'assign';
                $unit = $id > 0 ? pu_find($id) : null;
                if ($unit === null) {
                    pu_render_missing_unit($user);
                    exit;
                }
                $checked = pu_validate_agent($_POST);
                $agentForm = [
                    'agent_name' => $checked['data']['agent_name'],
                    'agent_username' => $checked['data']['agent_username'],
                    'agent_email' => $checked['data']['agent_email'],
                ];
                $errors = $checked['errors'];
                if ($errors === []) {
                    $notificationId = null;
                    $withinTransaction = an_schema_ready()
                        ? static function (PDO $pdo, int $agentId) use (&$notificationId, $user): void {
                            $notificationId = an_queue($agentId, 'account_created', (int) $user['id'], true);
                        }
                        : null;
                    $result = pu_register_agent($id, $checked['data'], (int) $user['id'], $withinTransaction);
                    if ($result['ok']) {
                        $notice = an_send_account_email($notificationId);
                        pu_flash($notice['ok'] ? 'success' : 'warn', 'Polling agent registered and assigned to this unit. ' . $notice['text']);
                        pu_redirect('admin/polling-unit.php?id=' . $id);
                    }
                    $errors['form'] = $result['error'];
                }
            } elseif ($intent === 'unassign') {
                $mode = 'view';
                if (($_POST['confirm'] ?? '') !== '1') {
                    $errors['form'] = 'Confirm that you want to clear the assigned agent.';
                } else {
                    $before = $id > 0 ? pu_find($id) : null;
                    $result = pu_unassign($id, (int) $user['id']);
                    if ($result['ok']) {
                        $notice = an_notify_assignment_change([(int) ($before['agent_user_id'] ?? 0)], (int) $user['id']);
                        pu_flash($notice['ok'] ? 'success' : 'warn', trim('The assigned agent was cleared. ' . $notice['text']));
                        pu_redirect('admin/polling-unit.php?id=' . $id);
                    }
                    $errors['form'] = $result['error'];
                }
            } elseif ($intent === 'retry_notification') {
                $mode = 'view';
                $retry = an_schema_ready()
                    ? an_retry((string) ($_POST['notification_id'] ?? ''), (int) $user['id'])
                    : ['ok' => false, 'error' => 'Assignment emails are unavailable until the notification migration is applied.'];
                if ($retry['ok']) {
                    $handed = in_array($retry['status'] ?? '', ['sent', 'captured'], true);
                    pu_flash($handed ? 'success' : 'warn', 'Retry: ' . an_status_text((string) $retry['status'], $retry['detail'] ?? null, (string) ($retry['email'] ?? '')));
                    pu_redirect('admin/polling-unit.php?id=' . $id);
                }
                $errors['form'] = $retry['error'];
            } elseif ($intent === 'deactivate' || $intent === 'reactivate') {
                $mode = 'view';
                if ($intent === 'deactivate' && ($_POST['confirm'] ?? '') !== '1') {
                    $errors['form'] = 'Confirm deactivation. The polling unit stays in the register and can be activated again.';
                } else {
                    $result = pu_set_active($id, $intent === 'reactivate', (int) $user['id']);
                    if ($result['ok']) {
                        pu_flash('success', $intent === 'reactivate' ? 'Polling unit activated.' : 'Polling unit deactivated.');
                        pu_redirect('admin/polling-unit.php?id=' . $id);
                    }
                    $errors['form'] = $result['error'];
                }
            } else {
                $errors['form'] = 'That action is not available.';
            }
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            if ($intent === 'retry_notification') {
                $errors['form'] = 'The email could not be retried. Try again.';
            } elseif (pu_is_duplicate_key($exception) && $intent === 'create_agent') {
                $errors['agent_username'] = 'That username or email is already in use.';
            } elseif (pu_is_duplicate_key($exception)) {
                $errors['unit_code'] = 'That polling-unit ID is already in use.';
            } else {
                $errors['form'] = 'The polling unit could not be saved. Try again.';
            }
        }
    }
}

if ($mode === 'create') {
    $unit = null;
} elseif ($unit === null) {
    $unit = $id > 0 ? pu_find($id) : null;
    if ($unit === null) {
        pu_render_missing_unit($user);
        exit;
    }
    if ($errors === [] && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $values['unit_code'] = (string) $unit['unit_code'];
        $values['name'] = (string) $unit['name'];
        $values['ward_id'] = (int) $unit['ward_id'];
        $values['classification'] = (string) $unit['classification'];
        $values['is_active'] = (int) $unit['is_active'];
        $values['agent_user_id'] = (int) ($unit['agent_user_id'] ?? 0);
    }
}

try {
    $wards = pu_wards();
    $agents = $mode === 'assign' ? pu_agents() : [];
    $audit = $unit !== null ? pu_recent_audit((int) $unit['id']) : [];
    $notificationsReady = an_schema_ready();
    $notifications = $unit !== null && $mode === 'view' && $unit['agent_user_id'] !== null && $notificationsReady
        ? an_recent_for_user((int) $unit['agent_user_id'])
        : [];
    $mailStatus = mailer_status();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    render_shell_start('Polling unit', 'polling-units', $user);
    echo '<p class="pu-alert pu-alert--error" role="alert">The polling unit could not be loaded. Try again.</p>';
    render_shell_end();
    exit;
}

$flash = pu_take_flash();
$title = match ($mode) {
    'create' => 'Add polling unit',
    'edit' => 'Edit polling unit',
    'assign' => 'Assign polling agent',
    default => 'Polling unit',
};
$crumb = match ($mode) {
    'create' => 'Add',
    'edit' => 'Edit',
    'assign' => 'Assign agent',
    default => 'Details',
};

render_shell_start($title, 'polling-units', $user);
?>
<nav class="pu-crumb" aria-label="Breadcrumb">
    <a href="<?= e(url('admin/dashboard.php')) ?>">Administration</a>
    <span aria-hidden="true">/</span>
    <a href="<?= e(pu_list_href()) ?>">Polling Unit Management</a>
    <span aria-hidden="true">/</span>
    <span><?= e($crumb) ?></span>
</nav>
<header class="cms-page__header">
    <div>
        <h1 class="cms-page__title"><?= e($title) ?></h1>
        <p class="cms-page__subtitle"><?= $unit === null ? 'Ohaji/Egbema' : e((string) $unit['unit_code']) ?></p>
    </div>
</header>
<?php if ($flash !== null): ?>
<p class="pu-alert pu-alert--<?= e($flash['kind']) ?>" role="<?= $flash['kind'] === 'error' ? 'alert' : 'status' ?>"><?= e($flash['message']) ?></p>
<?php endif; ?>
<?php if (($errors['form'] ?? '') !== ''): ?>
<p class="pu-alert pu-alert--error" role="alert"><?= e($errors['form']) ?></p>
<?php endif; ?>

<?php if ($mode === 'create' || $mode === 'edit'): ?>
<article class="cms-card">
    <div class="cms-card__body">
        <form method="post" action="<?= e(url('admin/polling-unit.php')) ?>" data-pu-busy="Saving…">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="intent" value="<?= $mode === 'create' ? 'create' : 'update' ?>">
            <?php if ($mode === 'edit'): ?>
            <input type="hidden" name="id" value="<?= e((string) $id) ?>">
            <?php endif; ?>
            <div class="pu-form-grid">
                <div class="pu-field">
                    <label for="unit_code">Polling Unit ID</label>
                    <input id="unit_code" name="unit_code" value="<?= e($values['unit_code']) ?>" maxlength="32" required autocomplete="off">
                    <?php if (($errors['unit_code'] ?? '') !== ''): ?><span class="pu-error"><?= e($errors['unit_code']) ?></span><?php endif; ?>
                </div>
                <div class="pu-field">
                    <label for="unit_name">Polling Unit Name</label>
                    <input id="unit_name" name="name" value="<?= e($values['name']) ?>" maxlength="150" required>
                    <?php if (($errors['name'] ?? '') !== ''): ?><span class="pu-error"><?= e($errors['name']) ?></span><?php endif; ?>
                </div>
                <div class="pu-field">
                    <label for="ward_id">Ward</label>
                    <select id="ward_id" name="ward_id" required>
                        <option value="">Select a ward</option>
                        <?php foreach ($wards as $ward): ?>
                        <option value="<?= e((string) $ward['id']) ?>"<?= $values['ward_id'] === $ward['id'] ? ' selected' : '' ?>><?= e($ward['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (($errors['ward_id'] ?? '') !== ''): ?><span class="pu-error"><?= e($errors['ward_id']) ?></span><?php endif; ?>
                </div>
                <div class="pu-field">
                    <label for="classification">Classification</label>
                    <select id="classification" name="classification" required>
                        <option value="">Select classification</option>
                        <?php foreach (PU_CLASSIFICATIONS as $classification): ?>
                        <option value="<?= e($classification) ?>"<?= $values['classification'] === $classification ? ' selected' : '' ?>><?= e($classification) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (($errors['classification'] ?? '') !== ''): ?><span class="pu-error"><?= e($errors['classification']) ?></span><?php endif; ?>
                </div>
            </div>
            <p class="pu-note">LGA is Ohaji/Egbema. The polling-unit ID is stored exactly as entered.</p>
            <?php if ($mode === 'edit'): ?>
            <label class="pu-check"><input type="checkbox" name="is_active" value="1"<?= $values['is_active'] === 1 ? ' checked' : '' ?>> Active</label>
            <?php endif; ?>
            <div class="pu-form-actions">
                <button class="cms-btn cms-btn--primary" type="submit"><?= $mode === 'create' ? 'Add polling unit' : 'Save changes' ?></button>
                <a class="cms-btn cms-btn--ghost" href="<?= e($mode === 'create' ? pu_list_href() : url('admin/polling-unit.php?id=' . $id)) ?>">Cancel</a>
            </div>
        </form>
    </div>
</article>
<?php elseif ($mode === 'assign' && $unit !== null): ?>
<article class="cms-card">
    <div class="cms-card__body">
        <p><?= e((string) $unit['name']) ?> · <?= e((string) $unit['ward_name']) ?></p>
        <?php if ($agents === []): ?>
        <p class="pu-note">No polling agent is registered yet. Use the form below to create one and assign this unit.</p>
        <?php else: ?>
        <form method="post" action="<?= e(url('admin/polling-unit.php')) ?>" data-pu-busy="Assigning…">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="intent" value="assign">
            <input type="hidden" name="id" value="<?= e((string) $id) ?>">
            <div class="pu-field">
                <label for="agent_user_id">Polling agent</label>
                <select id="agent_user_id" name="agent_user_id" required>
                    <option value="">Select an agent</option>
                    <?php foreach ($agents as $agent): ?>
                    <option value="<?= e((string) $agent['id']) ?>"<?= $values['agent_user_id'] === $agent['id'] ? ' selected' : '' ?>><?= e($agent['full_name']) ?> (<?= e($agent['username']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <?php if (($errors['agent_user_id'] ?? '') !== ''): ?><span class="pu-error"><?= e($errors['agent_user_id']) ?></span><?php endif; ?>
            </div>
            <div class="pu-form-actions">
                <button class="cms-btn cms-btn--primary" type="submit">Assign agent</button>
                <a class="cms-btn cms-btn--ghost" href="<?= e(url('admin/polling-unit.php?id=' . $id)) ?>">Cancel</a>
            </div>
        </form>
        <?php endif; ?>
    </div>
</article>
<article class="cms-card">
    <div class="cms-card__head"><h2 class="cms-card__title">Register a polling agent</h2></div>
    <div class="cms-card__body">
        <p>This creates an active polling-agent account, assigns it to this unit, and emails the username, a single-use password-setup link, and the unit details to the address below. No password is chosen here or stored in recoverable form. The link expires and can be used once.</p>
        <?php if (!$mailStatus['ready']): ?>
        <p class="pu-note">Email is not configured in this environment (<?= e(an_error_text($mailStatus['reason'])) ?>). The account will still be created; share the details with the agent another way, or retry the email once it is set up.</p>
        <?php elseif ($mailStatus['transport'] === 'capture'): ?>
        <p class="pu-note">Test mode: emails are captured on this server and not sent.</p>
        <?php endif; ?>
        <form method="post" action="<?= e(url('admin/polling-unit.php')) ?>" data-pu-busy="Saving…" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="intent" value="create_agent">
            <input type="hidden" name="id" value="<?= e((string) $id) ?>">
            <div class="pu-form-grid">
                <div class="pu-field">
                    <label for="agent_name">Full name</label>
                    <input id="agent_name" name="agent_name" value="<?= e($agentForm['agent_name']) ?>" maxlength="150" required autocomplete="name">
                    <?php if (($errors['agent_name'] ?? '') !== ''): ?><span class="pu-error"><?= e($errors['agent_name']) ?></span><?php endif; ?>
                </div>
                <div class="pu-field">
                    <label for="agent_username">Username</label>
                    <input id="agent_username" name="agent_username" value="<?= e($agentForm['agent_username']) ?>" maxlength="64" required autocapitalize="none" autocomplete="off">
                    <?php if (($errors['agent_username'] ?? '') !== ''): ?><span class="pu-error"><?= e($errors['agent_username']) ?></span><?php endif; ?>
                </div>
                <div class="pu-field">
                    <label for="agent_email">Email</label>
                    <input id="agent_email" name="agent_email" type="email" value="<?= e($agentForm['agent_email']) ?>" maxlength="255" required autocomplete="off">
                    <?php if (($errors['agent_email'] ?? '') !== ''): ?><span class="pu-error"><?= e($errors['agent_email']) ?></span><?php endif; ?>
                </div>
            </div>
            <p class="pu-note">The agent receives a time-limited setup link by email and chooses their own password (at least 12 characters).</p>
            <div class="pu-form-actions">
                <button class="cms-btn cms-btn--primary" type="submit">Create and assign</button>
                <a class="cms-btn cms-btn--ghost" href="<?= e(url('admin/polling-unit.php?id=' . $id)) ?>">Cancel</a>
            </div>
        </form>
    </div>
</article>
<?php elseif ($unit !== null): ?>
<article class="cms-card">
    <div class="cms-card__body">
        <dl class="cms-meta-list">
            <div><dt>Polling Unit ID</dt><dd><?= e((string) $unit['unit_code']) ?></dd></div>
            <div><dt>Polling Unit Name</dt><dd><?= e((string) $unit['name']) ?></dd></div>
            <div><dt>Ward</dt><dd><?= e((string) $unit['ward_name']) ?></dd></div>
            <div><dt>LGA</dt><dd><?= e((string) $unit['lga_name']) ?></dd></div>
            <div><dt>Classification</dt><dd><?= e((string) $unit['classification']) ?></dd></div>
            <div><dt>Assignment status</dt><dd><?= $unit['agent_user_id'] === null ? 'Unassigned' : 'Assigned' ?></dd></div>
            <div><dt>Assigned agent</dt><dd><?php if ($unit['agent_name'] === null): ?>—<?php else: ?><?= e((string) $unit['agent_name']) ?> (<?= e((string) $unit['agent_username']) ?>)<?php if ((int) $unit['agent_active'] !== 1): ?> · inactive account<?php endif; ?><?php endif; ?></dd></div>
            <div><dt>Assigned at</dt><dd><?= e(pu_when($unit['assigned_at'] !== null ? (string) $unit['assigned_at'] : null)) ?></dd></div>
            <div><dt>Assigned by</dt><dd><?= e($unit['assigned_by_name'] !== null ? (string) $unit['assigned_by_name'] : '—') ?></dd></div>
            <div><dt>Status</dt><dd><?= (int) $unit['is_active'] === 1 ? 'Active' : 'Inactive' ?></dd></div>
            <div><dt>Created</dt><dd><?= e(pu_when((string) $unit['created_at'])) ?></dd></div>
            <div><dt>Updated</dt><dd><?= e(pu_when((string) $unit['updated_at'])) ?></dd></div>
        </dl>
        <div class="pu-form-actions">
            <a class="cms-btn cms-btn--primary" href="<?= e(url('admin/polling-unit.php?id=' . $id . '&mode=edit')) ?>">Edit</a>
            <a class="cms-btn cms-btn--ghost" href="<?= e(url('admin/polling-unit.php?id=' . $id . '&mode=assign')) ?>">Assign agent</a>
            <a class="cms-btn cms-btn--ghost" href="<?= e(pu_list_href()) ?>">Back to list</a>
        </div>
    </div>
</article>
<article class="cms-card">
    <div class="cms-card__head"><h2 class="cms-card__title"><?= (int) $unit['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></h2></div>
    <div class="cms-card__body">
        <?php if ((int) $unit['is_active'] === 1): ?>
        <p>Deactivation hides the unit from active monitoring and keeps its record, including any later result references.</p>
        <form method="post" action="<?= e(url('admin/polling-unit.php')) ?>" data-pu-busy="Saving…">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="intent" value="deactivate">
            <input type="hidden" name="id" value="<?= e((string) $id) ?>">
            <label class="pu-check"><input type="checkbox" name="confirm" value="1"> I understand this polling unit will stay in the register as inactive.</label>
            <div class="pu-form-actions"><button class="cms-btn cms-btn--danger" type="submit">Deactivate</button></div>
        </form>
        <?php else: ?>
        <form method="post" action="<?= e(url('admin/polling-unit.php')) ?>" data-pu-busy="Saving…">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="intent" value="reactivate">
            <input type="hidden" name="id" value="<?= e((string) $id) ?>">
            <button class="cms-btn cms-btn--primary" type="submit">Activate</button>
        </form>
        <?php endif; ?>
        <?php if ($unit['agent_user_id'] !== null): ?>
        <form method="post" action="<?= e(url('admin/polling-unit.php')) ?>" data-pu-busy="Saving…">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="intent" value="unassign">
            <input type="hidden" name="id" value="<?= e((string) $id) ?>">
            <label class="pu-check"><input type="checkbox" name="confirm" value="1"> Clear the assigned agent.</label>
            <div class="pu-form-actions"><button class="cms-btn cms-btn--ghost" type="submit">Clear assignment</button></div>
        </form>
        <?php endif; ?>
    </div>
</article>
<?php if ($notifications !== []): ?>
<article class="cms-card">
    <div class="cms-card__head"><h2 class="cms-card__title">Agent emails</h2></div>
    <div class="cms-card__body">
        <p class="pu-note">“Handed to the mail server” means the server accepted the message; it does not confirm the agent received it.</p>
        <div class="pu-table-wrap">
            <table class="pu-table">
                <thead><tr><th>When</th><th>Email</th><th>To</th><th>Status</th><th>Attempts</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($notifications as $note): ?>
                    <?php $retryable = in_array((string) $note['status'], AN_RETRYABLE, true); ?>
                    <tr data-notification-status="<?= e((string) $note['status']) ?>">
                        <td><?= e(pu_when((string) $note['updated_at'])) ?></td>
                        <td><?= (string) $note['kind'] === 'account_created' ? 'Account details and assignment' : 'Assignment update' ?></td>
                        <td><?= e((string) $note['recipient_email']) ?></td>
                        <td><?= e(match ((string) $note['status']) {
                            'sent' => 'Handed to the mail server',
                            'captured' => 'Captured locally (test mode)',
                            'failed' => 'Failed: ' . an_error_text($note['last_error'] !== null ? (string) $note['last_error'] : null),
                            'not_configured' => 'Not sent: email not configured',
                            'superseded' => 'Replaced by a newer email',
                            default => 'Queued',
                        }) ?></td>
                        <td><?= e((string) $note['attempts']) ?></td>
                        <td>
                            <?php if ($retryable): ?>
                            <form method="post" action="<?= e(url('admin/polling-unit.php')) ?>" data-pu-busy="Sending…" autocomplete="off">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="intent" value="retry_notification">
                                <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                                <input type="hidden" name="notification_id" value="<?= e((string) $note['notification_id']) ?>">
                                <?php if ((int) $note['includes_password'] === 1): ?>
                                <p class="pu-note">Retry issues a new single-use setup link. Previous unused links for this account stop working.</p>
                                <?php endif; ?>
                                <button class="cms-btn cms-btn--ghost" type="submit"><?= (string) $note['status'] === 'captured' ? 'Send email now' : 'Retry email' ?></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</article>
<?php endif; ?>
<?php if ($audit !== []): ?>
<article class="cms-card">
    <div class="cms-card__head"><h2 class="cms-card__title">Recent changes</h2></div>
    <div class="cms-card__body">
        <div class="pu-table-wrap">
            <table class="pu-table">
                <thead><tr><th>When</th><th>Action</th><th>By</th><th>Detail</th></tr></thead>
                <tbody>
                    <?php foreach ($audit as $entry): ?>
                    <tr>
                        <td><?= e(pu_when((string) $entry['created_at'])) ?></td>
                        <td><?= e((string) $entry['action']) ?></td>
                        <td><?= e($entry['full_name'] !== null ? (string) $entry['full_name'] : '—') ?></td>
                        <td><?= e((string) $entry['summary']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</article>
<?php endif; ?>
<?php endif; ?>
<?php
pu_form_script();
render_shell_end();

function pu_when(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

    return $parsed === false ? '—' : $parsed->format('M j, Y g:i A');
}

function pu_list_href(): string
{
    $query = $_SESSION['pu_list_query'] ?? '';
    if (!is_string($query) || !preg_match('/\A[A-Za-z0-9_=&%+.-]*\z/', $query)) {
        return url('admin/polling-units.php');
    }

    return url('admin/polling-units.php') . ($query === '' ? '' : '?' . $query);
}

/**
 * @param array{id: int, username: string, email: string, full_name: string, role: string, last_login_at?: ?string} $user
 */
function pu_render_missing_unit(array $user): void
{
    http_response_code(404);
    render_shell_start('Polling unit', 'polling-units', $user);
    ?>
    <header class="cms-page__header">
        <div>
            <h1 class="cms-page__title">Polling unit not found</h1>
            <p class="cms-page__subtitle">That record is not in the register.</p>
        </div>
    </header>
    <p><a class="cms-btn cms-btn--primary" href="<?= e(url('admin/polling-units.php')) ?>">Back to polling units</a></p>
    <?php
    render_shell_end();
}
