<?php

namespace Bangsamu\LibraryClay\Tests\Pdf\OpenParser\Support;

use Bangsamu\LibraryClay\Pdf\OpenParser\PdfParser;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfParser\Type\PdfArray;
use setasign\Fpdi\PdfParser\Type\PdfBoolean;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfHexString;
use setasign\Fpdi\PdfParser\Type\PdfIndirectObject;
use setasign\Fpdi\PdfParser\Type\PdfIndirectObjectReference;
use setasign\Fpdi\PdfParser\Type\PdfName;
use setasign\Fpdi\PdfParser\Type\PdfNull;
use setasign\Fpdi\PdfParser\Type\PdfNumeric;
use setasign\Fpdi\PdfParser\Type\PdfStream;
use setasign\Fpdi\PdfParser\Type\PdfString;
use setasign\Fpdi\PdfParser\Type\PdfType;

/**
 * Dumps every object of a PDF file as canonical, JSON compatible data. The format is the same as the one of the
 * golden files, which tests/tools/make_fixtures.py writes with qpdf (through pikepdf).
 */
final class ObjectDumper
{
    /**
     * @param string $file
     * @return array{objects: array<string, mixed>, trailer: array<string, int[]>}
     */
    public static function dump($file)
    {
        $parser = new PdfParser(StreamReader::createByFile($file));
        $trailer = $parser->getCrossReference()->getTrailer();
        $size = (int) PdfType::resolve($trailer->value['Size'], $parser)->value;

        $objects = [];
        for ($number = 1; $number < $size; $number++) {
            try {
                $object = $parser->getIndirectObject($number);
            } catch (CrossReferenceException $e) {
                if ($e->getCode() === CrossReferenceException::OBJECT_NOT_FOUND) {
                    continue;
                }
                throw $e;
            }

            $value = $object->value;
            if ($value instanceof PdfStream) {
                $type = PdfDictionary::get($value->value, 'Type');
                if ($type instanceof PdfName && ($type->value === 'XRef' || $type->value === 'ObjStm')) {
                    continue;
                }
            }

            $objects[(string) $number] = self::canonical($value);
        }

        $refs = [];
        foreach (['Root', 'Info'] as $key) {
            if (isset($trailer->value[$key]) && $trailer->value[$key] instanceof PdfIndirectObjectReference) {
                $refs[$key] = [$trailer->value[$key]->value, $trailer->value[$key]->generationNumber];
            }
        }

        return ['objects' => $objects, 'trailer' => $refs];
    }

    /**
     * @param PdfType $value
     * @return mixed
     */
    public static function canonical(PdfType $value)
    {
        if ($value instanceof PdfIndirectObject) {
            return self::canonical($value->value);
        }
        if ($value instanceof PdfIndirectObjectReference) {
            return ['r' => [$value->value, $value->generationNumber]];
        }
        if ($value instanceof PdfNull) {
            return null;
        }
        if ($value instanceof PdfBoolean) {
            return (bool) $value->value;
        }
        if ($value instanceof PdfNumeric) {
            return $value->value;
        }
        if ($value instanceof PdfName) {
            return '/' . PdfName::unescape($value->value);
        }
        if ($value instanceof PdfString) {
            return ['s' => \bin2hex(PdfString::unescape($value->value))];
        }
        if ($value instanceof PdfHexString) {
            $hex = \preg_replace('/[^0-9A-Fa-f]/', '', $value->value);
            if (\strlen($hex) % 2 === 1) {
                $hex .= '0';
            }
            return ['s' => \strtolower($hex)];
        }
        if ($value instanceof PdfArray) {
            return \array_map([self::class, 'canonical'], $value->value);
        }
        if ($value instanceof PdfDictionary) {
            $result = [];
            foreach ($value->value as $key => $item) {
                $result[$key] = self::canonical($item);
            }
            return $result;
        }
        if ($value instanceof PdfStream) {
            return ['stream' => ['dict' => self::canonical($value->value), 'sha1' => \sha1($value->getStream())]];
        }

        throw new \LogicException('Unexpected type ' . \get_class($value));
    }
}
