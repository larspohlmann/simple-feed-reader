<?php

declare(strict_types=1);

namespace App\Service\Reader;

final class LeadingEngagementRules
{
    public const int PROSE_CHARS = 120;

    private const float LINK_DOMINATED = 0.8;

    private const array COUNTER_NOUNS = [
        'klicks', 'aufrufe', 'reaktionen', 'kommentare', 'likes', 'shares',
        'clicks', 'views', 'reactions', 'comments',
    ];

    /** A kicker is a label, not a sentence: at most this many words, this short. */
    private const int KICKER_MAX_WORDS = 3;
    private const int KICKER_MAX_CHARS = 30;

    /** A date line is short; the locales the reader serves and the spelled-out date styles. */
    private const int DATE_LINE_MAX_CHARS = 48;
    private const array DATE_LINE_LOCALES = ['de', 'en_US', 'en_GB'];
    private const array DATE_LINE_STYLES = [
        \IntlDateFormatter::FULL,
        \IntlDateFormatter::LONG,
        \IntlDateFormatter::MEDIUM,
    ];

    /** The single whitespace-collapse every rule and both layers normalize with. */
    public static function collapse(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }

    /** Callers pass already-collapsed text (see {@see collapse()}). */
    public static function isProse(string $text, int $linkTextLength): bool
    {
        $textLength = mb_strlen($text);

        return $textLength >= self::PROSE_CHARS && $linkTextLength / $textLength < self::LINK_DOMINATED;
    }

    public static function isEmojiOnly(string $text): bool
    {
        $symbols = str_replace(["\u{FE0E}", "\u{FE0F}"], '', self::withoutWhitespace($text));

        $emojiSequence = '/^(?:\p{Extended_Pictographic}|\p{Emoji_Modifier}|\p{Regional_Indicator}'
            . '|\x{200D}|\x{20E3})+$/u';

        return $symbols !== '' && preg_match($emojiSequence, $symbols) === 1;
    }

    public static function isCounter(string $text): bool
    {
        $number = '(?:\\d{1,3}(?:[., ]\\d{3})*|\\d+)';
        $nouns = implode('|', self::COUNTER_NOUNS);

        return preg_match('/^' . $number . '\\s+(?:' . $nouns . ')$/u', mb_strtolower($text)) === 1;
    }

    public static function isByline(string $text): bool
    {
        return preg_match('/^(?:von|by)\\s+\\S.*$/ui', $text) === 1;
    }

    /** A reading-time stamp: "9 min.", "11 min read", "9 minutes". */
    public static function isReadingTime(string $text): bool
    {
        return preg_match('/^\d+\s*min(?:\.|ute[ns]?|\s+read)?$/iu', $text) === 1;
    }

    /**
     * A stand-alone publication date. ICU supplies the German and English forms,
     * so none is hand-listed; strict full-string parsing with a length cap and a
     * required digit keep a bare month, a lone year or a sentence out.
     */
    public static function isDateLine(string $text): bool
    {
        if (mb_strlen($text) > self::DATE_LINE_MAX_CHARS || preg_match('/\d/', $text) !== 1) {
            return false;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            return true;
        }

        return array_any(
            self::DATE_LINE_LOCALES,
            static fn (string $locale): bool => array_any(
                self::DATE_LINE_STYLES,
                static fn (int $style): bool => self::consumesWholeStringAsDate($text, $locale, $style),
            ),
        );
    }

    private static function consumesWholeStringAsDate(string $text, string $locale, int $style): bool
    {
        $formatter = new \IntlDateFormatter(
            $locale,
            $style,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
        );
        $formatter->setLenient(false);

        $position = 0;
        $timestamp = $formatter->parse($text, $position);

        // parse() reports the stop position in code points, so compare with
        // mb_strlen: a byte length rejects any date with a non-ASCII month (März).
        return $timestamp !== false && $position === mb_strlen($text);
    }

    /** A stray engagement count rendered as a bare number, e.g. "0". */
    public static function isBareNumber(string $text): bool
    {
        return preg_match('/^\d+$/', $text) === 1;
    }

    /** A masthead separator with no words of its own: "|", "›", "•". */
    public static function isSeparatorOnly(string $text): bool
    {
        return $text !== '' && preg_match('/^[\s|\/~•·‣›‹»«—–\-…]+$/u', $text) === 1;
    }

    /**
     * A breadcrumb or section label: short enough to be no article prose, and
     * carried almost entirely by outbound links.
     */
    public static function isNavigationLabel(string $text, int $linkTextLength): bool
    {
        $length = mb_strlen($text);

        return $length > 0 && $length < self::PROSE_CHARS && $linkTextLength / $length >= self::LINK_DOMINATED;
    }

    /** A kicker or category eyebrow above the title: a few link-less label words. */
    public static function isKicker(string $text, int $linkTextLength): bool
    {
        if ($linkTextLength > 0 || mb_strlen($text) > self::KICKER_MAX_CHARS || self::isByline($text)) {
            return false;
        }
        if (preg_match('/[.!?:]/u', $text) === 1 || preg_match('/\pL/u', $text) !== 1) {
            return false;
        }

        return count(preg_split('/\s+/u', $text) ?: []) <= self::KICKER_MAX_WORDS;
    }

    public static function hasAuthor(?string $entryAuthor): bool
    {
        return $entryAuthor !== null && trim($entryAuthor) !== '';
    }

    private static function withoutWhitespace(string $text): string
    {
        return (string) preg_replace('/\s+/u', '', $text);
    }
}
