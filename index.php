<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/auth.php';

auth_boot();

$user = auth_user();
if ($user === null) {
    redirect_to('portal/login.php');
}

redirect_to(auth_dashboard_for($user['role']));
