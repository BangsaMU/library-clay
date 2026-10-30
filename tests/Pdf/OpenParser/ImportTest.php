<?php

namespace Bangsamu\LibraryClay\Tests\Pdf\OpenParser;

use Bangsamu\LibraryClay\Pdf\OpenParser\CrossReference\CrossReference;
use Bangsamu\LibraryClay\Pdf\OpenParser\PdfParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\StreamReader;

class ImportTest extends TestCase
{
    public static function fixtures()
    {
        return Fixtures::importable();
    }

    #[DataProvider('fixtures')]
    public function testImportWithFpdf(array $entry)
    {
        $pdf = new \Bangsamu\LibraryClay\Pdf\OpenParser\Fpdi('P', 'pt');
        $output = $this->importAll($pdf, $entry, function ($pdf) {
            return $pdf->Output('S');
        });
        $this->assertOutputMatchesSource($output, $entry, true);
    }

    #[DataProvider('fixtures')]
    public function testImportWithTcpdf(array $entry)
    {
        if (!\class_exists(\TCPDF::class)) {
            $this->markTestSkipped('TCPDF is not installed.');
        }
        $pdf = new \Bangsamu\LibraryClay\Pdf\OpenParser\Tcpdf\Fpdi('P', 'pt');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $output = $this->importAll($pdf, $entry, function ($pdf) {
            return $pdf->Output('', 'S');
        });
        $this->assertOutputMatchesSource($output, $entry, false);
    }

    #[DataProvider('fixtures')]
    public function testImportWithTfpdf(array $entry)
    {
        if (!\class_exists(\tFPDF::class)) {
            $this->markTestSkipped('tFPDF is not installed.');
        }
        $pdf = new \Bangsamu\LibraryClay\Pdf\OpenParser\Tfpdf\Fpdi('P', 'pt');
        $output = $this->importAll($pdf, $entry, function ($pdf) {
            return $pdf->Output('S');
        });
        $this->assertOutputMatchesSource($output, $entry, false);
    }

    #[DataProvider('fixtures')]
    public function testImportWithMpdf(array $entry)
    {
        if (!\class_exists(\Mpdf\Mpdf::class)) {
            $this->markTestSkipped('mPDF is not installed.');
        }
        $pdf = new \Bangsamu\LibraryClay\Pdf\OpenParser\Mpdf(['tempDir' => \sys_get_temp_dir()]);
        $count = $pdf->SetSourceFile(Fixtures::path($entry['file']));
        $this->assertSame($entry['pages'], $count);

        for ($page = 1; $page <= $count; $page++) {
            $template = $pdf->ImportPage($page);
            $size = $pdf->GetTemplateSize($template);
            [$width, $height] = $entry['sizes'][$page - 1];
            $this->assertEqualsWithDelta($width * 25.4 / 72, $size['width'], 0.01, "width of page $page");
            $this->assertEqualsWithDelta($height * 25.4 / 72, $size['height'], 0.01, "height of page $page");
            $pdf->AddPageByArray([
                'orientation' => $size['width'] > $size['height'] ? 'L' : 'P',
                'sheet-size' => [\min($size['width'], $size['height']), \max($size['width'], $size['height'])],
            ]);
            $pdf->UseTemplate($template, 0, 0, $size['width'], $size['height']);
        }

        $this->assertOutputMatchesSource($pdf->Output('', 'S'), $entry, false);
    }

    public function testTheFreeParserRefusesTheSameDocument()
    {
        $this->expectException(CrossReferenceException::class);
        $this->expectExceptionCode(CrossReferenceException::COMPRESSED_XREF);

        $pdf = new \setasign\Fpdi\Fpdi();
        $pdf->setSourceFile(Fixtures::path('rich-qpdf-objstm.pdf'));
    }

    public function testEncryptedDocumentsAreRefused()
    {
        $this->expectException(CrossReferenceException::class);
        $this->expectExceptionCode(CrossReferenceException::ENCRYPTED);

        $pdf = new \Bangsamu\LibraryClay\Pdf\OpenParser\Fpdi();
        $pdf->setSourceFile(Fixtures::path('encrypted-aes256.pdf'));
    }

    public function testRepairCanBeDisabled()
    {
        CrossReference::$repair = false;
        try {
            $parser = new PdfParser(StreamReader::createByFile(Fixtures::path('damaged-truncated.pdf')));
            $this->expectException(CrossReferenceException::class);
            $parser->getCrossReference();
        } finally {
            CrossReference::$repair = true;
        }
    }

