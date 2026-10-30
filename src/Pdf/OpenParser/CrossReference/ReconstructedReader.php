<?php

namespace Bangsamu\LibraryClay\Pdf\OpenParser\CrossReference;

use setasign\Fpdi\PdfParser\CrossReference\ReaderInterface;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;

/**
 * Cross-reference data which was rebuilt by scanning the whole file (used for damaged documents).
 */
class ReconstructedReader implements ReaderInterface, EntryReaderInterface
{
    /**
     * @var int[][] object number => entry
     */
    protected $entries;

    /**
     * @var PdfDictionary
     */
    protected $trailer;

    /**
     * @param int[][] $entries
     * @param PdfDictionary $trailer
     */
    public function __construct(array $entries, PdfDictionary $trailer)
    {
        $this->entries = $entries;
        $this->trailer = $trailer;
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
     */
    public function getOffsetFor($objectNumber)
    {
        $entry = $this->getEntry($objectNumber);

        return $entry !== null && $entry[0] === self::TYPE_IN_USE ? $entry[1] : false;
    }

    /**
     * @inheritdoc
     */
    public function getEntry($objectNumber)
    {
        return isset($this->entries[(int) $objectNumber]) ? $this->entries[(int) $objectNumber] : null;
    }

    /**
     * @return int[] All object numbers known by this reader.
     */
    public function getObjectNumbers()
    {
        return \array_keys($this->entries);
    }
}
