<?php

namespace Bangsamu\LibraryClay\Pdf\OpenParser\CrossReference;

/**
 * A cross-reference reader which is able to describe every kind of cross-reference entry
 * (free, in use and compressed objects), not only byte offsets.
 */
interface EntryReaderInterface
{
    const TYPE_FREE = 0;
    const TYPE_IN_USE = 1;
    const TYPE_COMPRESSED = 2;

    /**
     * Get the cross-reference entry of an object.
     *
     * The result is either null (this section does not know the object, older sections have to be asked) or
     * an array of three integers:
     *
     *  - [0, nextFreeObject, generation]  free entry
     *  - [1, byteOffset, generation]      object in use, stored at a byte offset
     *  - [2, objectStreamNumber, index]   compressed object, stored inside an object stream
     *
     * @param int $objectNumber
     * @return int[]|null
     */
    public function getEntry($objectNumber);
}
