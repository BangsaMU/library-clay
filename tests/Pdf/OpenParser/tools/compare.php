<?php
// Quick manual check: php tests/tools/compare.php [fixture-name ...]
require __DIR__ . '/../../../../vendor/autoload.php';

use Bangsamu\LibraryClay\Tests\Pdf\OpenParser\Support\GoldenComparator;
use Bangsamu\LibraryClay\Tests\Pdf\OpenParser\Support\ObjectDumper;

$fixtures = __DIR__ . '/../fixtures';
$manifest = json_decode(file_get_contents($fixtures . '/manifest.json'), true);
$only = array_slice($argv, 1);
$failed = 0;
foreach ($manifest as $entry) {
    if ($only && !in_array($entry['file'], $only, true)) {
        continue;
    }
    $golden = json_decode(file_get_contents($fixtures . '/golden/' . $entry['golden']), true);
    try {
        $diffs = GoldenComparator::compare(ObjectDumper::dump($fixtures . '/' . $entry['file']), $golden);
    } catch (Throwable $e) {
        $diffs = [get_class($e) . ': ' . $e->getMessage()];
    }
    printf("%-36s %s\n", $entry['file'], $diffs ? 'FAIL' : 'ok');
    foreach (array_slice($diffs, 0, 5) as $d) {
        echo "    - $d\n";
    }
    $failed += $diffs ? 1 : 0;
}
exit($failed ? 1 : 0);
