<?php

declare(strict_types=1);

/**
 * @param array<string, int> $nouns
 * @param array<string, int> $others
 * @return int the bytes written
 */
function writeLexiconFiles(string $directory, array $nouns, array $others): int
{
    $lines = lexiconLines($nouns, $others);
    file_put_contents($directory . '/lexicon.tsv', $lines);

    return strlen($lines);
}

/**
 * @param array<string, int> $nouns
 * @param array<string, int> $others
 */
function lexiconLines(array $nouns, array $others): string
{
    $entries = [];
    foreach ($nouns as $form => $class) {
        $entries[(string) $form] = 'n' . "\t" . $class;
    }
    foreach ($others as $form => $class) {
        $entries[(string) $form] = 'o' . "\t" . $class;
    }

    return sortedLines($entries);
}

/** @param array<string, string> $entries */
function sortedLines(array $entries): string
{
    ksort($entries, SORT_STRING);
    $lines = '';
    foreach ($entries as $key => $value) {
        $lines .= $key . "\t" . $value . "\n";
    }

    return $lines;
}
