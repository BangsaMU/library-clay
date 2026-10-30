<?php

namespace Bangsamu\LibraryClay\Pdf\OpenParser\Tfpdf;

use Bangsamu\LibraryClay\Pdf\OpenParser\OpenParserTrait;

/**
 * FPDI for tFPDF with support for PDF 1.5, 1.6 and 1.7 (cross-reference streams and object streams).
 */
class Fpdi extends \setasign\Fpdi\Tfpdf\Fpdi
{
    use OpenParserTrait;
}
