<?php

namespace Bangsamu\LibraryClay\Tests\Pdf\OpenParser;

use Bangsamu\LibraryClay\Pdf\OpenParser\Filter\Inflate;
use Bangsamu\LibraryClay\Pdf\OpenParser\Filter\Predictor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FilterTest extends TestCase
{
    public static function pngFilterTypes()
    {
        return ['none' => [0], 'sub' => [1], 'up' => [2], 'average' => [3], 'paeth' => [4]];
    }

    #[DataProvider('pngFilterTypes')]
    public function testPngPredictors($type)
    {
        $columns = 7;
        $rows = [];
        for ($r = 0; $r < 20; $r++) {
            $row = '';
            for ($c = 0; $c < $columns; $c++) {
                $row .= \chr(($r * 37 + $c * 91 + $r * $c) & 0xFF);
            }
            $rows[] = $row;
        }

        $encoded = self::pngEncode($rows, $type);
        $this->assertSame(\implode('', $rows), Predictor::decode($encoded, 12, 1, 8, $columns));
    }

    public function testTiffPredictor()
    {
        $row = "\x10\x20\x05\xFF\x00\x80";
        $encoded = $row[0];
        for ($i = 1; $i < \strlen($row); $i++) {
            $encoded .= \chr((\ord($row[$i]) - \ord($row[$i - 1])) & 0xFF);
        }

        $this->assertSame($row, Predictor::decode($encoded, 2, 1, 8, \strlen($row)));
    }

    public function testInflateToleratesDamagedData()
    {
        \mt_srand(42);
        $data = 'PDF object stream data';
        for ($i = 0; $i < 3000; $i++) {
            $data .= \chr(\mt_rand(32, 126));
        }
        $compressed = \gzcompress($data);

        $this->assertSame($data, Inflate::decode($compressed));
        $this->assertSame($data, Inflate::decode($compressed . "\r\ngarbage"));
        $this->assertSame($data, Inflate::decode(\substr($compressed, 0, -4) . 'XXXX'), 'wrong checksum');
        $this->assertStringStartsWith(
            'PDF object stream data',
            Inflate::decode(\substr($compressed, 0, (int) (\strlen($compressed) / 2))),
            'truncated data'
        );
    }

    private static function pngEncode(array $rows, $type)
    {
        $out = '';
        $previous = \str_repeat("\0", \strlen($rows[0]));
        foreach ($rows as $row) {
            $out .= \chr($type);
            for ($i = 0, $n = \strlen($row); $i < $n; $i++) {
                $a = $i > 0 ? \ord($row[$i - 1]) : 0;
                $b = \ord($previous[$i]);
                $c = $i > 0 ? \ord($previous[$i - 1]) : 0;
                switch ($type) {
                    case 1:
                        $p = $a;
                        break;
                    case 2:
                        $p = $b;
                        break;
                    case 3:
                        $p = ($a + $b) >> 1;
                        break;
                    case 4:
                        $pp = $a + $b - $c;
                        $pa = \abs($pp - $a);
                        $pb = \abs($pp - $b);
                        $pc = \abs($pp - $c);
                        $p = ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                        break;
                    default:
                        $p = 0;
                }
                $out .= \chr((\ord($row[$i]) - $p) & 0xFF);
            }
            $previous = $row;
        }

        return $out;
    }
}
