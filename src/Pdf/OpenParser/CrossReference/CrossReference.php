<?php

namespace Bangsamu\LibraryClay\Pdf\OpenParser\CrossReference;

use Bangsamu\LibraryClay\Pdf\OpenParser\Filter\StreamDecoder;
use setasign\Fpdi\FpdiException;
use setasign\Fpdi\PdfParser\CrossReference\CrossReference as BaseCrossReference;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\CrossReference\ReaderInterface;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfIndirectObject;
use setasign\Fpdi\PdfParser\Type\PdfIndirectObjectReference;
use setasign\Fpdi\PdfParser\Type\PdfName;
use setasign\Fpdi\PdfParser\Type\PdfNumeric;
use setasign\Fpdi\PdfParser\Type\PdfStream;
use setasign\Fpdi\PdfParser\Type\PdfToken;
use setasign\Fpdi\PdfParser\Type\PdfType;
use setasign\Fpdi\PdfParser\Type\PdfTypeException;

/**
 * Cross-reference handling for PDF 1.5+ documents.
 *
 * On top of the classic cross-reference tables of FPDI's free parser this class understands
 *
 *  - cross-reference streams (/Type /XRef),
 *  - hybrid-reference files (/XRefStm in the trailer of a classic table),
 *  - compressed objects, which are stored in object streams (/Type /ObjStm),
 *  - damaged documents: if the cross-reference cannot be read or points to wrong locations, it is rebuilt by
 *    scanning the file (can be disabled with the static $repair property).
 */
class CrossReference extends BaseCrossReference
{
    /**
     * Rebuild the cross-reference of damaged documents by scanning the file.
     *
     * @var bool
     */
    public static $repair = true;

    /**
     * Parsed object streams, the most recently loaded last.
     *
     * @var array[]
     */
    protected $objectStreams = [];

    /**
     * Maximum number of decoded object streams which are kept in memory.
     *
     * @var int
     */
    protected $objectStreamCacheSize = 16;

    /**
     * Rebuilt cross-reference data, used as fallback for broken entries.
     *
     * @var ReconstructedReader|null|false false if the reconstruction failed
     */
    protected $reconstructed;

    /**
     * True if the regular cross-reference could not be read at all and only the rebuilt data is used.
     *
     * @var bool
     */
    protected $repaired = false;

    /**
     * @param PdfParser $parser
     * @param int $fileHeaderOffset
     * @throws CrossReferenceException
     * @throws PdfTypeException
     */
    public function __construct(PdfParser $parser, $fileHeaderOffset = 0)
    {
        try {
            parent::__construct($parser, $fileHeaderOffset);
        } catch (CrossReferenceException $e) {
            if (!self::$repair || $e->getCode() === CrossReferenceException::ENCRYPTED) {
                throw $e;
            }

            $this->parser = $parser;
            $this->fileHeaderOffset = $fileHeaderOffset;
            $reader = $this->getReconstructedReader();
            if ($reader === null) {
                throw $e;
            }

            $this->checkForEncryption($reader->getTrailer());
            $this->readers = [$reader];
            $this->repaired = true;
        }
    }

    /**
     * Whether the cross-reference had to be rebuilt because it was not readable.
     *
     * @return bool
     */
    public function isRepaired()
    {
        return $this->repaired;
    }

    /**
     * Get the cross-reference entry of an object, newest section first.
     *
     * @param int $objectNumber
     * @return int[]|null null if no section knows the object, otherwise [type, field 2, field 3]
     * @see EntryReaderInterface::getEntry()
     */
    public function getEntry($objectNumber)
    {
        foreach ($this->getReaders() as $reader) {
            if ($reader instanceof EntryReaderInterface) {
                $entry = $reader->getEntry($objectNumber);
                if ($entry !== null) {
                    return $entry;
                }
                continue;
            }

            $offset = $reader->getOffsetFor($objectNumber);
            if ($offset !== false) {
                return [EntryReaderInterface::TYPE_IN_USE, $offset, 0];
            }
        }

        return null;
    }

