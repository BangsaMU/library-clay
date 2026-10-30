<?php

namespace Bangsamu\LibraryClay\Pdf\OpenParser\CrossReference;

use setasign\Fpdi\PdfParser\CrossReference\ReaderInterface;

/**
 * Reader for the cross-reference sections of hybrid-reference files (ISO 32000-1, 7.5.8.4).
 *
 * A hybrid-reference file has a classic cross-reference table, whose trailer points with /XRefStm to a
 * cross-reference stream. The stream describes the objects which are hidden from PDF 1.4 readers (the compressed
 * objects). Lookups ask the table first, then the stream; the /Prev chain is followed by the caller afterwards.
 */
class HybridReader implements ReaderInterface, EntryReaderInterface
{
    /**
     * @var ReaderInterface
     */
    protected $table;

    /**
     * @var ReaderInterface
     */
    protected $stream;

    /**
     * @param ReaderInterface $table The reader of the classic cross-reference table (owns the trailer).
     * @param ReaderInterface $stream The reader of the cross-reference stream referenced by /XRefStm.
     */
    public function __construct(ReaderInterface $table, ReaderInterface $stream)
    {
        $this->table = $table;
        $this->stream = $stream;
    }

    /**
     * @inheritdoc
     */
    public function getTrailer()
    {
        return $this->table->getTrailer();
    }

    /**
     * @inheritdoc
     */
    public function getOffsetFor($objectNumber)
    {
        $offset = $this->table->getOffsetFor($objectNumber);
        if ($offset !== false) {
            return $offset;
        }

        return $this->stream->getOffsetFor($objectNumber);
    }

    /**
     * @inheritdoc
     */
    public function getEntry($objectNumber)
    {
        $offset = $this->table->getOffsetFor($objectNumber);
        if ($offset !== false) {
            return [self::TYPE_IN_USE, $offset, 0];
        }

        if ($this->stream instanceof EntryReaderInterface) {
            return $this->stream->getEntry($objectNumber);
        }

        $offset = $this->stream->getOffsetFor($objectNumber);

        return $offset === false ? null : [self::TYPE_IN_USE, $offset, 0];
    }
}
