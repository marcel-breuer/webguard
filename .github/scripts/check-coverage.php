<?php

declare(strict_types=1);

/**
 * Enforce total and patch line coverage from a clover report.
 *
 * Total coverage uses the clover project metrics. Patch coverage counts the
 * executable statements on lines added or changed between --base and --head
 * inside --path, so it needs only local git data and no external service.
 */
$options = getopt('', [
    'clover:',
    'base::',
    'head::',
    'path::',
    'total-min::',
    'patch-min::',
    'summary::',
]);

$cloverPath = (string) ($options['clover'] ?? '');
$base = trim((string) ($options['base'] ?? ''));
$head = trim((string) ($options['head'] ?? '')) ?: 'HEAD';
$pathPrefix = rtrim((string) ($options['path'] ?? 'app'), '/') . '/';
$totalMin = isset($options['total-min']) ? (float) $options['total-min'] : null;
$patchMin = isset($options['patch-min']) ? (float) $options['patch-min'] : null;
$summaryPath = (string) ($options['summary'] ?? '');

$lines = ['## Coverage', ''];
$failures = [];

if ($cloverPath === '' || ! is_file($cloverPath)) {
    $failures[] = 'Coverage report `' . ($cloverPath ?: '(none)') . '` was not found; the test run probably failed before writing it.';
    finish($lines, $failures, $summaryPath);
}

$clover = loadClover($cloverPath);
$total = percentage($clover['covered'], $clover['statements']);

$lines[] = '| Scope | Covered lines | Executable lines | Coverage | Minimum |';
$lines[] = '| --- | ---: | ---: | ---: | ---: |';
$lines[] = row('Total', $clover['covered'], $clover['statements'], $total, $totalMin);

if ($totalMin !== null && $total < $totalMin) {
    $failures[] = sprintf('Total coverage %s is below the minimum of %s.', formatPercent($total), formatPercent($totalMin));
}

$uncovered = [];

if ($base !== '') {
    $changedLines = changedLines($base, $head, $pathPrefix);
    $patchCovered = 0;
    $patchStatements = 0;

    foreach ($changedLines as $file => $numbers) {
        $statements = statementsFor($clover['files'], $file);

        foreach ($numbers as $number) {
            if (! array_key_exists($number, $statements)) {
                continue;
            }

            $patchStatements++;

            if ($statements[$number]) {
                $patchCovered++;
            } else {
                $uncovered[$file][] = $number;
            }
        }
    }

    $patch = $patchStatements === 0 ? null : percentage($patchCovered, $patchStatements);
    $lines[] = row('Changed lines in `' . $pathPrefix . '`', $patchCovered, $patchStatements, $patch, $patchMin);

    if ($patch !== null && $patchMin !== null && $patch < $patchMin) {
        $failures[] = sprintf('Patch coverage %s is below the minimum of %s.', formatPercent($patch), formatPercent($patchMin));
    }
}

if ($uncovered !== []) {
    ksort($uncovered);
    $lines[] = '';
    $lines[] = '<details><summary>Uncovered changed lines</summary>';
    $lines[] = '';

    foreach ($uncovered as $file => $numbers) {
        $lines[] = '- `' . $file . '`: ' . implode(', ', compressRanges($numbers));
    }

    $lines[] = '';
    $lines[] = '</details>';
}

finish($lines, $failures, $summaryPath);

/**
 * @return array{statements: int, covered: int, files: array<string, array<int, bool>>}
 */
function loadClover(string $path): array
{
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_file($path);
    libxml_use_internal_errors($previous);

    if ($xml === false) {
        fwrite(STDERR, 'Unable to parse clover report ' . $path . PHP_EOL);

        exit(1);
    }

    $files = [];
    $statements = 0;
    $covered = 0;

    foreach ($xml->xpath('//file') ?: [] as $file) {
        $name = str_replace('\\', '/', (string) $file['name']);

        foreach ($file->line as $line) {
            if ((string) $line['type'] !== 'stmt') {
                continue;
            }

            $isCovered = (int) $line['count'] > 0;
            $files[$name][(int) $line['num']] = $isCovered;
            $statements++;
            $covered += $isCovered ? 1 : 0;
        }
    }

    return ['statements' => $statements, 'covered' => $covered, 'files' => $files];
}

