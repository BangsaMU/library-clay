<?php

namespace Bangsamu\LibraryClay\Pdf\OpenParser;

use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Makes a class which uses FPDI's FpdiTrait (or extends one of the FPDI classes) use the open parser.
 *
 * <code>
 * class MyPdf extends \setasign\Fpdi\Fpdi
 * {
 *     use \Bangsamu\LibraryClay\Pdf\OpenParser\OpenParserTrait;
 * }
 * </code>
 */
trait OpenParserTrait
{
    /**
     * Get a new pdf parser instance.
     *
     * @param StreamReader $streamReader
     * @param array $parserParams Individual parameters passed to the parser instance.
     * @return PdfParser
     */
    protected function getPdfParserInstance(
        StreamReader $streamReader,
        #[\SensitiveParameter]
        array $parserParams = []
    ) {
        return new PdfParser($streamReader, $parserParams);
    }
}
