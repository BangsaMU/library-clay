<?php

namespace Bangsamu\LibraryClay\Pdf\OpenParser\Tcpdf;

use Bangsamu\LibraryClay\Pdf\OpenParser\OpenParserTrait;

/**
 * FPDI for TCPDF with support for PDF 1.5, 1.6 and 1.7 (cross-reference streams and object streams).
 */
class Fpdi extends \setasign\Fpdi\Tcpdf\Fpdi
{
    use OpenParserTrait;
}
