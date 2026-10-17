<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/dashboard_view.php';

auth_boot();
$signedIn = auth_user();
if ($signedIn !== null && $signedIn['role'] === 'polling_agent') {
    redirect_to('agent/dashboard.php');
}
render_workspace(
    'Field reporting',
    'Field workspace for collation officers.',
    ['collation_officer']
);
