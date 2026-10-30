<?php

namespace Bangsamu\LibraryClay\Pdf\OpenParser\Filter;

use setasign\Fpdi\PdfParser\Filter\FlateException;

/**
 * Inflates zlib compressed data and tolerates the usual damages found in real world PDF files
 * (wrong Adler-32 checksum, trailing garbage, truncated data).
 */
final class Inflate
{
    /**
     * @param string $data
     * @return string
     * @throws FlateException
     */
    public static function decode($data)
    {
        if (!\extension_loaded('zlib')) {
            throw new FlateException(
                'To handle FlateDecode filter, enable zlib support in PHP.',
                FlateException::NO_ZLIB
            );
        }

        $data = (string) $data;
        if ($data === '') {
            return '';
        }

        $result = @\gzuncompress($data);
        if ($result !== false) {
            return $result;
        }

        // Missing/invalid zlib header or checksum: try the raw deflate payload.
        $result = @\gzinflate(\substr($data, 2));
        if ($result !== false && $result !== '') {
            return $result;
        }

        // Last resort: keep whatever can be decoded before the damaged part starts.
        if (\function_exists('inflate_init')) {
            $context = @\inflate_init(\ZLIB_ENCODING_DEFLATE);
            if ($context !== false) {
                $result = '';
                $length = \strlen($data);
                for ($i = 0; $i < $length; $i += 512) {
                    $chunk = @\inflate_add($context, \substr($data, $i, 512), \ZLIB_SYNC_FLUSH);
                    if ($chunk === false) {
                        break;
                    }
                    $result .= $chunk;
                }

                if ($result !== '') {
                    return $result;
                }
            }
        }

        throw new FlateException('Error while decompressing stream.', FlateException::DECOMPRESS_ERROR);
    }
}
