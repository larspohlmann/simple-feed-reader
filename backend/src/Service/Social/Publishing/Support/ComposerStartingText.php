<?php

declare(strict_types=1);

namespace App\Service\Social\Publishing\Support;

use App\Service\Foundation\Text\Support\WordBoundary;
use App\Service\Social\Publishing\Model\SharedLinkModel;

/** Where the composer's text starts before the reader types. */
final class ComposerStartingText
{
    private const string OPENING_QUOTE = '“';
    private const string CLOSING_QUOTE = '”';

    private function __construct()
    {
    }

    /** @param list<string> $mentions */
    public static function mentioning(array $mentions): string
    {
        return [] === $mentions ? '' : implode(' ', $mentions) . ' ';
    }

    /** The title, or the quote, alone: a link post carries its link outside the text, in the card or the status. */
    public static function sharing(SharedLinkModel $link, int $maxCharacters): string
    {
        if ('' !== $link->quote) {
            return self::quoting($link->quote, $maxCharacters);
        }

        return $link->title;
    }

    /** The quote in typographic quotes, cut at a word so the whole fits $room characters; nothing without a quote. */
    public static function quoting(string $quote, int $room): string
    {
        if ('' === $quote) {
            return '';
        }

        $marks = mb_strlen(self::OPENING_QUOTE) + mb_strlen(self::CLOSING_QUOTE);

        return self::OPENING_QUOTE . WordBoundary::clipped($quote, $room - $marks) . self::CLOSING_QUOTE;
    }
}