    /**
     * @inheritdoc
     */
    public function getIndirectObject($objectNumber)
    {
        $objectNumber = (int) $objectNumber;
        $entry = $this->getEntry($objectNumber);

        if ($entry !== null && $entry[0] === EntryReaderInterface::TYPE_FREE) {
            throw new CrossReferenceException(
                \sprintf('Object (id:%s) not found.', $objectNumber),
                CrossReferenceException::OBJECT_NOT_FOUND
            );
        }

        try {
            if ($entry === null) {
                throw new CrossReferenceException(
                    \sprintf('Object (id:%s) not found.', $objectNumber),
                    CrossReferenceException::OBJECT_NOT_FOUND
                );
            }

            return $this->readEntry($objectNumber, $entry);
        } catch (CrossReferenceException $e) {
            // The entry points to a wrong location (or is missing): ask the rebuilt cross-reference.
            if ($this->repaired || $e->getCode() !== CrossReferenceException::OBJECT_NOT_FOUND) {
                throw $e;
            }

            $reader = $this->getReconstructedReader();
            $fallback = $reader !== null ? $reader->getEntry($objectNumber) : null;
            if ($fallback === null || $fallback === $entry) {
                throw $e;
            }

            return $this->readEntry($objectNumber, $fallback);
        }
    }

    /**
     * @param int $objectNumber
     * @param int[] $entry
     * @return PdfIndirectObject
     * @throws CrossReferenceException
     */
    protected function readEntry($objectNumber, array $entry)
    {
        if ($entry[0] === EntryReaderInterface::TYPE_COMPRESSED) {
            return $this->readCompressedObject($objectNumber, $entry[1], $entry[2]);
        }

        return $this->readObjectAt($objectNumber, $entry[1]);
    }

    /**
     * Read an uncompressed object at a byte offset (relative to the file header).
     *
     * @param int $objectNumber
     * @param int $offset
     * @return PdfIndirectObject
     * @throws CrossReferenceException
     */
    protected function readObjectAt($objectNumber, $offset)
    {
        $parser = $this->parser;
        $parser->getTokenizer()->clearStack();
        $parser->getStreamReader()->reset($offset + $this->fileHeaderOffset);

        try {
            /** @var PdfIndirectObject $object */
            $object = $parser->readValue(null, PdfIndirectObject::class);
        } catch (PdfTypeException $e) {
            throw new CrossReferenceException(
                \sprintf('Object (id:%s) not found at location (%s).', $objectNumber, $offset),
                CrossReferenceException::OBJECT_NOT_FOUND,
                $e
            );
        }

        if (!$object instanceof PdfIndirectObject || $object->objectNumber !== $objectNumber) {
            throw new CrossReferenceException(
                \sprintf('Object (id:%s) not found at location (%s).', $objectNumber, $offset),
                CrossReferenceException::OBJECT_NOT_FOUND
            );
        }

        return $object;
    }

    /**
     * @inheritdoc
     */
    protected function readXref($offset)
    {
        $reader = parent::readXref($offset);

        if ($reader instanceof XrefStreamReader || !$reader instanceof ReaderInterface) {
            return $reader;
        }

        // hybrid-reference file: the trailer of the classic table points to an additional cross-reference stream
        $trailer = $reader->getTrailer();
        if (!isset($trailer->value['XRefStm']) || !$trailer->value['XRefStm'] instanceof PdfNumeric) {
            return $reader;
        }

        try {
            $streamReader = parent::readXref(((int) $trailer->value['XRefStm']->value) + $this->fileHeaderOffset);
        } catch (FpdiException $e) {
            // A damaged /XRefStm only hides the compressed objects, the classic table is still usable.
            return $reader;
        }

        if ($streamReader instanceof XrefStreamReader) {
            return new HybridReader($reader, $streamReader);
        }

        return $reader;
    }

