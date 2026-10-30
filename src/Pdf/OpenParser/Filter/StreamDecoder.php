<?php

namespace Bangsamu\LibraryClay\Pdf\OpenParser\Filter;

use setasign\Fpdi\PdfParser\Filter\Ascii85;
use setasign\Fpdi\PdfParser\Filter\AsciiHex;
use setasign\Fpdi\PdfParser\Filter\FilterException;
use setasign\Fpdi\PdfParser\Filter\Lzw;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\Type\PdfArray;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfName;
use setasign\Fpdi\PdfParser\Type\PdfNull;
use setasign\Fpdi\PdfParser\Type\PdfNumeric;
use setasign\Fpdi\PdfParser\Type\PdfStream;
use setasign\Fpdi\PdfParser\Type\PdfType;

/**
 * Decodes the data of a stream object.
 *
 * In contrast to PdfStream::getUnfilteredStream() of the free FPDI parser this decoder is also able to
 * revert the Flate/LZW predictors, which are needed to read cross-reference streams and object streams.
 */
final class StreamDecoder
{
    /**
     * @param PdfStream $stream
     * @param PdfParser|null $parser Used to resolve indirect references in the stream dictionary.
     * @return string
     * @throws FilterException
     */
    public static function decode(PdfStream $stream, ?PdfParser $parser = null)
    {
        $data = (string) $stream->getStream();

        $filters = self::toList(PdfDictionary::get($stream->value, 'Filter'), $parser);
        if ($filters === []) {
            return $data;
        }
        $parameters = self::toList(PdfDictionary::get($stream->value, 'DecodeParms'), $parser, true);

        foreach ($filters as $index => $filter) {
            if (!$filter instanceof PdfName) {
                continue;
            }

            $parameter = isset($parameters[$index]) && $parameters[$index] instanceof PdfDictionary
                ? $parameters[$index]
                : null;

            switch ($filter->value) {
                case 'FlateDecode':
                case 'Fl':
                    $data = self::applyPredictor(Inflate::decode($data), $parameter, $parser);
                    break;

                case 'LZWDecode':
                case 'LZW':
                    $lzw = new Lzw();
                    $data = self::applyPredictor($lzw->decode($data), $parameter, $parser);
                    break;

                case 'ASCII85Decode':
                case 'A85':
                    $ascii85 = new Ascii85();
                    $data = $ascii85->decode($data);
                    break;

                case 'ASCIIHexDecode':
                case 'AHx':
                    $asciiHex = new AsciiHex();
                    $data = $asciiHex->decode($data);
                    break;

                case 'Crypt':
                    // "Identity" crypt filter (the data is not encrypted) - nothing to do.
                    break;

                default:
                    throw new FilterException(
                        \sprintf('Unsupported filter "%s".', $filter->value),
                        FilterException::UNSUPPORTED_FILTER
                    );
            }
        }

        return $data;
    }

    /**
     * @param string $data
     * @param PdfDictionary|null $parameters
     * @param PdfParser|null $parser
     * @return string
     * @throws FilterException
     */
    private static function applyPredictor($data, ?PdfDictionary $parameters = null, ?PdfParser $parser = null)
    {
        if ($parameters === null) {
            return $data;
        }

        $predictor = self::getInt($parameters, 'Predictor', 1, $parser);
        if ($predictor <= 1) {
            return $data;
        }

        return Predictor::decode(
            $data,
            $predictor,
            self::getInt($parameters, 'Colors', 1, $parser),
            self::getInt($parameters, 'BitsPerComponent', 8, $parser),
            self::getInt($parameters, 'Columns', 1, $parser)
        );
    }

    /**
     * @param PdfDictionary $dictionary
     * @param string $key
     * @param int $default
     * @param PdfParser|null $parser
     * @return int
     */
    private static function getInt(PdfDictionary $dictionary, $key, $default, ?PdfParser $parser = null)
    {
        $value = PdfDictionary::get($dictionary, $key);
        if ($parser !== null) {
            $value = PdfType::resolve($value, $parser);
        }

        return $value instanceof PdfNumeric ? (int) $value->value : $default;
    }

    /**
     * Normalizes a /Filter or /DecodeParms entry to a list of values.
     *
     * @param PdfType $value
     * @param PdfParser|null $parser
     * @param bool $keepNulls Keep null entries to preserve the index of the entries (needed for /DecodeParms).
     * @return PdfType[]
     */
    private static function toList(PdfType $value, ?PdfParser $parser = null, $keepNulls = false)
    {
        if ($parser !== null) {
            $value = PdfType::resolve($value, $parser);
        }

        if ($value instanceof PdfNull) {
            return [];
        }

        $items = $value instanceof PdfArray ? $value->value : [$value];
        $result = [];
        foreach ($items as $item) {
            if ($parser !== null) {
                $item = PdfType::resolve($item, $parser);
            }
            if (!$keepNulls && $item instanceof PdfNull) {
                continue;
            }
            $result[] = $item;
        }

        return $result;
    }
}
