<?php
declare(strict_types=1);

require_once __DIR__ . '/mark.php';
require_once __DIR__ . '/dashboard_style.php';
require_once __DIR__ . '/dashboard_script.php';

/**
 * @param array{id: int, username: string, email: string, full_name: string, role: string, last_login_at?: ?string} $user
 */
function render_forbidden(array $user): void
{
    http_response_code(403);
    $dashboard = auth_dashboard_for($user['role']);
    render_shell_start('Access denied', 'dashboard', $user);
    ?>
    <header class="cms-page__header">
        <div>
            <h1 class="cms-page__title">Access denied</h1>
            <p class="cms-page__subtitle">Your account does not have access to this area.</p>
        </div>
    </header>
    <article class="cms-card">
        <div class="cms-card__body">
            <p>Signed in as <?= e($user['full_name']) ?> · <?= e(AUTH_ROLES[$user['role']] ?? 'Authorized user') ?></p>
            <p style="margin-top:16px"><a class="cms-btn cms-btn--primary" href="<?= e(url($dashboard)) ?>">Go to your workspace</a></p>
        </div>
    </article>
    <?php
    render_shell_end();
}

/**
 * @param list<string> $roles
 */
function render_workspace(string $title, string $intro, array $roles): void
{
    $user = auth_user();
    if ($user === null) {
        redirect_to('portal/login.php');
    }
    if (!in_array($user['role'], $roles, true)) {
        render_forbidden($user);
        exit;
    }

    $roleLabel = AUTH_ROLES[$user['role']];
    $now = new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos'));
    render_shell_start($title, 'dashboard', $user);
    ?>
    <header class="cms-page__header">
        <div>
            <h1 class="cms-page__title"><?= e($title) ?></h1>
            <p class="cms-page__subtitle">Welcome back, <?= e($user['full_name']) ?>. Ohaji/Egbema Federal Constituency, Imo State.</p>
        </div>
        <div class="cms-page__actions">
            <span class="cms-badge cms-badge--muted"><?= e($now->format('D, M j · g:i A')) ?></span>
        </div>
    </header>

    <p class="cms-section-label">Ohaji/Egbema — Monitoring</p>
    <div class="cms-stats">
        <article class="cms-stat cms-card">
            <div class="cms-stat__top">
                <div class="cms-stat__icon cms-stat__icon--gold"><i class="fas fa-user-shield" aria-hidden="true"></i></div>
            </div>
            <div class="cms-stat__value cms-stat__value--text"><?= e($roleLabel) ?></div>
            <div class="cms-stat__label">Assigned role</div>
            <span class="cms-stat__brand cms-stat__brand--ag">Authorized</span>
        </article>
        <article class="cms-stat cms-card">
            <div class="cms-stat__top">
                <div class="cms-stat__icon cms-stat__icon--green"><i class="fas fa-circle-check" aria-hidden="true"></i></div>
            </div>
            <div class="cms-stat__value">Active</div>
            <div class="cms-stat__label">Account status</div>
            <span class="cms-stat__brand cms-stat__brand--ag">Ohaji/Egbema</span>
        </article>
        <article class="cms-stat cms-card">
            <div class="cms-stat__top">
                <div class="cms-stat__icon cms-stat__icon--blue"><i class="fas fa-clock" aria-hidden="true"></i></div>
            </div>
            <div class="cms-stat__value cms-stat__value--text"><?= e(format_last_login($user['last_login_at'] ?? null)) ?></div>
            <div class="cms-stat__label">Last sign-in</div>
            <span class="cms-stat__brand cms-stat__brand--ag">This account</span>
        </article>
        <article class="cms-stat cms-card">
            <div class="cms-stat__top">
                <div class="cms-stat__icon cms-stat__icon--purple"><i class="fas fa-location-dot" aria-hidden="true"></i></div>
            </div>
            <div class="cms-stat__value cms-stat__value--text">Imo State</div>
            <div class="cms-stat__label">Federal constituency</div>
            <span class="cms-stat__brand cms-stat__brand--ag">Ohaji/Egbema</span>
        </article>
    </div>

    <div class="cms-grid cms-grid--2">
        <article class="cms-card">
            <div class="cms-card__head">
                <h2 class="cms-card__title">Workspace</h2>
            </div>
            <div class="cms-card__body">
                <p class="cms-card__subtitle" style="margin-top:0"><?= e($intro) ?> Result entry is available from Results. Progress, scores, and collation reports are available from Reports.</p>
            </div>
        </article>
        <article class="cms-card">
            <div class="cms-card__head">
                <h2 class="cms-card__title">Your session</h2>
            </div>
            <div class="cms-card__body">
                <dl class="cms-meta-list">
                    <div><dt>Name</dt><dd><?= e($user['full_name']) ?></dd></div>
                    <div><dt>Username</dt><dd><?= e($user['username']) ?></dd></div>
                    <div><dt>Email</dt><dd><?= e($user['email']) ?></dd></div>
                    <div><dt>Role</dt><dd><?= e($roleLabel) ?></dd></div>
                </dl>
            </div>
        </article>
    </div>
    <?php
    render_shell_end();
}

