<?php

namespace Bangsamu\LibraryClay\Pdf\OpenParser;

use Bangsamu\LibraryClay\Pdf\OpenParser\CrossReference\CrossReference;
use setasign\Fpdi\PdfParser\PdfParser as BasePdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfParser\Type\PdfIndirectObject;
use setasign\Fpdi\PdfParser\Type\PdfNull;

/**
 * A PDF parser for FPDI which is able to read PDF 1.5, 1.6 and 1.7 documents that use cross-reference streams
 * and object streams.
 */
class PdfParser extends BasePdfParser
{
    /**
     * Individual parameters passed through FPDI's setSourceFile() (reserved for future options).
     *
     * @var array
     */
    protected $parserParams = [];

    /**
     * True while the cross-reference is being read.
     *
     * @var bool
     */
    protected $loadingCrossReference = false;

    /**
     * @param StreamReader $streamReader
     * @param array $parserParams
     */
    public function __construct(
        StreamReader $streamReader,
        #[\SensitiveParameter]
        array $parserParams = []
    ) {
        parent::__construct($streamReader);
        $this->parserParams = $parserParams;
    }

    /**
     * @inheritdoc
     * @return CrossReference
     */
    public function getCrossReference()
    {
        if ($this->xref === null) {
            $this->loadingCrossReference = true;
            try {
                $this->xref = new CrossReference($this, $this->resolveFileHeader());
            } finally {
                $this->loadingCrossReference = false;
            }
        }

        return $this->xref;
    }

    /**
     * @inheritdoc
     */
    public function getIndirectObject($objectNumber, $cache = false)
    {
        if ($this->loadingCrossReference) {
            // While the cross-reference is read nothing can be resolved yet (e.g. an indirect /Length of
            // a cross-reference stream). A null object makes the stream reader fall back to scanning for "endstream".
            return PdfIndirectObject::create((int) $objectNumber, 0, new PdfNull());
        }

        // Same as the parser of current FPDI versions; older versions read objects themselves and would bypass
        // the cross-reference class, which knows about compressed objects.
        $objectNumber = (int) $objectNumber;
        if (isset($this->objects[$objectNumber])) {
            return $this->objects[$objectNumber];
        }

        $object = $this->getCrossReference()->getIndirectObject($objectNumber);
        if ($cache) {
            $this->objects[$objectNumber] = $object;
        }

        return $object;
    }
}
