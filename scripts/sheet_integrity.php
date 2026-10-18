<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/sheet_integrity.php';

function integrity_usage(): void
{
    fwrite(STDOUT, <<<TXT
Check result-sheet integrity.

Usage:
  php scripts/sheet_integrity.php verify
      Re-hash every stored sheet, check every record signature, and walk the signed event trail.
      Opens incidents for failures. Exit code 1 when anything fails.

  php scripts/sheet_integrity.php baseline --id=N --confirm
      For a legacy upload (no fingerprint from upload time), record today's SHA-256 as a baseline.
      The upload stays UNVERIFIED LEGACY. The baseline only shows the file has not changed since now.

  php scripts/sheet_integrity.php checkpoint [save]
      Print a signed JSON checkpoint of all upload fingerprints and the head of the event trail.
      With "save", also write a copy under private_dir/checkpoints/ (outside the web root).
      Copy it to a location the application server cannot write to (for example, email it to a
      separate account or store it on separate media). Later checkpoints can be compared with it.

TXT);
}

$command = $argv[1] ?? '';

if ($command === 'verify') {
    $counts = [];
    $bad = 0;
    $rows = db()->query('SELECT * FROM result_sheet_uploads ORDER BY id ASC')->fetchAll();
    foreach ($rows as $row) {
        $check = sheet_verify($row, null);
        $label = sheet_status_label($check['status']);
        $counts[$label] = ($counts[$label] ?? 0) + 1;
        if (!in_array($check['status'], [SHEET_STATUS_VERIFIED, SHEET_STATUS_LEGACY], true)) {
            $bad++;
            fwrite(STDOUT, "Record {$row['id']}: {$label}\n");
        }
    }
    foreach ($counts as $label => $count) {
        fwrite(STDOUT, "{$label}: {$count}\n");
    }
    fwrite(STDOUT, 'Uploads checked: ' . count($rows) . PHP_EOL);
    $chain = sheet_verify_event_chain();
    fwrite(STDOUT, 'Events checked: ' . $chain['total'] . PHP_EOL);
    foreach ($chain['problems'] as $problem) {
        fwrite(STDOUT, 'Event trail: ' . $problem . PHP_EOL);
    }
    $ok = $bad === 0 && $chain['problems'] === [];
    fwrite(STDOUT, $ok ? "RESULT: no integrity problems found\n" : "RESULT: INTEGRITY PROBLEMS FOUND\n");
    exit($ok ? 0 : 1);
}

if ($command === 'baseline') {
    $id = null;
    $confirmed = false;
    foreach (array_slice($argv, 2) as $arg) {
        if (preg_match('/\A--id=(\d+)\z/', $arg, $match)) {
            $id = (int) $match[1];
        } elseif ($arg === '--confirm') {
            $confirmed = true;
        }
    }
    if (!is_int($id) || $id < 1 || !$confirmed) {
        integrity_usage();
        exit(1);
    }
    $find = db()->prepare('SELECT * FROM result_sheet_uploads WHERE id = :id');
    $find->execute(['id' => $id]);
    $row = $find->fetch();
    if (!is_array($row) || !sheet_is_legacy($row)) {
        fwrite(STDERR, "Only a legacy upload without a fingerprint can be given a baseline.\n");
        exit(1);
    }
    if ((string) ($row['legacy_baseline_sha256'] ?? '') !== '') {
        fwrite(STDERR, "This upload already has a baseline. It is never replaced.\n");
        exit(1);
    }
    $path = sheet_stored_path($row);
    $hash = $path !== null && is_file($path) ? hash_file('sha256', $path) : false;
    if (!is_string($hash)) {
        fwrite(STDERR, "The stored file is missing, so no baseline was recorded.\n");
        exit(1);
    }
    sheet_with_chain_lock(static function () use ($row, $hash): void {
        $at = sheet_utc_now();
        $update = db()->prepare(
            'UPDATE result_sheet_uploads SET legacy_baseline_sha256 = :hash, legacy_baseline_at_utc = :at
             WHERE id = :id AND legacy_baseline_sha256 IS NULL'
        );
        $update->execute(['hash' => $hash, 'at' => $at, 'id' => (int) $row['id']]);
        sheet_event('legacy_baseline', [
            'upload_id' => (int) $row['id'],
            'polling_unit_id' => (int) $row['polling_unit_id'],
            'detail' => 'Legacy baseline SHA-256 ' . $hash . ' recorded at ' . $at . ' UTC. Integrity before this time is not established.',
        ]);
    });
    fwrite(STDOUT, "Baseline recorded for record {$row['id']}. It remains UNVERIFIED LEGACY.\n");
    exit(0);
}

if ($command === 'checkpoint') {
    $keys = sheet_keys();
    $digest = hash_init('sha256');
    $count = 0;
    foreach (db()->query('SELECT id, sha256, meta_hmac, legacy_baseline_sha256 FROM result_sheet_uploads ORDER BY id ASC') as $row) {
        hash_update($digest, $row['id'] . ':' . ($row['sha256'] ?? '') . ':' . ($row['meta_hmac'] ?? '') . ':' . ($row['legacy_baseline_sha256'] ?? '') . "\n");
        $count++;
    }
    $head = db()->query('SELECT id, event_hmac FROM result_sheet_events ORDER BY id DESC LIMIT 1')->fetch();
    $checkpoint = [
        'type' => 'em-sheet-checkpoint-v1',
        'generated_at_utc' => sheet_utc_now(),
        'uploads' => $count,
        'uploads_digest' => hash_final($digest),
        'last_event_id' => is_array($head) ? (int) $head['id'] : 0,
        'last_event_hmac' => is_array($head) ? (string) ($head['event_hmac'] ?? '') : '',
        'key_id' => $keys['current'],
    ];
    $checkpoint['hmac'] = $keys['current'] !== null
        ? hash_hmac('sha256', json_encode($checkpoint, JSON_UNESCAPED_SLASHES), $keys['keys'][$keys['current']])
        : null;
    $json = json_encode($checkpoint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    fwrite(STDOUT, $json);
    if (($argv[2] ?? '') === 'save') {
        $dir = app_config()['private_dir'] . DIRECTORY_SEPARATOR . 'checkpoints';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            fwrite(STDERR, "Could not create private checkpoint directory.\n");
            exit(1);
        }
        $problems = function_exists('app_storage_path_problems') ? app_storage_path_problems() : [];
        if ($problems !== []) {
            fwrite(STDERR, "Refusing to save checkpoint: " . $problems[0] . "\n");
            exit(1);
        }
        $out = $dir . DIRECTORY_SEPARATOR . 'checkpoint-' . gmdate('Ymd\THis') . 'Z.json';
        if (file_put_contents($out, $json, LOCK_EX) === false) {
            fwrite(STDERR, "Could not write checkpoint file.\n");
            exit(1);
        }
        @chmod($out, 0600);
        fwrite(STDERR, "Saved checkpoint outside the web root.\n");
    }
    if ($keys['current'] === null) {
        fwrite(STDERR, "WARNING: no usable key, so this checkpoint is unsigned.\n");
        exit(1);
    }
    exit(0);
}

integrity_usage();
exit($command === 'help' || $command === '--help' ? 0 : 1);
