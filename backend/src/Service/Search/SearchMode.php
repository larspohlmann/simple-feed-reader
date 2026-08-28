<?php

declare(strict_types=1);

namespace App\Service\Search;

/**
 * How a query's terms are matched. The three modes are mutually exclusive and
 * decided once for the whole query, not per term:
 *
 * - Substring: the default — each word matches anywhere inside a title or
 *   summary.
 * - WholeWord: a trailing space in the raw query — each word matches only on a
 *   word boundary.
 * - Phrase: the raw query wrapped in double quotes — the whole inner text
 *   matches as one exact, contiguous phrase.
 *
 * A saved search stores the mode as two booleans (its own columns); this enum
 * is the single value the domain threads instead, so no method forks on a pair
 * of flags.
 */
enum SearchMode
{
    case Substring;
    case WholeWord;
    case Phrase;

    public static function fromFlags(bool $wholeWord, bool $phrase): self
    {
        // Phrase wins: a query can carry both signals (quotes plus a trailing
        // space), and the exact phrase is the stronger intent.
        if ($phrase) {
            return self::Phrase;
        }

        return $wholeWord ? self::WholeWord : self::Substring;
    }

    public function isWholeWord(): bool
    {
        return self::WholeWord === $this;
    }

    public function isPhrase(): bool
    {
        return self::Phrase === $this;
    }
}