/**
 * @param array{id: int, username: string, email: string, full_name: string, role: string, last_login_at?: ?string} $user
 */
function render_shell_start(string $title, string $activeNav, array $user): void
{
    $roleLabel = AUTH_ROLES[$user['role']] ?? 'Authorized user';
    $theme = shell_pref('em_theme', ['gold', 'ocean', 'emerald', 'royal', 'sunset'], 'gold');
    $mode = shell_pref('em_mode', ['dark', 'light'], 'dark');
    $dashboardPath = auth_dashboard_for($user['role']);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#070b16">
    <title><?= e($title) ?> | Election Monitoring Portal</title>
    <link rel="icon" href="<?= e(portal_favicon_href()) ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css">
    <style nonce="<?= e(app_csp_nonce()) ?>"><?= dashboard_css() ?></style>
</head>
<body class="cms-app<?= $mode === 'dark' ? ' dark' : '' ?>" data-page="<?= e($activeNav) ?>" data-theme="<?= e($theme) ?>" data-mode="<?= e($mode) ?>" data-cookie-path="<?= e(app_cookie_path()) ?>" data-user-id="<?= (int) $user['id'] ?>"<?php if (auth_is_idle_role($user['role'])): ?> data-idle-seconds="<?= (int) auth_idle_seconds_for($user['role']) ?>" data-idle-logout="<?= e(url('portal/logout.php')) ?>" data-csrf-token="<?= e(csrf_token()) ?>"<?php endif; ?>>
    <div class="cms-sidebar-overlay" id="cmsSidebarOverlay" hidden></div>
    <div class="cms-layout">
        <aside class="cms-sidebar" id="cmsSidebar" aria-label="Main navigation">
            <div class="cms-sidebar__brand">
                <div class="cms-sidebar__logo">
                    <?php portal_mark_svg(42); ?>
                </div>
                <div class="cms-sidebar__brand-text">
                    <strong>Election Portal</strong>
                    <span>Ohaji/Egbema</span>
                </div>
            </div>
            <nav class="cms-sidebar__nav">
                <?php if ($user['role'] === 'polling_agent'): ?>
                <div class="cms-nav-group">
                    <ul class="cms-nav-group__items">
                        <li class="cms-nav-item<?= $activeNav === 'dashboard' ? ' is-active' : '' ?>">
                            <a href="<?= e(url('agent/dashboard.php')) ?>">
                                <i class="fas fa-gauge-high cms-nav-item__icon" aria-hidden="true"></i>
                                <span>My polling unit</span>
                            </a>
                        </li>
                    </ul>
                </div>
                <?php else: ?>
                <div class="cms-nav-group">
                    <button type="button" class="cms-nav-group__label" aria-expanded="true">
                        <span>Monitoring</span>
                        <i class="fas fa-chevron-down chevron" aria-hidden="true"></i>
                    </button>
                    <ul class="cms-nav-group__items">
                        <li class="cms-nav-item<?= $activeNav === 'dashboard' ? ' is-active' : '' ?>">
                            <a href="<?= e(url($dashboardPath)) ?>">
                                <i class="fas fa-gauge-high cms-nav-item__icon" aria-hidden="true"></i>
                                <span>Dashboard</span>
                            </a>
                        </li>
                        <?php if (in_array($user['role'], ['super_admin', 'admin'], true)): ?>
                        <li class="cms-nav-item<?= $activeNav === 'polling-units' ? ' is-active' : '' ?>">
                            <a href="<?= e(url('admin/polling-units.php')) ?>">
                                <i class="fas fa-building-columns cms-nav-item__icon" aria-hidden="true"></i>
                                <span>Polling Unit Management</span>
                            </a>
                        </li>
                        <?php else: ?>
                        <?php render_soon_item('fa-building-columns', 'Polling units'); ?>
                        <?php endif; ?>
                        <li class="cms-nav-item<?= $activeNav === 'results' ? ' is-active' : '' ?>">
                            <a href="<?= e(url('results/index.php')) ?>">
                                <i class="fas fa-square-poll-vertical cms-nav-item__icon" aria-hidden="true"></i>
                                <span>Results</span>
                            </a>
                        </li>
                        <?php if (in_array($user['role'], ['super_admin', 'admin'], true)): ?>
                        <li class="cms-nav-item<?= $activeNav === 'live' ? ' is-active' : '' ?>">
                            <a href="<?= e(url('results/live.php')) ?>">
                                <i class="fas fa-tower-broadcast cms-nav-item__icon" aria-hidden="true"></i>
                                <span>Live sheets</span>
                            </a>
                        </li>
                        <li class="cms-nav-item<?= $activeNav === 'reviews' ? ' is-active' : '' ?>">
                            <a href="<?= e(url('results/reviews.php')) ?>">
                                <i class="fas fa-scale-balanced cms-nav-item__icon" aria-hidden="true"></i>
                                <span>Result review</span>
                            </a>
                        </li>
                        <li class="cms-nav-item<?= $activeNav === 'incidents' ? ' is-active' : '' ?>">
                            <a href="<?= e(url('results/incidents.php')) ?>">
                                <i class="fas fa-triangle-exclamation cms-nav-item__icon" aria-hidden="true"></i>
                                <span>Integrity incidents</span>
                            </a>
                        </li>
                        <li class="cms-nav-item<?= $activeNav === 'locked-accounts' ? ' is-active' : '' ?>">
                            <a href="<?= e(url('admin/locked-accounts.php')) ?>">
                                <i class="fas fa-user-lock cms-nav-item__icon" aria-hidden="true"></i>
                                <span>Locked accounts</span>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="cms-nav-group">
                    <button type="button" class="cms-nav-group__label" aria-expanded="true">
                        <span>Reports</span>
                        <i class="fas fa-chevron-down chevron" aria-hidden="true"></i>
                    </button>
                    <ul class="cms-nav-group__items">
                        <?php if (in_array($user['role'], ['super_admin', 'admin', 'supervisor', 'collation_officer', 'viewer'], true)): ?>
                        <li class="cms-nav-item<?= $activeNav === 'collation' ? ' is-active' : '' ?>">
                            <a href="<?= e(url('collation/index.php')) ?>">
                                <i class="fas fa-layer-group cms-nav-item__icon" aria-hidden="true"></i>
                                <span>Collation</span>
                            </a>
                        </li>
                        <?php else: ?>
                        <?php render_soon_item('fa-layer-group', 'Collation'); ?>
                        <?php endif; ?>
                        <?php if (in_array($user['role'], ['super_admin', 'admin', 'supervisor', 'collation_officer', 'viewer'], true)): ?>
                        <li class="cms-nav-item<?= $activeNav === 'reports' ? ' is-active' : '' ?>">
                            <a href="<?= e(url('reports/index.php')) ?>">
                                <i class="fas fa-file-lines cms-nav-item__icon" aria-hidden="true"></i>
                                <span>Reports</span>
                            </a>
                        </li>
                        <?php else: ?>
                        <?php render_soon_item('fa-file-lines', 'Reports'); ?>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </nav>
            <div class="cms-sidebar__footer">
                <button type="button" class="cms-sidebar__collapse" id="cmsSidebarCollapse" aria-label="Collapse sidebar">
                    <i class="fas fa-angles-left" aria-hidden="true"></i><span>Collapse</span>
                </button>
            </div>
        </aside>
        <div class="cms-main">
            <header class="cms-topbar">
                <button type="button" class="cms-topbar__menu" id="cmsMobileMenu" aria-label="Open menu"><i class="fas fa-bars" aria-hidden="true"></i></button>
                <div class="cms-topbar__search">
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <label class="visually-hidden" for="cmsGlobalSearch">Search menu</label>
                    <input type="search" id="cmsGlobalSearch" placeholder="Search menu" autocomplete="off">
                </div>
                <div class="cms-topbar__actions">
                    <div class="cms-theme-picker">
                        <label for="cmsThemeSelect" class="cms-theme-picker__label"><i class="fas fa-palette" aria-hidden="true"></i> Theme</label>
                        <select id="cmsThemeSelect" class="cms-theme-picker__select" aria-label="Select dashboard theme">
                            <?php foreach (['gold' => 'Gold', 'ocean' => 'Ocean Blue', 'emerald' => 'Emerald', 'royal' => 'Royal Purple', 'sunset' => 'Sunset Orange'] as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= $theme === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="cmsModeSelect" class="cms-theme-picker__label"><i class="fas fa-circle-half-stroke" aria-hidden="true"></i> Mode</label>
                        <select id="cmsModeSelect" class="cms-theme-picker__select" aria-label="Select color mode">
                            <option value="dark"<?= $mode === 'dark' ? ' selected' : '' ?>>Dark</option>
                            <option value="light"<?= $mode === 'light' ? ' selected' : '' ?>>Light</option>
                        </select>
                    </div>
                    <div class="cms-profile">
                        <button type="button" class="cms-topbar__btn" id="cmsNotifyBtn" aria-label="Notifications" aria-expanded="false" aria-controls="cmsNotifyPanel">
                            <i class="fas fa-bell" aria-hidden="true"></i>
                        </button>
                        <div class="cms-notify-panel" id="cmsNotifyPanel">
                            <div class="cms-notify-panel__head"><span>Notifications</span></div>
                            <div class="cms-notify-empty">No notifications yet.</div>
                        </div>
                    </div>
                    <div class="cms-profile" id="cmsProfile">
                        <button type="button" class="cms-profile__trigger" id="cmsProfileTrigger" aria-expanded="false" aria-controls="cmsProfileDropdown">
                            <div class="cms-profile__avatar" aria-hidden="true"><?= e(shell_initials($user['full_name'])) ?></div>
                            <div class="cms-profile__info">
                                <strong><?= e($user['full_name']) ?></strong>
                                <span><?= e($roleLabel) ?></span>
                            </div>
                            <i class="fas fa-chevron-down cms-profile__chevron" aria-hidden="true"></i>
                        </button>
                        <div class="cms-dropdown" id="cmsProfileDropdown">
                            <div class="cms-dropdown__identity">
                                <strong><?= e($user['username']) ?></strong>
                                <span><?= e($user['email']) ?></span>
                            </div>
                            <div class="cms-dropdown__divider"></div>
                            <a class="cms-dropdown__link" href="<?= e(url('portal/change-password.php')) ?>"><i class="fas fa-key" aria-hidden="true"></i> Change password</a>
                            <form method="post" action="<?= e(url('portal/logout.php')) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <button type="submit"><i class="fas fa-right-from-bracket" aria-hidden="true"></i> Sign out</button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>
            <main class="cms-page" id="cmsPageMain">
    <?php
}

function render_shell_end(): void
{
    $user = auth_user();
    $agentOffline = is_array($user) && ($user['role'] ?? '') === 'polling_agent';
    ?>
            </main>
            <p class="cms-page__footnote">Access is restricted to authorized personnel.</p>
        </div>
    </div>
    <script nonce="<?= e(app_csp_nonce()) ?>"><?= dashboard_js() ?></script>
    <?php if ($agentOffline): ?>
    <?php if (strpos((string) ($GLOBALS['em_offline_store_loaded'] ?? ''), '1') !== 0): ?>
    <script nonce="<?= e(app_csp_nonce()) ?>" src="<?= e(asset_url('assets/js/offline-store.js')) ?>"></script>
    <?php endif; ?>
    <script nonce="<?= e(app_csp_nonce()) ?>" src="<?= e(asset_url('assets/js/offline-logout.js')) ?>"></script>
    <?php endif; ?>
</body>
</html>
    <?php
}

function render_soon_item(string $icon, string $label): void
{
    ?>
    <li class="cms-nav-item is-soon">
        <span class="cms-nav-item__static">
            <i class="fas <?= e($icon) ?> cms-nav-item__icon" aria-hidden="true"></i>
            <span><?= e($label) ?></span>
            <span class="cms-nav-item__badge">Soon</span>
        </span>
    </li>
    <?php
}

function shell_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $letters .= strtoupper(substr($part, 0, 1));
        if (strlen($letters) === 2) {
            break;
        }
    }

    return $letters !== '' ? $letters : 'EM';
}

function format_last_login(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
    if ($parsed === false) {
        return '—';
    }

    return $parsed->format('M j, Y');
}

/**
 * @param list<string> $allowed
 */
function shell_pref(string $cookie, array $allowed, string $default): string
{
    $value = $_COOKIE[$cookie] ?? '';
    if (!is_string($value) || !in_array($value, $allowed, true)) {
        return $default;
    }

    return $value;
}
