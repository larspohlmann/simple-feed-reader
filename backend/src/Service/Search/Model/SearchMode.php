<?php

declare(strict_types=1);

namespace App\Service\Search\Model;

/**
 * How a query's terms match, decided once for the whole query: Substring by default, WholeWord after a trailing
 * space, Phrase when the query is wrapped in double quotes. A saved search stores it as two boolean columns.
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
