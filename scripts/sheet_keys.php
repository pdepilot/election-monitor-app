<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/sheet_integrity.php';

function keys_usage(): void
{
    fwrite(STDOUT, <<<TXT
Manage the result-sheet HMAC key file. Key material is never printed.

Usage:
  php scripts/sheet_keys.php status   Show the key file location, key ids, and the current key id.
  php scripts/sheet_keys.php init     Create the key file with a new random key (refuses to overwrite).
  php scripts/sheet_keys.php rotate   Add a new random key and make it current. Old keys stay so old records still verify.
  php scripts/sheet_keys.php resign   Re-sign upload records made with an older key, only if they still verify with that key.

The key file lives outside the web root, the project, and the database. Back it up separately.
Removing an old key makes every record and event signed with it unverifiable.

TXT);
}

function keys_file(): string
{
    return app_config()['sheet_key_file'];
}

function keys_guard_location(string $file): void
{
    $appRoot = realpath(dirname(__DIR__));
    $webRoot = realpath(dirname(__DIR__, 2));
    $targets = [dirname($file), $file];
    foreach ($targets as $target) {
        if ($webRoot !== false && function_exists('app_path_is_under') && app_path_is_under($target, $webRoot)) {
            fwrite(STDERR, "Refusing: the key file must not be inside the web root ({$webRoot}).\n");
            exit(1);
        }
        if ($appRoot !== false && function_exists('app_path_is_under') && app_path_is_under($target, $appRoot)) {
            fwrite(STDERR, "Refusing: the key file must not be inside the application directory ({$appRoot}).\n");
            exit(1);
        }
    }
}

/**
 * @param array{current: string, keys: array<string, string>} $data
 */
function keys_write(string $file, array $data): void
{
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        fwrite(STDERR, "The key directory could not be created.\n");
        exit(1);
    }
    $tmp = $dir . DIRECTORY_SEPARATOR . '.sheet-hmac.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) {
        fwrite(STDERR, "The key file could not be written.\n");
        exit(1);
    }
    @chmod($tmp, 0600);
    if (is_file($file)) {
        @chmod($file, 0600);
    }
    if (!rename($tmp, $file)) {
        @unlink($tmp);
        fwrite(STDERR, "The key file could not be replaced.\n");
        exit(1);
    }
    @chmod($file, 0600);
}

function keys_new_id(array $existing): string
{
    do {
        $id = 'k' . gmdate('Ymd') . '-' . bin2hex(random_bytes(3));
    } while (isset($existing[$id]));

    return $id;
}

/**
 * @return array{current: string, keys: array<string, string>}
 */
function keys_read_raw(string $file): array
{
    $data = json_decode((string) @file_get_contents($file), true);
    if (!is_array($data) || !is_array($data['keys'] ?? null) || !is_string($data['current'] ?? null)) {
        fwrite(STDERR, "The key file is missing or invalid. Run init, or restore it from backup.\n");
        exit(1);
    }

    return $data;
}

$command = $argv[1] ?? '';
$file = keys_file();

switch ($command) {
    case 'status':
        $keys = sheet_keys(true);
        fwrite(STDOUT, 'Key file: ' . $file . PHP_EOL);
        fwrite(STDOUT, 'State: ' . ($keys['current'] !== null ? 'ready' : 'NOT USABLE (' . $keys['error'] . ')') . PHP_EOL);
        fwrite(STDOUT, 'Current key id: ' . ($keys['current'] ?? '-') . PHP_EOL);
        fwrite(STDOUT, 'Key ids: ' . ($keys['keys'] === [] ? '-' : implode(', ', array_keys($keys['keys']))) . PHP_EOL);
        exit($keys['current'] !== null ? 0 : 1);

    case 'init':
        keys_guard_location($file);
        if (file_exists($file)) {
            fwrite(STDERR, "A key file already exists. Use rotate to add a key.\n");
            exit(1);
        }
        $id = keys_new_id([]);
        keys_write($file, ['current' => $id, 'keys' => [$id => base64_encode(random_bytes(32))]]);
        fwrite(STDOUT, "Key file created with key id {$id}.\n");
        exit(0);

    case 'rotate':
        keys_guard_location($file);
        $data = keys_read_raw($file);
        $id = keys_new_id($data['keys']);
        $data['keys'][$id] = base64_encode(random_bytes(32));
        $data['current'] = $id;
        keys_write($file, $data);
        fwrite(STDOUT, "New current key id {$id}. Older keys are kept for verification. Run resign to move upload records to it.\n");
        exit(0);

    case 'resign':
        $keys = sheet_keys(true);
        if ($keys['current'] === null) {
            fwrite(STDERR, "No usable current key. Nothing was changed.\n");
            exit(1);
        }
        $moved = 0;
        $refused = 0;
        $rows = db()->query(
            "SELECT * FROM result_sheet_uploads WHERE meta_hmac IS NOT NULL AND hmac_key_id <> " . db()->quote($keys['current'])
        )->fetchAll();
        foreach ($rows as $row) {
            if (sheet_row_signature($row) !== 'valid') {
                $refused++;
                fwrite(STDOUT, "Record {$row['id']}: does not verify with its own key, left unchanged.\n");
                continue;
            }
            sheet_with_chain_lock(static function () use ($row, &$moved): void {
                $signed = sheet_sign_row($row);
                if ($signed === null) {
                    throw new RuntimeException('Current key became unavailable.');
                }
                $update = db()->prepare('UPDATE result_sheet_uploads SET meta_hmac = :hmac, hmac_key_id = :key_id WHERE id = :id AND meta_hmac = :old');
                $update->execute(['hmac' => $signed['hmac'], 'key_id' => $signed['key_id'], 'id' => (int) $row['id'], 'old' => (string) $row['meta_hmac']]);
                if ($update->rowCount() === 1) {
                    sheet_event('resigned', [
                        'upload_id' => (int) $row['id'],
                        'polling_unit_id' => (int) $row['polling_unit_id'],
                        'detail' => 'Record signature moved from key ' . $row['hmac_key_id'] . ' to ' . $signed['key_id'] . ' after verifying.',
                    ]);
                    $moved++;
                }
            });
        }
        fwrite(STDOUT, "Re-signed {$moved} record(s). Left unchanged: {$refused}.\n");
        exit($refused === 0 ? 0 : 1);

    default:
        keys_usage();
        exit($command === 'help' || $command === '--help' ? 0 : 1);
}
