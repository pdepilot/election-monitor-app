<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/polling_units.php';

function import_polling_units_usage(): void
{
    fwrite(STDOUT, <<<'TXT'
Import constituency polling units from a CSV file (Ohaji/Egbema or Oguta).

Usage:
  php scripts/import_polling_units.php --file=register.csv
  php scripts/import_polling_units.php --file=register.csv --apply

The first command is a dry run and does not write. Add --apply to insert new
polling-unit IDs. Existing IDs are skipped and are not overwritten.

Columns:
  polling_unit_id,polling_unit_name,ward_name,lga_name,classification

A header-only template is at database/polling_units_template.csv.
Ward targets are validation checks. This import does not invent missing units
and does not mark the file as checked against the INEC register.

TXT);
}

$options = getopt('', ['file:', 'apply', 'help']);
if ($options === false || isset($options['help']) || !isset($options['file'])) {
    import_polling_units_usage();
    exit(isset($options['help']) ? 0 : 1);
}

$path = (string) $options['file'];
if (!is_file($path) || !is_readable($path)) {
    fwrite(STDERR, "The CSV file could not be read.\n");
    exit(1);
}
$size = filesize($path);
if ($size === false || $size > PU_IMPORT_MAX_BYTES) {
    fwrite(STDERR, "The CSV file must be 2 MB or smaller.\n");
    exit(1);
}
$contents = file_get_contents($path);
if (!is_string($contents)) {
    fwrite(STDERR, "The CSV file could not be read.\n");
    exit(1);
}

$apply = array_key_exists('apply', $options);
if (!pu_schema_ready()) {
    fwrite(STDERR, "Apply database/migration_polling_units.sql before importing.\n");
    exit(1);
}

$report = pu_import_contents($contents, $apply, null);
if (!$report['ok']) {
    fwrite(STDERR, $report['message'] . "\n");
    exit(1);
}

$insertLabel = $report['projected'] ? 'Would insert' : 'Inserted';
fwrite(STDOUT, $apply ? "Applied.\n" : "Dry run. No rows were written.\n");
fwrite(STDOUT, $insertLabel . ': ' . $report['inserted'] . "\n");
fwrite(STDOUT, 'Updated: ' . $report['updated'] . "\n");
fwrite(STDOUT, 'Skipped: ' . $report['skipped'] . "\n");
fwrite(STDOUT, 'Failed: ' . $report['failed'] . "\n");

foreach (array_slice($report['failures'], 0, 40) as $failure) {
    fwrite(STDOUT, 'Failed line ' . $failure['line'] . ' [' . $failure['polling_unit_id'] . '] ' . $failure['message'] . "\n");
}
foreach (array_slice($report['skipped_rows'], 0, 40) as $skipped) {
    fwrite(STDOUT, 'Skipped line ' . $skipped['line'] . ' [' . $skipped['polling_unit_id'] . '] ' . $skipped['message'] . "\n");
}

$expected = 0;
$actual = 0;
$mismatches = 0;
foreach ($report['discrepancies'] as $gap) {
    $expected += $gap['expected'];
    $actual += $gap['actual'];
    if ($gap['gap'] !== 0) {
        $mismatches++;
        $label = $report['projected'] ? 'projected' : 'in database';
        fwrite(STDOUT, $gap['ward'] . ' expected ' . $gap['expected'] . ', ' . $label . ' ' . $gap['actual'] . ', gap ' . $gap['gap'] . "\n");
    }
}
fwrite(STDOUT, 'Ward targets: expected ' . $expected . ', ' . ($report['projected'] ? 'projected' : 'in database') . ' ' . $actual . ".\n");
if ($mismatches === 0 && $report['discrepancies'] !== []) {
    fwrite(STDOUT, "Ward counts match the supplied targets. The file has not been checked against the current INEC register.\n");
} else {
    fwrite(STDOUT, "Ward counts do not match the supplied targets. Missing polling units were not created.\n");
}

exit(0);
