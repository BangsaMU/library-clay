<?php

namespace Bangsamu\LibraryClay\Tests\Pdf\OpenParser;

use Bangsamu\LibraryClay\Tests\Pdf\OpenParser\Support\GoldenComparator;
use Bangsamu\LibraryClay\Tests\Pdf\OpenParser\Support\ObjectDumper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every object read by this library has to be identical to what qpdf reads from the same file.
 */
class ObjectGraphTest extends TestCase
{
    public static function fixtures()
    {
        return Fixtures::importable();
    }

    #[DataProvider('fixtures')]
    public function testObjectsMatchQpdf(array $entry)
    {
        $golden = \json_decode(\file_get_contents(__DIR__ . '/fixtures/golden/' . $entry['golden']), true);
        $diffs = GoldenComparator::compare(ObjectDumper::dump(Fixtures::path($entry['file'])), $golden);

        $this->assertSame([], $diffs, $entry['file'] . ' (' . $entry['note'] . ')');
        $this->assertNotEmpty($golden['objects']);
    }
}
