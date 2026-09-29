<?php

declare(strict_types=1);

namespace App\Service\Reader;

final readonly class LeadingEngagementRules
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

    /** Callers pass text already collapsed by {@see \App\Service\Text\Support\Whitespace::collapse()}. */
    public function isProse(string $text, int $linkTextLength): bool
    {
        $textLength = mb_strlen($text);

        return $textLength >= self::PROSE_CHARS && $linkTextLength / $textLength < self::LINK_DOMINATED;
    }

    public function isEmojiOnly(string $text): bool
    {
        $symbols = str_replace(["\u{FE0E}", "\u{FE0F}"], '', self::withoutWhitespace($text));

        $emojiSequence = '/^(?:\p{Extended_Pictographic}|\p{Emoji_Modifier}|\p{Regional_Indicator}'
            . '|\x{200D}|\x{20E3})+$/u';

        return $symbols !== '' && preg_match($emojiSequence, $symbols) === 1;
    }

    public function isCounter(string $text): bool
    {
        $number = '(?:\\d{1,3}(?:[., ]\\d{3})*|\\d+)';
        $nouns = implode('|', self::COUNTER_NOUNS);

        return preg_match('/^' . $number . '\\s+(?:' . $nouns . ')$/u', mb_strtolower($text)) === 1;
    }

    public function isByline(string $text): bool
    {
        return preg_match('/^(?:von|by)\\s+\\S.*$/ui', $text) === 1;
    }

    /** A reading-time stamp: "9 min.", "11 min read", "9 minutes". */
    public function isReadingTime(string $text): bool
    {
        return preg_match('/^\d+\s*min(?:\.|ute[ns]?|\s+read)?$/iu', $text) === 1;
    }

    /** A stray engagement count rendered as a bare number, e.g. "0". */
    public function isBareNumber(string $text): bool
    {
        return $text !== '' && ctype_digit($text);
    }

    /** A masthead separator with no words of its own: "|", "›", "•". */
    public function isSeparatorOnly(string $text): bool
    {
        return $text !== '' && preg_match('/^[\s|\/~•·‣›‹»«—–\-…]+$/u', $text) === 1;
    }

    /**
     * A breadcrumb or section label: short enough to be no article prose, and
     * carried almost entirely by outbound links.
     */
    public function isNavigationLabel(string $text, int $linkTextLength): bool
    {
        $length = mb_strlen($text);

        return $length > 0 && $length < self::PROSE_CHARS && $linkTextLength / $length >= self::LINK_DOMINATED;
    }

    /** A kicker or category eyebrow above the title: a few link-less label words. */
    public function isKicker(string $text, int $linkTextLength): bool
    {
        if ($linkTextLength > 0 || mb_strlen($text) > self::KICKER_MAX_CHARS || $this->isByline($text)) {
            return false;
        }
        if (preg_match('/[.!?:]/u', $text) === 1 || preg_match('/\pL/u', $text) !== 1) {
            return false;
        }

        return count(preg_split('/\s+/u', $text) ?: []) <= self::KICKER_MAX_WORDS;
    }

    public function hasAuthor(?string $entryAuthor): bool
    {
        return $entryAuthor !== null && trim($entryAuthor) !== '';
    }

    private static function withoutWhitespace(string $text): string
    {
        return (string) preg_replace('/\s+/u', '', $text);
    }
}
