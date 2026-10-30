<?php

namespace Bangsamu\LibraryClay\Pdf\OpenParser\CrossReference;

use Bangsamu\LibraryClay\Pdf\OpenParser\Filter\StreamDecoder;
use setasign\Fpdi\FpdiException;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\CrossReference\ReaderInterface;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\Type\PdfArray;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfNumeric;
use setasign\Fpdi\PdfParser\Type\PdfStream;
use setasign\Fpdi\PdfParser\Type\PdfType;
use setasign\Fpdi\PdfParser\Type\PdfTypeException;

/**
 * Reads a cross-reference stream (/Type /XRef, PDF 1.5 and higher; ISO 32000-1, 7.5.8).
 *
 * The decoded stream is kept as one binary string and the entries are decoded on demand, so even documents with
 * hundreds of thousands of objects need only a few bytes per object.
 */
class XrefStreamReader implements ReaderInterface, EntryReaderInterface
{
    /**
     * Keys of the stream dictionary which do not belong to the trailer.
     *
     * @var string[]
     */
    protected static $streamOnlyKeys = ['Type', 'W', 'Index', 'Length', 'Filter', 'DecodeParms', 'DL'];

    /**
     * @var PdfDictionary
     */
    protected $trailer;

    /**
     * The decoded entries.
     *
     * @var string
     */
    protected $data = '';

    /**
     * Byte widths of the three fields of an entry.
     *
     * @var int[]
     */
    protected $widths = [0, 0, 0];

    /**
     * @var int
     */
    protected $rowLength = 0;

    /**
     * Subsections: [first object number, object count, byte offset in $data].
     *
     * @var int[][]
     */
    protected $sections = [];

    /**
     * @param PdfStream $stream
     * @param PdfParser $parser
     * @throws CrossReferenceException
     */
    public function __construct(PdfStream $stream, PdfParser $parser)
    {
        $dictionary = $stream->value;

        try {
            $widths = $this->readIntegers(PdfDictionary::get($dictionary, 'W'), $parser);
            $size = $this->readIntegers(PdfDictionary::get($dictionary, 'Size'), $parser);
            $index = $this->readIntegers(PdfDictionary::get($dictionary, 'Index'), $parser);
        } catch (PdfTypeException $e) {
            throw new CrossReferenceException(
                'Invalid cross-reference stream dictionary.',
                CrossReferenceException::INVALID_DATA,
                $e
            );
        }

        if (\count($widths) < 3) {
            throw new CrossReferenceException(
                'Invalid /W entry in cross-reference stream.',
                CrossReferenceException::INVALID_DATA
            );
        }

        $widths = \array_slice($widths, 0, 3);
        foreach ($widths as $width) {
            if ($width < 0 || $width > 8) {
                throw new CrossReferenceException(
                    'Invalid /W entry in cross-reference stream.',
                    CrossReferenceException::INVALID_DATA
                );
            }
        }

        $this->widths = $widths;
        $this->rowLength = \array_sum($widths);
        if ($this->rowLength === 0) {
            throw new CrossReferenceException(
                'Invalid /W entry in cross-reference stream.',
                CrossReferenceException::INVALID_DATA
            );
        }

        if ($index === []) {
            $index = [0, isset($size[0]) ? $size[0] : 0];
        }

        try {
            $this->data = StreamDecoder::decode($stream, $parser);
        } catch (FpdiException $e) {
            throw new CrossReferenceException(
                'Unable to decode the cross-reference stream: ' . $e->getMessage(),
                CrossReferenceException::INVALID_DATA,
                $e
            );
        }

        $availableRows = \intdiv(\strlen($this->data), $this->rowLength);
        $usedRows = 0;
        for ($i = 0, $n = \count($index) - 1; $i < $n; $i += 2) {
            $first = $index[$i];
            $count = $index[$i + 1];
            if ($count <= 0 || $first < 0) {
                continue;
            }

            // tolerate truncated data
            $count = \min($count, $availableRows - $usedRows);
            if ($count <= 0) {
                break;
            }

            $this->sections[] = [$first, $count, $usedRows * $this->rowLength];
            $usedRows += $count;
        }

        $entries = $dictionary->value;
        foreach (self::$streamOnlyKeys as $key) {
            unset($entries[$key]);
        }
        $this->trailer = PdfDictionary::create($entries);
    }

    /**
     * @inheritdoc
     */
    public function getTrailer()
    {
        return $this->trailer;
    }

    /**
     * @inheritdoc
     * @return int|false
     */
    public function getOffsetFor($objectNumber)
    {
        $entry = $this->getEntry($objectNumber);
        if ($entry !== null && $entry[0] === self::TYPE_IN_USE) {
            return $entry[1];
        }

        return false;
    }

    /**
     * @inheritdoc
     */
    public function getEntry($objectNumber)
    {
        $objectNumber = (int) $objectNumber;

        foreach ($this->sections as $section) {
            $first = $section[0];
            if ($objectNumber < $first || $objectNumber >= $first + $section[1]) {
                continue;
            }

            $position = $section[2] + ($objectNumber - $first) * $this->rowLength;
            $w1 = $this->widths[0];
            $w2 = $this->widths[1];
            $w3 = $this->widths[2];

            // "If the first element is zero, the type field shall not be present, and shall default to type 1."
            $type = $w1 === 0 ? self::TYPE_IN_USE : self::bytesToInt(\substr($this->data, $position, $w1));
            $second = self::bytesToInt(\substr($this->data, $position + $w1, $w2));
            $third = self::bytesToInt(\substr($this->data, $position + $w1 + $w2, $w3));

            if ($type === self::TYPE_FREE || $type === self::TYPE_IN_USE || $type === self::TYPE_COMPRESSED) {
                return [$type, $second, $third];
            }

            // "Any other value shall be interpreted as a reference to the null object."
            return [self::TYPE_FREE, 0, 0];
        }

        return null;
    }

    /**
     * Get the number of the first and the last object (+1) this stream describes. Mainly useful for diagnostics.
     *
     * @return int[][] A list of [first object number, object count].
     */
    public function getSubSections()
    {
        $result = [];
        foreach ($this->sections as $section) {
            $result[] = [$section[0], $section[1]];
        }

        return $result;
    }

    /**
     * @param string|false $bytes
     * @return int
     */
    protected static function bytesToInt($bytes)
    {
        $value = 0;
        $length = \strlen((string) $bytes);
        for ($i = 0; $i < $length; $i++) {
            $value = ($value << 8) | \ord($bytes[$i]);
        }

        return $value;
    }

    /**
     * @param PdfType $value
     * @param PdfParser $parser
     * @return int[]
     * @throws PdfTypeException
     */
    protected function readIntegers(PdfType $value, PdfParser $parser)
    {
        $value = PdfType::resolve($value, $parser);

        if ($value instanceof PdfNumeric) {
            return [(int) $value->value];
        }

        if (!$value instanceof PdfArray) {
            return [];
        }

        $result = [];
        foreach ($value->value as $item) {
            $item = PdfType::resolve($item, $parser);
            if (!$item instanceof PdfNumeric) {
                throw new PdfTypeException('Numeric value expected.', PdfTypeException::INVALID_DATA_TYPE);
            }
            $result[] = (int) $item->value;
        }

        return $result;
    }
}
