<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\Model\SharedLinkModel;
use App\Service\Text\Support\WordBoundary;

/** Where the composer's text starts before the reader types. */
final class ComposerStartingText
{
    /** Mastodon counts every link as this many characters, whatever its length. */
    private const int MASTODON_LINK_LENGTH = 23;
    private const string LINK_GAP = "\n\n";
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

    /** A Bluesky link post carries its link in the card, so the text starts with the title, or the quote, alone. */
    public static function sharing(SocialNetwork $network, SharedLinkModel $link, int $maxCharacters): string
    {
        if ('' !== $link->quote) {
            return self::sharingQuote($network, $link, $maxCharacters);
        }

        return match (true) {
            SocialNetwork::Bluesky === $network => $link->title,
            '' === $link->title => $link->url,
            default => $link->title . self::LINK_GAP . $link->url,
        };
    }

    /** The quote in typographic quotes, cut at a word so the whole fits $room characters; nothing without a quote. */
    public static function quoting(string $quote, int $room): string
    {
        if ('' === $quote) {
            return '';
        }

        $marks = mb_strlen(self::OPENING_QUOTE . self::CLOSING_QUOTE);

        return self::OPENING_QUOTE . WordBoundary::clipped($quote, $room - $marks) . self::CLOSING_QUOTE;
    }

    private static function sharingQuote(SocialNetwork $network, SharedLinkModel $link, int $maxCharacters): string
    {
        if (SocialNetwork::Bluesky === $network) {
            return self::quoting($link->quote, $maxCharacters);
        }

        $linkRoom = mb_strlen(self::LINK_GAP) + self::MASTODON_LINK_LENGTH;

        return self::quoting($link->quote, $maxCharacters - $linkRoom) . self::LINK_GAP . $link->url;
    }
}
