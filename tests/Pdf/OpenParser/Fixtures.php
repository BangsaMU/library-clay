<?php

namespace Bangsamu\LibraryClay\Tests\Pdf\OpenParser;

final class Fixtures
{
    /**
     * @return array[] manifest entries, keyed by file name
     */
    public static function all()
    {
        $manifest = \json_decode(\file_get_contents(__DIR__ . '/fixtures/manifest.json'), true);
        $result = [];
        foreach ($manifest as $entry) {
            $result[$entry['file']] = $entry;
        }

        return $result;
    }

    /**
     * @return array[] PHPUnit data sets: [manifest entry]
     */
    public static function importable()
    {
        $result = [];
        foreach (self::all() as $file => $entry) {
            if ($entry['importable']) {
                $result[$file] = [$entry];
            }
        }

        return $result;
    }

    public static function path($file)
    {
        return __DIR__ . '/fixtures/' . $file;
    }
}
