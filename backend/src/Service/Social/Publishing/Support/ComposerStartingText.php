<?php

declare(strict_types=1);

namespace App\Service\Social\Publishing\Support;

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

    /** The whole quote, or the title: a link post carries its link outside the text, in the card or the status. */
    public static function sharing(SharedLinkModel $link): string
    {
        if ('' !== $link->quote) {
            return self::quoting($link->quote);
        }

        return $link->title;
    }

    /** The whole quote in typographic quotes; nothing without a quote. */
    public static function quoting(string $quote): string
    {
        if ('' === $quote) {
            return '';
        }

        return self::OPENING_QUOTE . $quote . self::CLOSING_QUOTE;
    }
}