/**
 * Clover paths are absolute inside the container, so match by path suffix.
 *
 * @param  array<string, array<int, bool>>  $files
 * @return array<int, bool>
 */
function statementsFor(array $files, string $relativePath): array
{
    foreach ($files as $name => $statements) {
        if ($name === $relativePath || str_ends_with($name, '/' . $relativePath)) {
            return $statements;
        }
    }

    return [];
}

/**
 * @return array<string, list<int>>
 */
function changedLines(string $base, string $head, string $pathPrefix): array
{
    $diff = run(['git', 'diff', '--no-color', '--no-ext-diff', '--unified=0', '--diff-filter=AMR', $base, $head, '--', $pathPrefix]);
    $changed = [];
    $file = null;

    foreach (explode("\n", $diff) as $line) {
        if (str_starts_with($line, '+++ ')) {
            $target = substr($line, 4);
            $file = $target === '/dev/null' ? null : preg_replace('#^b/#', '', $target);

            continue;
        }

        if ($file === null || ! preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $matches)) {
            continue;
        }

        $start = (int) $matches[1];
        $count = isset($matches[2]) ? (int) $matches[2] : 1;

        for ($number = $start; $number < $start + $count; $number++) {
            $changed[$file][] = $number;
        }
    }

    return $changed;
}

/**
 * @param  list<string>  $command
 */
function run(array $command): string
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (! is_resource($process)) {
        fwrite(STDERR, 'Unable to start process: ' . implode(' ', $command) . PHP_EOL);

        exit(1);
    }

    $output = (string) stream_get_contents($pipes[1]);
    $error = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    if (proc_close($process) !== 0) {
        fwrite(STDERR, trim($error) . PHP_EOL);

        exit(1);
    }

    return $output;
}

function percentage(int $covered, int $total): float
{
    return $total === 0 ? 100.0 : $covered / $total * 100;
}

function formatPercent(float $value): string
{
    // Truncate instead of rounding so 79.99 % is never displayed as passing 80 %.
    return number_format(floor($value * 100) / 100, 2) . ' %';
}

function row(string $scope, int $covered, int $total, ?float $coverage, ?float $minimum): string
{
    return sprintf(
        '| %s | %d | %d | %s | %s |',
        $scope,
        $covered,
        $total,
        $coverage === null ? 'n/a (no executable changes)' : formatPercent($coverage),
        $minimum === null ? '-' : formatPercent($minimum),
    );
}

/**
 * @param  list<int>  $numbers
 * @return list<string>
 */
function compressRanges(array $numbers): array
{
    sort($numbers);
    $ranges = [];
    $start = $previous = array_shift($numbers);

    foreach ([...$numbers, null] as $number) {
        if ($number !== null && $number === $previous + 1) {
            $previous = $number;

            continue;
        }

        $ranges[] = $start === $previous ? (string) $start : $start . '-' . $previous;
        $start = $previous = $number;
    }

    return $ranges;
}

/**
 * @param  list<string>  $lines
 * @param  list<string>  $failures
 */
function finish(array $lines, array $failures, string $summaryPath): never
{
    $lines[] = '';
    $lines[] = $failures === [] ? 'Coverage thresholds passed.' : '**Coverage check failed.**';

    foreach ($failures as $failure) {
        $lines[] = '- ' . $failure;
    }

    $markdown = implode(PHP_EOL, $lines) . PHP_EOL;

    if ($summaryPath !== '') {
        file_put_contents($summaryPath, $markdown, FILE_APPEND);
    }

    echo $markdown;

    exit($failures === [] ? 0 : 1);
}