    /**
     * @inheritdoc
     */
    protected function initReaderInstance($initValue)
    {
        if ($initValue instanceof PdfIndirectObject && $initValue->value instanceof PdfStream) {
            $stream = $initValue->value;
            $type = PdfDictionary::get($stream->value, 'Type');
            if ($type instanceof PdfName && $type->value === 'XRef') {
                return new XrefStreamReader($stream, $this->parser);
            }
        }

        return parent::initReaderInstance($initValue);
    }

    /**
     * Read an object which is stored in an object stream.
     *
     * @param int $objectNumber
     * @param int $streamNumber The number of the object stream.
     * @param int $index The index of the object in the object stream.
     * @return PdfIndirectObject
     * @throws CrossReferenceException
     */
    protected function readCompressedObject($objectNumber, $streamNumber, $index)
    {
        $objectStream = $this->loadObjectStream($streamNumber);

        $position = null;
        if (isset($objectStream['numbers'][$index]) && $objectStream['numbers'][$index] === $objectNumber) {
            $position = $objectStream['offsets'][$index];
        } elseif (isset($objectStream['map'][$objectNumber])) {
            // the index in the cross-reference is wrong, the object stream itself is the better source
            $position = $objectStream['map'][$objectNumber];
        }

        if ($position === null) {
            throw new CrossReferenceException(
                \sprintf('Object (id:%s) not found in object stream (id:%s).', $objectNumber, $streamNumber),
                CrossReferenceException::OBJECT_NOT_FOUND
            );
        }

        /** @var PdfParser $parser */
        $parser = $objectStream['parser'];
        $parser->getTokenizer()->clearStack();
        $parser->getStreamReader()->reset($objectStream['first'] + $position, 1024);

        try {
            $value = $parser->readValue();
        } catch (PdfTypeException $e) {
            throw new CrossReferenceException(
                \sprintf('Object (id:%s) cannot be read from object stream (id:%s).', $objectNumber, $streamNumber),
                CrossReferenceException::OBJECT_NOT_FOUND,
                $e
            );
        }

        // some writers repeat the "n g obj" header inside of the object stream
        if ($value instanceof PdfIndirectObject) {
            $value = $value->value;
        }

        if (!$value instanceof PdfType || $value instanceof PdfToken) {
            throw new CrossReferenceException(
                \sprintf('Object (id:%s) cannot be read from object stream (id:%s).', $objectNumber, $streamNumber),
                CrossReferenceException::OBJECT_NOT_FOUND
            );
        }

        // compressed objects always have the generation number 0
        return PdfIndirectObject::create($objectNumber, 0, $value);
    }

    /**
     * Decode an object stream and read its table of contents.
     *
     * @param int $streamNumber
     * @return array
     * @throws CrossReferenceException
     */
    protected function loadObjectStream($streamNumber)
    {
        if (isset($this->objectStreams[$streamNumber])) {
            return $this->objectStreams[$streamNumber];
        }

        try {
            $object = $this->getIndirectObject($streamNumber);
            $objectStream = $this->parseObjectStream(PdfStream::ensure($object->value));
        } catch (FpdiException $e) {
            throw new CrossReferenceException(
                \sprintf('Unable to read object stream (id:%s): %s', $streamNumber, $e->getMessage()),
                CrossReferenceException::OBJECT_NOT_FOUND,
                $e
            );
        }

        if (\count($this->objectStreams) >= $this->objectStreamCacheSize) {
            \array_shift($this->objectStreams);
        }
        $this->objectStreams[$streamNumber] = $objectStream;

        return $objectStream;
    }

