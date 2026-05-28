<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);

function formatHistoryTestNameForExport(string $rawName): string
{
    $name = str_replace("\u{2014}", '-', $rawName);
    $name = preg_replace('/^__pest_evaluable_/', '', $name);
    $name = preg_replace('/_+/', ' ', $name);
    $name = trim((string) preg_replace('/\s+/', ' ', $name));
    if ($name === '') {
        return $rawName;
    }

    return strtoupper(substr($name, 0, 1)) . substr($name, 1);
}

function normalizeTestKeyForExport(string $name): string
{
    $normalized = trim($name);
    $normalized = str_replace('\\', '/', $normalized);
    $normalized = preg_replace('/^__pest_evaluable_/i', '', $normalized);
    $normalized = preg_replace('/^[^\s]+::/', '', $normalized);
    $normalized = preg_replace('/([a-z0-9])([A-Z])/u', '$1 $2', $normalized);
    $normalized = preg_replace('#^(?:tests?/[^:]+/)+#i', '', $normalized);
    $normalized = preg_replace('/^(?:feature|unit|suite|tests?)\s*[:\-\|]\s*/i', '', $normalized);
    $normalized = str_replace('::', ' ', $normalized);
    $normalized = str_replace('/', ' ', $normalized);
    $normalized = str_replace('_', ' ', $normalized);
    $normalized = preg_replace('/^\s*test\s*[:\-\|]?\s*/i', '', $normalized);
    $normalized = str_replace('…', '...', $normalized);
    if (strpos($normalized, '...') !== false) {
        $beforeEllipsis = trim((string) preg_replace('/\.\.\..*$/', '', $normalized));
        if ($beforeEllipsis !== '') {
            $tokens = preg_split('/\s+/', $beforeEllipsis);
            if (is_array($tokens) && count($tokens) > 1) {
                array_pop($tokens);
                $candidate = trim((string) implode(' ', $tokens));
                if ($candidate !== '') {
                    $normalized = $candidate;
                }
            } else {
                $normalized = $beforeEllipsis;
            }
        }
    }
    $normalized = strtolower(trim((string) preg_replace('/\s+/', ' ', $normalized)));
    $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized);
    $normalized = trim((string) preg_replace('/\s+/', ' ', $normalized));

    return str_replace(' ', '', $normalized);
}

function classToTestFilePath(string $className): string
{
    $parts = explode('\\', ltrim($className, '\\'));
    if (!empty($parts) && $parts[0] === 'P') {
        array_shift($parts);
    }
    if (!empty($parts) && $parts[0] === 'Tests') {
        array_shift($parts);
        return 'tests/' . implode('/', $parts) . '.php';
    }

    return $className;
}

function resolveSuiteFromClass(string $className): ?string
{
    if (strpos($className, '\\Tests\\Unit\\') !== false) {
        return 'unit';
    }
    if (strpos($className, '\\Tests\\Feature\\') !== false) {
        return 'feature';
    }

    return null;
}

$command = 'cd ' . escapeshellarg($projectRoot)
    . ' && PHPUNIT_RESULT_CACHE=/dev/null php vendor/bin/pest --list-tests --colors=never 2>/dev/null';
$pestOutput = shell_exec($command);

$catalog = [];
$exportNames = [];
$byFile = [];

foreach (preg_split('/\r\n|\r|\n/', (string) $pestOutput) as $line) {
    $line = trim($line);
    if (strpos($line, '- ') !== 0 || strpos($line, '::') === false) {
        continue;
    }

    $identifier = trim(substr($line, 2));
    [$className, $rawName] = explode('::', $identifier, 2);
    $suite = resolveSuiteFromClass($className);
    if ($suite === null) {
        continue;
    }

    $file = classToTestFilePath($className);
    $exportName = formatHistoryTestNameForExport($rawName);
    $key = strtolower($file) . "\0" . strtolower($rawName);

    if (isset($catalog[$key])) {
        continue;
    }

    $catalog[$key] = [
        'file' => $file,
        'raw_name' => $rawName,
        'export_name' => $exportName,
        'suite' => $suite,
    ];
    $exportNames[$exportName] = ($exportNames[$exportName] ?? 0) + 1;
    $byFile[$file] = ($byFile[$file] ?? 0) + 1;
}

$duplicateExportNames = [];
foreach ($exportNames as $name => $count) {
    if ($count > 1) {
        $duplicateExportNames[$name] = $count;
    }
}

uksort($catalog, static function ($a, $b) use ($catalog) {
    return strcmp($catalog[$a]['file'] . $catalog[$a]['export_name'], $catalog[$b]['file'] . $catalog[$b]['export_name']);
});

$outDir = $projectRoot . '/storage/cache';
if (!is_dir($outDir)) {
    @mkdir($outDir, 0775, true);
}

$catalogPath = $outDir . '/pest-export-catalog-names.json';
$namesPath = $outDir . '/pest-export-test-names.txt';

file_put_contents($catalogPath, json_encode(array_values($catalog), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents($namesPath, implode("\n", array_map(static fn ($row) => $row['export_name'], array_values($catalog))));

echo 'Pest catalog tests: ' . count($catalog) . PHP_EOL;
echo 'Unique export test names: ' . count($exportNames) . PHP_EOL;
echo 'Duplicate export test names (would collapse in ensureUniqueExportTestNames): ' . count($duplicateExportNames) . PHP_EOL;
echo 'Written: ' . $catalogPath . PHP_EOL;
echo 'Written: ' . $namesPath . PHP_EOL;

if ($duplicateExportNames !== []) {
    echo PHP_EOL . 'Top duplicate export names:' . PHP_EOL;
    arsort($duplicateExportNames);
    $i = 0;
    foreach ($duplicateExportNames as $name => $count) {
        echo "  [{$count}x] {$name}" . PHP_EOL;
        if (++$i >= 25) {
            break;
        }
    }
}
