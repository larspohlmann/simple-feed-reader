<?php

declare(strict_types=1);

namespace App\Service\Text\Support;

/** A headline for a post its feed gave none: the first sentence of its first line that holds words, cut to fit. */
final class DerivedTitle
{
    private const int MAX_LENGTH = 80;
    private const string ELLIPSIS = '…';
    private const string LINK_PATTERN = '#\bhttps?://\S+#u';
    /** Punctuation, symbols and at most a short label such as Mastodon's quote-post "RE:". */
    private const string WORDLESS_PATTERN = '/^[\p{P}\p{S}\s]*(?:\p{L}{1,3}:)?[\p{P}\p{S}\s]*$/u';
    private const string FIRST_SENTENCE_PATTERN = '/^.+?[.!?…](?=\s|$)/u';

    public static function from(?string $bodyHtml): ?string
    {
        foreach (PlainText::linesFromHtmlBlocks($bodyHtml) as $line) {
            if (self::holdsWords($line)) {
                return self::cut(self::firstSentence($line));
            }
        }

        return null;
    }

    private static function holdsWords(string $line): bool
    {
        $withoutLinks = preg_replace(self::LINK_PATTERN, '', $line) ?? $line;

        return preg_match(self::WORDLESS_PATTERN, $withoutLinks) !== 1;
    }

    private static function firstSentence(string $line): string
    {
        return preg_match(self::FIRST_SENTENCE_PATTERN, $line, $sentence) === 1 ? $sentence[0] : $line;
    }

    private static function cut(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_LENGTH) {
            return $text;
        }

        $head = mb_substr($text, 0, self::MAX_LENGTH - 1);
        $lastSpace = mb_strrpos($head, ' ');
        $kept = $lastSpace === false ? $head : mb_substr($head, 0, $lastSpace);

        return rtrim($kept, ' ,;:-–—') . self::ELLIPSIS;
    }

    private function __construct()
    {
    }
}
