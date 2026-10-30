<?php

namespace Bangsamu\LibraryClay\Pdf\OpenParser;

/**
 * mPDF whose SetSourceFile()/ImportPage() read PDF 1.5, 1.6 and 1.7 documents (cross-reference streams and object
 * streams) without converting them first. Needs mpdf/mpdf ^8 in the application.
 */
class Mpdf extends \Mpdf\Mpdf
{
    use OpenParserTrait;
}
