<?php

namespace Bangsamu\LibraryClay\Pdf\OpenParser\Filter;

use setasign\Fpdi\PdfParser\Filter\FilterException;

/**
 * Reverts the predictor functions of the Flate/LZW filters (PDF 32000-1, 7.4.4.4).
 *
 *  - Predictor 2: TIFF predictor 2 (8 and 16 bits per component)
 *  - Predictor 10-15: PNG predictors (the real filter type is taken from every row)
 */
final class Predictor
{
    /**
     * @param string $data
     * @param int $predictor
     * @param int $colors
     * @param int $bitsPerComponent
     * @param int $columns
     * @return string
     * @throws FilterException
     */
    public static function decode($data, $predictor = 1, $colors = 1, $bitsPerComponent = 8, $columns = 1)
    {
        $predictor = (int) $predictor;
        if ($predictor <= 1) {
            return $data;
        }

        $colors = \max(1, (int) $colors);
        $bitsPerComponent = \max(1, (int) $bitsPerComponent);
        $columns = \max(1, (int) $columns);

        if ($predictor === 2) {
            return self::decodeTiff($data, $colors, $bitsPerComponent, $columns);
        }

        if ($predictor >= 10 && $predictor <= 15) {
            return self::decodePng($data, $colors, $bitsPerComponent, $columns);
        }

        throw new FilterException(
            \sprintf('Unsupported predictor "%s".', $predictor),
            FilterException::NOT_IMPLEMENTED
        );
    }

    /**
     * @param string $data
     * @param int $colors
     * @param int $bitsPerComponent
     * @param int $columns
     * @return string
     */
    private static function decodePng($data, $colors, $bitsPerComponent, $columns)
    {
        $bytesPerPixel = \max(1, (int) \ceil($colors * $bitsPerComponent / 8));
        $rowBytes = (int) \ceil($colors * $bitsPerComponent * $columns / 8);
        $rowSize = $rowBytes + 1;
        $length = \strlen($data);
        $rows = (int) \ceil($length / $rowSize);

        $previous = \array_fill(0, $rowBytes, 0);
        $output = [];

        for ($row = 0; $row < $rows; $row++) {
            $start = $row * $rowSize;
            $filterType = \ord($data[$start]);
            $raw = (string) \substr($data, $start + 1, $rowBytes);
            $rawLength = \strlen($raw);
            if ($rawLength === 0) {
                break;
            }
            if ($rawLength < $rowBytes) {
                $raw = \str_pad($raw, $rowBytes, "\0");
            }

            /** @var int[] $current */
            $current = \array_values(\unpack('C*', $raw));

            switch ($filterType) {
                case 1: // Sub
                    for ($i = $bytesPerPixel; $i < $rowBytes; $i++) {
                        $current[$i] = ($current[$i] + $current[$i - $bytesPerPixel]) & 0xFF;
                    }
                    break;

                case 2: // Up
                    for ($i = 0; $i < $rowBytes; $i++) {
                        $current[$i] = ($current[$i] + $previous[$i]) & 0xFF;
                    }
                    break;

                case 3: // Average
                    for ($i = 0; $i < $rowBytes; $i++) {
                        $left = $i >= $bytesPerPixel ? $current[$i - $bytesPerPixel] : 0;
                        $current[$i] = ($current[$i] + (($left + $previous[$i]) >> 1)) & 0xFF;
                    }
                    break;

                case 4: // Paeth
                    for ($i = 0; $i < $rowBytes; $i++) {
                        $a = $i >= $bytesPerPixel ? $current[$i - $bytesPerPixel] : 0;
                        $b = $previous[$i];
                        $c = $i >= $bytesPerPixel ? $previous[$i - $bytesPerPixel] : 0;
                        $p = $a + $b - $c;
                        $pa = \abs($p - $a);
                        $pb = \abs($p - $b);
                        $pc = \abs($p - $c);
                        if ($pa <= $pb && $pa <= $pc) {
                            $predicted = $a;
                        } elseif ($pb <= $pc) {
                            $predicted = $b;
                        } else {
                            $predicted = $c;
                        }
                        $current[$i] = ($current[$i] + $predicted) & 0xFF;
                    }
                    break;

                default: // 0 (None) and unknown filter types
                    break;
            }

            $decoded = \pack('C*', ...$current);
            $output[] = $rawLength < $rowBytes ? \substr($decoded, 0, $rawLength) : $decoded;
            $previous = $current;
        }

        return \implode('', $output);
    }

    /**
     * @param string $data
     * @param int $colors
     * @param int $bitsPerComponent
     * @param int $columns
     * @return string
     * @throws FilterException
     */
    private static function decodeTiff($data, $colors, $bitsPerComponent, $columns)
    {
        if ($bitsPerComponent !== 8 && $bitsPerComponent !== 16) {
            throw new FilterException(
                \sprintf('TIFF predictor with %s bits per component is not implemented.', $bitsPerComponent),
                FilterException::NOT_IMPLEMENTED
            );
        }

        $rowBytes = (int) \ceil($colors * $bitsPerComponent * $columns / 8);
        $length = \strlen($data);
        $output = [];

        for ($start = 0; $start < $length; $start += $rowBytes) {
            $raw = (string) \substr($data, $start, $rowBytes);

            if ($bitsPerComponent === 8) {
                $current = \array_values(\unpack('C*', $raw));
                $count = \count($current);
                for ($i = $colors; $i < $count; $i++) {
                    $current[$i] = ($current[$i] + $current[$i - $colors]) & 0xFF;
                }
                $output[] = \pack('C*', ...$current);
                continue;
            }

            // 16 bits per component (big endian samples)
            $samples = \array_values(\unpack('n*', \substr($raw, 0, \strlen($raw) - (\strlen($raw) % 2))));
            $count = \count($samples);
            for ($i = $colors; $i < $count; $i++) {
                $samples[$i] = ($samples[$i] + $samples[$i - $colors]) & 0xFFFF;
            }
            $output[] = \pack('n*', ...$samples);
        }

        return \implode('', $output);
    }
}
