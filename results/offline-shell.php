<?php
declare(strict_types=1);

/*
 * Public, data-free page shell for offline result entry. The service worker caches it (fetched
 * without cookies) and serves it only when a navigation to results/entry cannot reach the
 * server. Unit details come from the device copy in IndexedDB; the server re-checks everything
 * when a result is sent. No session is started and nothing user-specific is rendered here.
 */

require_once dirname(__DIR__) . '/includes/result_submissions.php';

header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Offline result entry | Election Monitoring Portal</title>
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/offline-shell.css')) ?>">
</head>
<body class="em-shell">
    <main class="em-shell__main">
        <header class="em-shell__header">
            <h1>Offline result entry</h1>
            <p class="em-shell__notice"><?= e(SUBMISSION_NOTICE) ?></p>
        </header>
        <p id="em-shell-boot" class="pu-alert pu-alert--error" role="alert">Offline entry could not start. Some files it needs are not stored on this device, or JavaScript is turned off. Reconnect and open your result page while online.</p>
        <div id="em-shell-app" hidden
            data-entry-url="<?= e(url('results/entry.php')) ?>"
            data-submit-url="<?= e(url('results/api-submit.php')) ?>"
            data-status-url="<?= e(url('results/api-status.php')) ?>"
            data-dashboard-url="<?= e(url('agent/dashboard.php')) ?>"
            data-notice="<?= e(SUBMISSION_NOTICE) ?>"
            data-scripts="<?= e(json_encode([asset_url('assets/js/offline-sync.js'), asset_url('assets/js/offline-entry.js')], JSON_UNESCAPED_SLASHES)) ?>"></div>
    </main>
    <script src="<?= e(asset_url('assets/js/offline-store.js')) ?>"></script>
    <script src="<?= e(asset_url('assets/js/offline-shell.js')) ?>"></script>
</body>
</html>
