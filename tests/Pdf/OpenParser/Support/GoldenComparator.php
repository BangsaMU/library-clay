<?php

namespace Bangsamu\LibraryClay\Tests\Pdf\OpenParser\Support;

/**
 * Compares the canonical dump of this library with a golden file.
 */
final class GoldenComparator
{
    /**
     * @param array $actual Result of ObjectDumper::dump()
     * @param array $golden Decoded golden JSON
     * @return string[] A list of differences (empty if everything matches).
     */
    public static function compare(array $actual, array $golden)
    {
        $diffs = [];

        foreach ($golden['objects'] as $number => $expected) {
            if (!\array_key_exists($number, $actual['objects'])) {
                $diffs[] = "object $number is missing";
                continue;
            }
            self::compareValue($actual['objects'][$number], $expected, "obj $number", $diffs, $actual['objects']);
        }
        foreach (\array_diff_key($actual['objects'], $golden['objects']) as $number => $_) {
            $diffs[] = "object $number is not known by the golden file";
        }

        if ($actual['trailer'] != $golden['trailer']) {
            $diffs[] = 'trailer references differ: ' . \json_encode($actual['trailer']) . ' vs ' .
                \json_encode($golden['trailer']);
        }

        return $diffs;
    }

    /**
     * @param mixed $actual
     * @param mixed $expected
     * @param string $path
     * @param string[] $diffs
     */
    private static function compareValue($actual, $expected, $path, array &$diffs, array $objects)
    {
        // pikepdf hands out indirect scalar values (e.g. /Length 12 0 R) already resolved
        if (!\is_array($expected) && $expected !== null && \is_array($actual) && isset($actual['r'])
            && \array_key_exists((string) $actual['r'][0], $objects)
        ) {
            $actual = $objects[(string) $actual['r'][0]];
        }

        if (\is_array($expected) && \is_array($actual)) {
            if (\count($diffs) > 20) {
                return;
            }
            foreach ($expected as $key => $item) {
                if (!\array_key_exists($key, $actual)) {
                    $diffs[] = "$path: missing key $key";
                    continue;
                }
                self::compareValue($actual[$key], $item, "$path/$key", $diffs, $objects);
            }
            foreach (\array_diff_key($actual, $expected) as $key => $_) {
                $diffs[] = "$path: unexpected key $key";
            }
            return;
        }

        if ((\is_int($expected) || \is_float($expected)) && (\is_int($actual) || \is_float($actual))) {
            if (\abs($expected - $actual) > 1e-6) {
                $diffs[] = "$path: $actual != $expected";
            }
            return;
        }

        if ($actual !== $expected) {
            $diffs[] = "$path: " . \json_encode($actual) . ' != ' . \json_encode($expected);
        }
    }
}