    /**
     * @param PdfStream $stream
     * @return array
     * @throws FpdiException
     */
    protected function parseObjectStream(PdfStream $stream)
    {
        $data = StreamDecoder::decode($stream, $this->parser);
        $count = $this->getInteger($stream->value, 'N');
        $first = $this->getInteger($stream->value, 'First');

        if ($first < 0 || $first > \strlen($data)) {
            throw new CrossReferenceException(
                'Invalid /First entry in object stream.',
                CrossReferenceException::OBJECT_NOT_FOUND
            );
        }

        // the table of contents: N pairs of "object number" and "byte offset (relative to /First)"
        \preg_match_all('/\d+/', (string) \substr($data, 0, $first), $matches);
        $integers = $matches[0];
        $count = \min($count, \intdiv(\count($integers), 2));

        $numbers = [];
        $offsets = [];
        $map = [];
        for ($i = 0; $i < $count; $i++) {
            $number = (int) $integers[$i * 2];
            $offset = (int) $integers[$i * 2 + 1];
            $numbers[$i] = $number;
            $offsets[$i] = $offset;
            if (!isset($map[$number])) {
                $map[$number] = $offset;
            }
        }

        return [
            'parser' => new PdfParser(StreamReader::createByString($data)),
            'first' => $first,
            'numbers' => $numbers,
            'offsets' => $offsets,
            'map' => $map,
        ];
    }

    /**
     * Get (and build once) the cross-reference data rebuilt by scanning the file.
     *
     * @return ReconstructedReader|null
     */
    protected function getReconstructedReader()
    {
        if ($this->reconstructed === null) {
            $this->reconstructed = false; // guards against recursion while the file is scanned
            try {
                $this->reconstructed = $this->reconstruct();
            } catch (FpdiException $e) {
                $this->reconstructed = false;
            }
        }

        return $this->reconstructed ?: null;
    }