    public function testRepairIsReported()
    {
        $parser = new PdfParser(StreamReader::createByFile(Fixtures::path('damaged-truncated.pdf')));
        $this->assertTrue($parser->getCrossReference()->isRepaired());

        $parser = new PdfParser(StreamReader::createByFile(Fixtures::path('rich-qpdf-objstm.pdf')));
        $this->assertFalse($parser->getCrossReference()->isRepaired());
    }

    public function testSourceFromStringAndStreamReader()
    {
        $pdf = new \Bangsamu\LibraryClay\Pdf\OpenParser\Fpdi();
        $data = \file_get_contents(Fixtures::path('rich-gs-1.7.pdf'));
        $this->assertSame(3, $pdf->setSourceFile(StreamReader::createByString($data)));
    }

    /**
     * @param object $pdf
     * @param array $entry
     * @param callable $output
     * @return string
     */
    private function importAll($pdf, array $entry, callable $output)
    {
        $count = $pdf->setSourceFile(Fixtures::path($entry['file']));
        $this->assertSame($entry['pages'], $count);

        for ($page = 1; $page <= $count; $page++) {
            $template = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template);
            [$width, $height] = $entry['sizes'][$page - 1];
            $this->assertEqualsWithDelta($width, $size['width'], 0.01, "width of page $page");
            $this->assertEqualsWithDelta($height, $size['height'], 0.01, "height of page $page");

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);
        }

        return $output($pdf);
    }

    /**
     * The output is a classic PDF which even the free parser reads. If poppler's pdftoppm is installed, every page
     * of the output is rendered and compared with the rendering of the source page.
     */
    private function assertOutputMatchesSource($output, array $entry, $render)
    {
        $check = new \setasign\Fpdi\Fpdi();
        $this->assertSame($entry['pages'], $check->setSourceFile(StreamReader::createByString($output)));

        if (!$render || !self::hasPdftoppm()) {
            return;
        }

        $outFile = \tempnam(\sys_get_temp_dir(), 'fop');
        \file_put_contents($outFile, $output);
        try {
            for ($page = 1; $page <= $entry['pages']; $page++) {
                // reference: the undamaged source of the fixture (poppler cannot render all damaged variants)
                $source = Fixtures::path(\pathinfo($entry['golden'], \PATHINFO_FILENAME) . '.pdf');
                $expected = self::render($source, $page);
                $actual = self::render($outFile, $page);
                $this->assertEqualsWithDelta($expected['w'], $actual['w'], 1, "rendered width of page $page");
                $this->assertEqualsWithDelta($expected['h'], $actual['h'], 1, "rendered height of page $page");
                $this->assertLessThan(2.0, self::meanDifference($expected, $actual), "rendering of page $page");
            }
        } finally {
            @\unlink($outFile);
        }
    }

    private static function hasPdftoppm()
    {
        static $has = null;
        if ($has === null) {
            \exec('command -v pdftoppm', $out, $code);
            $has = $code === 0;
        }

        return $has;
    }

    /**
     * @return array{w: int, h: int, data: string}
     */
    private static function render($file, $page)
    {
        $prefix = \tempnam(\sys_get_temp_dir(), 'fopr');
        \exec(\sprintf(
            'pdftoppm -q -cropbox -gray -r 36 -f %d -l %d -singlefile %s %s 2>/dev/null',
            $page,
            $page,
            \escapeshellarg($file),
            \escapeshellarg($prefix)
        ));
        $image = (string) @\file_get_contents($prefix . '.pgm');
        @\unlink($prefix);
        @\unlink($prefix . '.pgm');

        if (!\preg_match('/^P5\s+(\d+)\s+(\d+)\s+(\d+)\s/', $image, $m)) {
            throw new \RuntimeException("Rendering of page $page of $file failed.");
        }

        return ['w' => (int) $m[1], 'h' => (int) $m[2], 'data' => \substr($image, \strlen($m[0]))];
    }

    private static function meanDifference(array $a, array $b)
    {
        $w = \min($a['w'], $b['w']);
        $h = \min($a['h'], $b['h']);
        $sum = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $sum += \abs(\ord($a['data'][$y * $a['w'] + $x]) - \ord($b['data'][$y * $b['w'] + $x]));
            }
        }

        return $sum / \max(1, $w * $h);
    }
}
