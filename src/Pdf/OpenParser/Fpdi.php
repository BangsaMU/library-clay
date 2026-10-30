<?php

namespace Bangsamu\LibraryClay\Pdf\OpenParser;

/**
 * FPDI for FPDF with support for PDF 1.5, 1.6 and 1.7 (cross-reference streams and object streams).
 */
class Fpdi extends \setasign\Fpdi\Fpdi
{
    use OpenParserTrait;
}