    /**
     * Rebuild the cross-reference by scanning the file for object definitions, object streams and trailers.
     *
     * @return ReconstructedReader|false
     * @throws FpdiException
     */
    protected function reconstruct()
    {
        $streamReader = $this->parser->getStreamReader();
        $streamReader->reset(0, $streamReader->getTotalLength());
        $data = $streamReader->getBuffer(false);
        $headerOffset = $this->fileHeaderOffset;

        // 1. every "n g obj" (the last definition of an object number wins, like an incremental update)
        \preg_match_all(
            '/(?<![0-9])(\d{1,10})[\x00\t\n\f\r ]+(\d{1,5})[\x00\t\n\f\r ]*obj(?![A-Za-z0-9])/',
            $data,
            $matches,
            \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE
        );

        $entries = [];
        $positions = [];
        foreach ($matches as $match) {
            $number = (int) $match[1][0];
            $position = $match[0][1];
            $entries[$number] = [EntryReaderInterface::TYPE_IN_USE, $position - $headerOffset, (int) $match[2][0]];
            $positions[$number] = $position;
        }

        if ($entries === []) {
            return false;
        }

        // the scan result is used while the object streams and trailers are read
        $previousReaders = $this->readers;
        $this->readers = [new ReconstructedReader($entries, PdfDictionary::create())];

        $trailers = [];
        $catalog = null;
        try {
            // 2. trailers of classic cross-reference tables
            $offset = 0;
            while (($offset = \strpos($data, 'trailer', $offset)) !== false) {
                $offset += 7;
                $dictionary = $this->readDictionaryAt($offset);
                if ($dictionary !== null && isset($dictionary->value['Root'])) {
                    $trailers[$offset] = $dictionary;
                }
            }

            // 3. object streams and cross-reference streams
            $compressed = [];
            foreach ($entries as $number => $entry) {
                $header = (string) \substr($data, $positions[$number], 512);
                $isObjectStream = \preg_match('~/Type\s*/ObjStm~', $header) === 1;
                $isXref = \preg_match('~/Type\s*/XRef~', $header) === 1;
                if (!$isObjectStream && !$isXref) {
                    continue;
                }

                try {
                    $object = $this->readObjectAt($number, $entry[1]);
                } catch (FpdiException $e) {
                    continue;
                }
                if (!$object->value instanceof PdfStream) {
                    continue;
                }

                $type = PdfDictionary::get($object->value->value, 'Type');
                if ($type instanceof PdfName && $type->value === 'XRef') {
                    if (isset($object->value->value->value['Root'])) {
                        $trailers[$positions[$number]] = $object->value->value;
                    }
                    continue;
                }

                try {
                    $objectStream = $this->parseObjectStream($object->value);
                } catch (FpdiException $e) {
                    continue;
                }

                foreach ($objectStream['numbers'] as $index => $compressedNumber) {
                    // a direct definition after the object stream is newer than the compressed one
                    if (isset($positions[$compressedNumber]) && $positions[$compressedNumber] > $positions[$number]) {
                        continue;
                    }
                    if (!isset($compressed[$compressedNumber])
                        || $compressed[$compressedNumber][1] < $positions[$number]
                    ) {
                        $compressed[$compressedNumber] = [
                            [EntryReaderInterface::TYPE_COMPRESSED, $number, $index],
                            $positions[$number],
                        ];
                    }
                }
            }

            foreach ($compressed as $number => $item) {
                $entries[$number] = $item[0];
            }
            $this->readers = [new ReconstructedReader($entries, PdfDictionary::create())];

            // 4. the trailer: the last one which points to a catalog
            \krsort($trailers);
            $trailer = null;
            foreach ($trailers as $candidate) {
                if ($this->pointsToCatalog(PdfDictionary::get($candidate, 'Root'))) {
                    $trailer = $candidate;
                    break;
                }
            }

            if ($trailer === null) {
                // no usable trailer: search the catalog itself
                foreach (\array_keys($entries) as $number) {
                    if ($this->pointsToCatalog(PdfIndirectObjectReference::create($number, 0))) {
                        $catalog = $number;
                    }
                }
                if ($catalog === null) {
                    return false;
                }
                $trailer = PdfDictionary::create(['Root' => PdfIndirectObjectReference::create($catalog, 0)]);
            }
        } finally {
            $this->readers = $previousReaders;
        }

        $values = $trailer->value;
        foreach (['Type', 'W', 'Index', 'Length', 'Filter', 'DecodeParms', 'DL', 'Prev', 'XRefStm'] as $key) {
            unset($values[$key]);
        }
        $values['Size'] = PdfNumeric::create(\max(\array_keys($entries)) + 1);

        return new ReconstructedReader($entries, PdfDictionary::create($values));
    }

    /**
     * @param int $offset Absolute byte offset.
     * @return PdfDictionary|null
     */
    protected function readDictionaryAt($offset)
    {
        try {
            $this->parser->getTokenizer()->clearStack();
            $this->parser->getStreamReader()->reset($offset);
            $value = $this->parser->readValue();
        } catch (FpdiException $e) {
            return null;
        }

        return $value instanceof PdfDictionary ? $value : null;
    }

    /**
     * @param PdfType $reference
     * @return bool
     */
    protected function pointsToCatalog(PdfType $reference)
    {
        if (!$reference instanceof PdfIndirectObjectReference) {
            return false;
        }

        try {
            $object = $this->getIndirectObject($reference->value);
        } catch (FpdiException $e) {
            return false;
        }

        if (!$object->value instanceof PdfDictionary) {
            return false;
        }
        $type = PdfDictionary::get($object->value, 'Type');

        return ($type instanceof PdfName && $type->value === 'Catalog')
            || isset($object->value->value['Pages']);
    }

    /**
     * @param PdfDictionary $dictionary
     * @param string $key
     * @return int
     * @throws FpdiException
     */
    protected function getInteger(PdfDictionary $dictionary, $key)
    {
        $value = PdfType::resolve(PdfDictionary::get($dictionary, $key), $this->parser);

        return (int) PdfNumeric::ensure($value)->value;
    }
}
